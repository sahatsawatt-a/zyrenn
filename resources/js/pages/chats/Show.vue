<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { JSONContent } from '@tiptap/vue-3';
import { Send, Settings2, Square, Trash2 } from '@lucide/vue';
import { nextTick, onMounted, ref, watch } from 'vue';
import ModelPicker from '@/components/chat/ModelPicker.vue';
import TiptapEditor from '@/components/Editor/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { xsrfToken } from '@/lib/utils';
import { index as connectionsIndex, models } from '@/routes/ai-connections';
import { destroy, index, show, update } from '@/routes/chats';
import { store as sendMessage } from '@/routes/chats/messages';

type Message = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    // An agent's words as the note editor's document, so they are drawn as a note is
    doc: JSONContent | null;
    // Why an answer stopped short, when it did
    error: string | null;
};

type Room = {
    ref_id: string;
    title: string;
    model: string | null;
    system_prompt: string | null;
    connection: string | null;
    ready: boolean;
};

type Connection = {
    ref_id: string;
    name: string;
    kind: string;
    label: string;
    default_model: string | null;
};

const props = defineProps<{
    room: Room;
    messages: Message[];
    connections: Connection[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Chat', href: index() }] } });

const list = ref<Message[]>([...props.messages]);
const title = ref(props.room.title);
const draft = ref('');
// What the agent has said so far in the answer being written
const streaming = ref<string | null>(null);
const failure = ref<string | null>(null);
const sending = ref(false);
let abort: AbortController | null = null;

const scroller = ref<HTMLElement | null>(null);
const input = ref<HTMLTextAreaElement | null>(null);

const toBottom = () =>
    nextTick(() => {
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }
    });

onMounted(toBottom);
watch(() => [list.value.length, streaming.value], toBottom);

// The room's own title is saved as it is left
function saveTitle() {
    if (title.value !== props.room.title) {
        router.patch(
            update(props.room.ref_id).url,
            { title: title.value },
            { preserveScroll: true, only: ['room'] },
        );
    }
}

async function send() {
    const content = draft.value.trim();

    if (!content || sending.value) {
        return;
    }

    draft.value = '';
    failure.value = null;
    sending.value = true;
    abort = new AbortController();

    try {
        const response = await fetch(sendMessage(props.room.ref_id).url, {
            method: 'POST',
            signal: abort.signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ content }),
        });

        if (!response.ok || !response.body) {
            draft.value = content;
            failure.value =
                response.status === 422
                    ? 'That message cannot be sent: it is empty or too long.'
                    : 'Could not send that.';

            return;
        }

        await read(response.body);
    } catch (error) {
        // Stopping is not a failure; the page just keeps what it had
        if ((error as Error).name !== 'AbortError') {
            failure.value = 'The connection was lost.';
        }
    } finally {
        // The server keeps whatever was written, so the page asks for it rather than guess
        if (streaming.value !== null || failure.value) {
            router.reload({
                only: ['messages'],
                onSuccess: () => {
                    list.value = [...props.messages];
                },
            });
        }

        streaming.value = null;
        sending.value = false;
        abort = null;
        nextTick(() => input.value?.focus());
    }
}

// Server-sent events: "event: name", "data: json", and a blank line after each
async function read(body: ReadableStream<Uint8Array>) {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            return;
        }

        buffer += decoder.decode(value, { stream: true });

        let end: number;

        while ((end = buffer.indexOf('\n\n')) !== -1) {
            const block = buffer.slice(0, end);
            buffer = buffer.slice(end + 2);

            const name = /^event: (\w+)/m.exec(block)?.[1];
            const data = /^data: (.*)$/m.exec(block)?.[1];

            if (name && data) {
                handle(name, JSON.parse(data));
            }
        }
    }
}

function handle(
    name: string,
    data: { message?: Message | null; text?: string; title?: string },
) {
    if (name === 'user' && data.message) {
        list.value.push(data.message);
        title.value = data.title ?? title.value;
        streaming.value = props.room.ready ? '' : null;
    } else if (name === 'delta') {
        streaming.value = (streaming.value ?? '') + (data.text ?? '');
    } else if (name === 'done') {
        if (data.message) {
            list.value.push(data.message);
        }

        streaming.value = null;
    } else if (name === 'error') {
        failure.value = (data as { message?: string }).message as string;
    }
}

const stop = () => abort?.abort();

function onKey(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void send();
    }
}

function remove() {
    if (confirm('Delete this chat and everything said in it?')) {
        router.delete(destroy(props.room.ref_id).url);
    }
}

// --- the room's agent: a connection, a model, and instructions ---
const settingsOpen = ref(false);
const connection = ref(props.room.connection ?? '');
const model = ref(props.room.model ?? '');
const prompt = ref(props.room.system_prompt ?? '');
const offered = ref<string[]>([]);
const offeredFree = ref<string[] | null>(null);
const offeredFailure = ref<string | null>(null);
const loadingModels = ref(false);

async function loadModels() {
    offered.value = [];
    offeredFree.value = null;
    offeredFailure.value = null;

    if (!connection.value) {
        return;
    }

    loadingModels.value = true;

    try {
        const response = await fetch(models(connection.value).url, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        if (response.ok) {
            offered.value = body.models;
            offeredFree.value = body.free;
        } else {
            offeredFailure.value = body.message;
        }
    } catch {
        offeredFailure.value = 'Could not ask for its models.';
    } finally {
        loadingModels.value = false;
    }
}

watch(settingsOpen, (open) => open && void loadModels());

const usual = () =>
    props.connections.find((c) => c.ref_id === connection.value)
        ?.default_model ?? '';

function saveSettings() {
    router.patch(
        update(props.room.ref_id).url,
        {
            connection: connection.value || null,
            model: model.value || null,
            system_prompt: prompt.value || null,
        },
        {
            preserveScroll: true,
            only: ['room', 'connections'],
            onSuccess: () => (settingsOpen.value = false),
        },
    );
}
</script>

<template>
    <Head :title="title || 'New chat'" />

    <div class="mx-auto flex h-[calc(100svh-4rem)] w-full max-w-3xl flex-col">
        <header class="flex items-center gap-2 px-4 py-3">
            <Input
                v-model="title"
                class="h-9 flex-1 border-transparent bg-transparent text-base font-medium shadow-none"
                placeholder="New chat"
                maxlength="255"
                aria-label="Chat title"
                @blur="saveTitle"
                @keydown.enter.prevent="
                    ($event.target as HTMLInputElement).blur()
                "
            />
            <Button variant="ghost" size="sm" @click="settingsOpen = true">
                <Settings2 />
                <span class="hidden sm:inline">
                    {{ room.ready ? 'Agent' : 'Set up agent' }}
                </span>
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="Delete chat"
                @click="remove"
            >
                <Trash2 />
            </Button>
        </header>

        <div
            ref="scroller"
            class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 pb-4"
            data-chat-messages
        >
            <p
                v-if="!room.ready"
                class="bg-muted/40 rounded-lg border px-4 py-3 text-sm"
                data-chat-no-agent
            >
                No agent answers here, so this is only a log of what you write.
                <button
                    class="text-primary underline underline-offset-4"
                    @click="settingsOpen = true"
                >
                    Choose a connection and model
                </button>
                to have it answer.
            </p>

            <p
                v-else-if="!list.length"
                class="text-muted-foreground py-16 text-center text-sm"
            >
                Say something to begin.
            </p>

            <template v-for="message in list" :key="message.id">
                <div
                    v-if="message.role === 'user'"
                    class="flex justify-end"
                    data-chat-user
                >
                    <p
                        class="bg-primary text-primary-foreground max-w-[85%] rounded-2xl rounded-br-md px-4 py-2 text-sm whitespace-pre-wrap"
                    >
                        {{ message.content }}
                    </p>
                </div>
                <div v-else class="max-w-full" data-chat-assistant>
                    <!-- The same editor and Markdown as a note, only for reading -->
                    <TiptapEditor
                        :content="message.doc"
                        :editable="false"
                        wide
                        class="chat-answer min-h-0! pt-0!"
                    />
                    <p
                        v-if="message.error"
                        class="text-destructive mt-1 text-xs"
                    >
                        Stopped early: {{ message.error }}
                    </p>
                </div>
            </template>

            <!-- Still being written: plain until it is whole, then drawn as a note -->
            <div v-if="streaming !== null" data-chat-streaming>
                <p v-if="streaming" class="text-sm whitespace-pre-wrap">
                    {{ streaming }}
                </p>
                <p v-else class="text-muted-foreground text-sm">Thinking…</p>
            </div>

            <p
                v-if="failure"
                class="text-destructive text-sm"
                role="alert"
                data-chat-error
            >
                {{ failure }}
            </p>
        </div>

        <form class="px-4 pt-2 pb-4" @submit.prevent="send">
            <div
                class="focus-within:ring-ring/40 flex items-end gap-2 rounded-2xl border p-2 focus-within:ring-[3px]"
            >
                <textarea
                    ref="input"
                    v-model="draft"
                    rows="1"
                    class="field-sizing-content max-h-48 min-h-9 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none"
                    placeholder="Write a message…  (Enter sends, Shift+Enter for a new line)"
                    data-chat-input
                    @keydown="onKey"
                />
                <Button
                    v-if="sending"
                    type="button"
                    size="icon-sm"
                    aria-label="Stop"
                    @click="stop"
                >
                    <Square />
                </Button>
                <Button
                    v-else
                    type="submit"
                    size="icon-sm"
                    aria-label="Send"
                    :disabled="!draft.trim()"
                >
                    <Send />
                </Button>
            </div>
        </form>

        <Dialog v-model:open="settingsOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Agent</DialogTitle>
                    <DialogDescription>
                        Who answers in this chat, and how. Leave the connection
                        empty for a chat that only keeps what you write.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="saveSettings">
                    <div class="grid gap-2">
                        <Label for="chat-connection">Connection</Label>
                        <select
                            id="chat-connection"
                            v-model="connection"
                            class="border-input dark:bg-input/30 h-9 w-full rounded-md border bg-transparent px-3 text-sm"
                            @change="loadModels"
                        >
                            <option value="">
                                None: only keep what I write
                            </option>
                            <option
                                v-for="c in connections"
                                :key="c.ref_id"
                                :value="c.ref_id"
                            >
                                {{ c.name }} ({{ c.label }})
                            </option>
                        </select>
                        <p
                            v-if="!connections.length"
                            class="text-muted-foreground text-xs"
                        >
                            You have none yet.
                            <Link
                                :href="connectionsIndex()"
                                class="text-primary underline underline-offset-4"
                            >
                                Add one in settings.
                            </Link>
                        </p>
                    </div>

                    <div v-if="connection" class="grid gap-2">
                        <Label>Model</Label>
                        <ModelPicker
                            v-model="model"
                            :models="offered"
                            :free="offeredFree"
                            :loading="loadingModels"
                            :error="offeredFailure"
                            :usual="usual()"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="chat-prompt">Instructions</Label>
                        <textarea
                            id="chat-prompt"
                            v-model="prompt"
                            rows="4"
                            maxlength="10000"
                            class="border-input dark:bg-input/30 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            placeholder="e.g. Answer briefly, in the language I write in."
                        />
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="settingsOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
