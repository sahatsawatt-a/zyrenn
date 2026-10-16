<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowDown, Send, Square, StickyNote } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import type { DriveFile } from '@/lib/drive';
import type { Room } from '../types';
import AnswerActions from './AnswerActions.vue';
import AnswerSources from './AnswerSources.vue';
import AnswerWindow from './AnswerWindow.vue';
import AttachMenu from './AttachMenu.vue';
import ComposerAttached from './ComposerAttached.vue';
import EmojiPicker from './EmojiPicker.vue';
import type { ChatPlace } from './MessagePlace.vue';
import SavedReplies from './SavedReplies.vue';
import SuggestReply from './SuggestReply.vue';
import type { Suggested } from './SuggestReply.vue';

// Writing the next message: its words, with emoji, a saved reply or one an
// agent suggests, and a file or a place to go with them -- in a customer's
// chat, an answer to them or a note for the team

const draft = defineModel<string>('draft', { required: true });
const attached = defineModel<File | DriveFile | null>('attached', {
    required: true,
});
const placed = defineModel<ChatPlace | null>('placed', { required: true });
const noting = defineModel<boolean>('noting', { required: true });

const props = defineProps<{
    room: Room;
    sending: boolean;
    // A reply an agent suggested, with what it answered from
    suggestion: Suggested | null;
    // Whether the latest message is in view, and whether more came since
    atLatest: boolean;
    fresh: boolean;
}>();

const emit = defineEmits<{
    send: [];
    stop: [];
    // Down to the latest message
    latest: [];
    suggested: [text: string, about: Suggested];
    failed: [message: string];
}>();

const page = usePage();

const WRITES = [
    {
        value: 'reply' as const,
        label: 'Reply to them',
        test: 'chat-as-reply',
    },
    {
        value: 'note' as const,
        label: 'Note for the team',
        title: 'For your project only: it is not sent to them',
        icon: StickyNote,
        test: 'chat-as-note',
    },
];

const input = ref<HTMLTextAreaElement | null>(null);
const focus = () => input.value?.focus();

defineExpose({ focus });

// A file to go with the next message: kept in the Drive of whoever's chat
// this is. Two people's own chat has no Drive both can open.
const attachable = props.room.kind !== 'direct';

function attachFile(file: File | DriveFile) {
    attached.value = file;
    placed.value = null;
    focus();
}

function attachPlace(place: ChatPlace) {
    placed.value = place;
    attached.value = null;
    focus();
}

// Pasted from the clipboard or dropped on the box: the first file is attached
function take(files: FileList | undefined | null, event: Event) {
    if (attachable && files?.length) {
        event.preventDefault();
        attachFile(files[0]);
    }
}

const dropping = ref(false);

// An emoji goes where the cursor was when the message was left for the
// picker, and the next after it; the picker closed, writing carries on there
const caret = ref<number | null>(null);

function addEmoji(emoji: string) {
    const at = Math.min(caret.value ?? draft.value.length, draft.value.length);

    draft.value = draft.value.slice(0, at) + emoji + draft.value.slice(at);
    caret.value = at + emoji.length;
}

function backToWriting() {
    const at = caret.value;

    nextTick(() => {
        input.value?.focus();

        if (at !== null) {
            input.value?.setSelectionRange(at, at);
        }
    });
}

function onKey(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        emit('send');
    }
}
</script>

<template>
    <form class="relative px-4 pt-2 pb-3" @submit.prevent="emit('send')">
        <!-- Scrolled back: a way down to the latest, saying when there is more -->
        <button
            v-if="!atLatest"
            type="button"
            class="bg-background hover:bg-accent absolute -top-10 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs shadow-md"
            data-chat-latest
            @click="emit('latest')"
        >
            <ArrowDown class="size-3.5" />
            {{ fresh ? 'New messages' : 'Latest' }}
        </button>
        <ComposerAttached
            :placed="placed"
            :attached="attached"
            @unplace="placed = null"
            @detach="attached = null"
        />
        <AnswerWindow
            v-if="room.desk?.answer_until && !noting"
            :until="room.desk.answer_until"
            :service="room.link?.service"
        />
        <div
            v-if="suggestion && draft.trim()"
            class="bg-muted/40 mb-2 grid gap-1 rounded-lg border px-3 py-2"
            data-suggestion
        >
            <p class="text-muted-foreground text-[11px]">
                Suggested by {{ suggestion.agent }}, version
                {{ suggestion.version }}: read it, and its sources, before you
                send it.
            </p>
            <AnswerSources
                :sources="suggestion.sources"
                :knows="suggestion.knows"
                :looked="suggestion.actions.length > 0"
            />
            <AnswerActions :actions="suggestion.actions" />
        </div>
        <SegmentTabs
            v-if="room.desk"
            :model-value="noting ? 'note' : 'reply'"
            :options="WRITES"
            label="What is written"
            class="mb-1.5"
            @update:model-value="noting = $event === 'note'"
        />
        <div
            class="focus-within:ring-ring/40 flex items-end gap-1.5 rounded-2xl border p-1.5 shadow-xs transition-shadow focus-within:ring-[3px]"
            :class="[
                noting
                    ? 'border-amber-500/50 bg-amber-50 dark:bg-amber-500/10'
                    : 'bg-background',
                { 'ring-primary/40 ring-[3px]': dropping },
            ]"
            @dragover.prevent="dropping = attachable"
            @dragleave="dropping = false"
            @drop="
                dropping = false;
                take($event.dataTransfer?.files, $event);
            "
        >
            <AttachMenu
                v-if="attachable"
                :room="room.ref_id"
                :disabled="sending"
                @file="attachFile"
                @place="attachPlace"
            />
            <EmojiPicker
                :disabled="sending"
                @pick="addEmoji"
                @closed="backToWriting"
            />
            <SuggestReply
                v-if="room.desk && !noting"
                :room="room.ref_id"
                :agents="room.desk.agents ?? []"
                :disabled="sending"
                @pick="(text, about) => emit('suggested', text, about)"
                @failed="emit('failed', $event)"
            />
            <SavedReplies
                v-if="room.desk && room.project"
                :project="room.project.ref_id"
                :name="room.contact?.name ?? room.title"
                :me="page.props.auth.user.name"
                :draft="draft"
                :can-manage="room.manage"
                :disabled="sending"
                @pick="addEmoji"
                @closed="backToWriting"
            />
            <textarea
                ref="input"
                v-model="draft"
                rows="1"
                class="field-sizing-content max-h-48 min-h-9 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none"
                :placeholder="
                    noting
                        ? 'A note for your project: not sent to them…'
                        : 'Write a message…'
                "
                data-chat-input
                @blur="caret = input?.selectionStart ?? null"
                @keydown="onKey"
                @paste="take($event.clipboardData?.files, $event)"
            />
            <Button
                v-if="sending"
                type="button"
                size="icon-sm"
                aria-label="Stop"
                @click="emit('stop')"
            >
                <Square />
            </Button>
            <Button
                v-else
                type="submit"
                size="icon-sm"
                aria-label="Send"
                :disabled="!draft.trim() && !attached && !placed"
            >
                <Send />
            </Button>
        </div>
        <p
            class="text-muted-foreground mt-1.5 hidden px-2 text-[11px] sm:block"
        >
            Enter sends · Shift+Enter starts a new line<template
                v-if="attachable"
            >
                · a file can be pasted or dropped here</template
            >
        </p>
    </form>
</template>
