<script setup lang="ts">
import {
    Check,
    MessageSquareText,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { xsrfToken } from '@/lib/utils';
import { destroy, index, store, update } from '@/routes/projects/replies';
import Tip from '@/components/common/Tip.vue';

// Answers a project gives its customers often, put in what is being written
// with a click: {name} becomes who the chat is with, {me} whoever writes.
// Owners and editors keep the list; anyone answering may use it.

type Reply = { ref_id: string; title: string; body: string };

const props = defineProps<{
    project: string;
    // Who the chat is with, and who is writing: what {name} and {me} become
    name: string;
    me: string;
    // What is written so far, to keep as a new one
    draft: string;
    canManage: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{ pick: [text: string]; closed: [] }>();

const open = ref(false);
const replies = ref<Reply[]>([]);
const loaded = ref(false);
const failed = ref('');
const query = ref('');

// One being written or changed: a new one has no ref_id
const editing = ref<{ ref_id?: string; title: string; body: string } | null>(
    null,
);
const removing = ref<Reply | null>(null);

async function ask(
    url: string,
    method = 'GET',
    body?: Record<string, string>,
): Promise<boolean> {
    failed.value = '';

    try {
        const init: RequestInit = {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        };

        if (body) {
            init.body = JSON.stringify(body);
        }

        const response = await fetch(url, init);
        const answer = await response.json();

        if (!response.ok) {
            failed.value =
                answer?.errors?.title?.[0] ??
                answer?.errors?.body?.[0] ??
                'That could not be done.';

            return false;
        }

        replies.value = answer.replies;
        loaded.value = true;

        return true;
    } catch {
        failed.value = 'The saved replies could not be reached.';

        return false;
    }
}

// Fresh each time it opens: another person may have added some
watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        editing.value = null;
        void ask(index(props.project).url);
    } else {
        emit('closed');
    }
});

const shown = computed(() => {
    const words = query.value.trim().toLowerCase();

    return words
        ? replies.value.filter((reply) =>
              `${reply.title} ${reply.body}`.toLowerCase().includes(words),
          )
        : replies.value;
});

const filled = (body: string) =>
    body.replaceAll('{name}', props.name).replaceAll('{me}', props.me);

function pick(reply: Reply) {
    emit('pick', filled(reply.body));
    open.value = false;
}

function startNew() {
    editing.value = { title: '', body: props.draft.trim() };
}

async function save() {
    const draft = editing.value;

    if (!draft || !draft.title.trim() || !draft.body.trim()) {
        return;
    }

    const kept = draft.ref_id
        ? await ask(
              update({ project: props.project, reply: draft.ref_id }).url,
              'PATCH',
              { title: draft.title, body: draft.body },
          )
        : await ask(store(props.project).url, 'POST', {
              title: draft.title,
              body: draft.body,
          });

    if (kept) {
        editing.value = null;
    }
}

async function remove() {
    const reply = removing.value;

    if (reply) {
        await ask(
            destroy({ project: props.project, reply: reply.ref_id }).url,
            'DELETE',
        );
        removing.value = null;
    }
}
</script>

<template>
    <Tip text="Saved replies">
        <span class="inline-flex">
            <Popover v-model:open="open">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Saved replies"
                        :disabled="disabled"
                        data-test="replies-open"
                    >
                        <MessageSquareText />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    side="top"
                    class="w-[min(22rem,calc(100vw-2rem))] p-0"
                    data-test="replies"
                >
                    <!-- One being kept: what it is called, and its words -->
                    <form
                        v-if="editing"
                        class="grid gap-2 p-3"
                        @submit.prevent="save"
                    >
                        <Input
                            v-model="editing.title"
                            maxlength="80"
                            placeholder="What it is called: Opening hours…"
                            aria-label="Its name"
                            data-test="reply-title"
                        />
                        <textarea
                            v-model="editing.body"
                            rows="4"
                            maxlength="4000"
                            class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                            placeholder="Hi {name}, we are open from 9 to 6…"
                            aria-label="Its words"
                            data-test="reply-body"
                        />
                        <p class="text-muted-foreground text-[11px]">
                            {name} becomes who the chat is with, {me} whoever
                            sends it.
                        </p>
                        <p v-if="failed" class="text-destructive text-xs">
                            {{ failed }}
                        </p>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="editing = null"
                            >
                                <X /> Cancel
                            </Button>
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="
                                    !editing.title.trim() ||
                                    !editing.body.trim()
                                "
                                data-test="reply-save"
                            >
                                <Check /> Save
                            </Button>
                        </div>
                    </form>

                    <template v-else>
                        <div class="relative border-b p-2">
                            <Search
                                class="text-muted-foreground pointer-events-none absolute top-1/2 left-4.5 size-4 -translate-y-1/2"
                            />
                            <Input
                                v-model="query"
                                class="h-8 pl-8 text-sm"
                                placeholder="Find a reply"
                                aria-label="Find a reply"
                            />
                        </div>
                        <ul class="max-h-72 overflow-y-auto p-1">
                            <li
                                v-for="reply in shown"
                                :key="reply.ref_id"
                                class="group flex items-start gap-1 rounded-md"
                            >
                                <button
                                    type="button"
                                    class="hover:bg-muted min-w-0 flex-1 rounded-md px-2 py-1.5 text-left"
                                    data-test="reply"
                                    @click="pick(reply)"
                                >
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ reply.title }}</span
                                    >
                                    <span
                                        class="text-muted-foreground line-clamp-2 text-xs"
                                        >{{ filled(reply.body) }}</span
                                    >
                                </button>
                                <template v-if="canManage">
                                    <Tip :text="`Change ${reply.title}`">
                                        <button
                                            type="button"
                                            class="text-muted-foreground hover:text-foreground mt-1 rounded-md p-1.5 opacity-60 group-hover:opacity-100"
                                            :aria-label="`Change ${reply.title}`"
                                            @click="editing = { ...reply }"
                                        >
                                            <Pencil class="size-3.5" />
                                        </button>
                                    </Tip>
                                    <Tip :text="`Delete ${reply.title}`">
                                        <button
                                            type="button"
                                            class="text-muted-foreground hover:text-destructive mt-1 rounded-md p-1.5 opacity-60 group-hover:opacity-100"
                                            :aria-label="`Delete ${reply.title}`"
                                            @click="removing = reply"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </button>
                                    </Tip>
                                </template>
                            </li>
                            <li
                                v-if="loaded && !shown.length"
                                class="text-muted-foreground px-2 py-6 text-center text-xs"
                            >
                                {{
                                    query
                                        ? 'No reply matches.'
                                        : 'No saved replies yet: answers the project gives often.'
                                }}
                            </li>
                        </ul>
                        <p
                            v-if="failed"
                            class="text-destructive px-3 pb-2 text-xs"
                        >
                            {{ failed }}
                        </p>
                        <div v-if="canManage" class="border-t p-1">
                            <button
                                type="button"
                                class="text-primary hover:bg-muted flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-sm"
                                data-test="reply-new"
                                @click="startNew"
                            >
                                <Plus class="size-3.5" />
                                {{
                                    draft.trim()
                                        ? 'Keep what is written as a reply'
                                        : 'New saved reply'
                                }}
                            </button>
                        </div>
                    </template>
                </PopoverContent>
            </Popover>
        </span>
    </Tip>

    <DeleteDialog
        :open="removing !== null"
        :name="removing?.title ?? ''"
        description="It goes for everyone in the project. Chats it was sent in keep what was said."
        @update:open="$event || (removing = null)"
        @confirm="remove"
    />
</template>
