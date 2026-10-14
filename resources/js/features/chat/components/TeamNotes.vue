<script setup lang="ts">
import { Bot, StickyNote } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import MessageText from './MessageText.vue';

// A customer's chat, the part only the project sees: notes people wrote,
// what an AI desk flagged, which tickets were made -- kept apart from the
// conversation, so nothing here is ever taken for something the customer
// was told.

type Note = {
    id: number;
    role: string;
    content: string;
    created_at: string | null;
    author: { id: number | null; name: string } | null;
    sender?: { name: string } | null;
};

const props = defineProps<{ notes: Note[]; customer: string }>();

const clock = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

const list = ref<HTMLElement | null>(null);

// The newest is at the foot, and in sight as it comes
watch(
    () => props.notes.length,
    () =>
        nextTick(() => list.value?.scrollTo({ top: list.value.scrollHeight })),
    { immediate: true },
);
</script>

<template>
    <aside
        class="flex min-h-0 flex-col bg-amber-50/60 dark:bg-amber-500/5"
        data-team-notes
    >
        <header
            class="flex items-start gap-2 border-b border-amber-500/20 px-4 py-2.5"
        >
            <StickyNote
                class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-400"
            />
            <div class="min-w-0">
                <p class="text-sm font-medium">Team only</p>
                <p class="text-muted-foreground text-xs">
                    Never sent to {{ customer }}
                </p>
            </div>
        </header>
        <ol ref="list" class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
            <li
                v-for="note in notes"
                :key="note.id"
                class="bg-background rounded-lg border border-l-4 border-amber-500/30 border-l-amber-500 px-3 py-2"
                data-chat-note
            >
                <p
                    class="text-muted-foreground mb-0.5 flex items-center gap-1 text-[11px]"
                >
                    <Bot v-if="note.role === 'workflow'" class="size-3" />
                    <span class="truncate">{{
                        note.author?.name ?? note.sender?.name ?? 'Workflow'
                    }}</span>
                    <time
                        v-if="note.created_at"
                        class="ml-auto shrink-0 tabular-nums"
                        :datetime="note.created_at"
                        >{{ clock.format(new Date(note.created_at)) }}</time
                    >
                </p>
                <p class="text-sm [overflow-wrap:anywhere] whitespace-pre-wrap">
                    <MessageText :content="note.content" :emojis="[]" />
                </p>
            </li>
            <li
                v-if="!notes.length"
                class="text-muted-foreground px-2 py-8 text-center text-xs"
            >
                Notes written here are for your team: {{ customer }} never sees
                them.
            </li>
        </ol>
    </aside>
</template>
