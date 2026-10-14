<script setup lang="ts">
import { CircleAlert, Wrench } from '@lucide/vue';
import { ref } from 'vue';

// What an agent did with its tools before it answered -- each tool it used,
// with what it asked and what came back -- shown beside the answer, so
// whoever reads it can see how it got there.

export type AnswerAction = {
    tool: string;
    server: string;
    arguments: Record<string, unknown>;
    ok: boolean;
    said: string;
};

defineProps<{ actions: AnswerAction[] }>();

const open = ref<number | null>(null);
</script>

<template>
    <div v-if="actions.length" class="grid gap-1 text-xs" data-answer-actions>
        <div class="flex flex-wrap items-center gap-1">
            <span class="text-muted-foreground">Used</span>
            <button
                v-for="(action, at) in actions"
                :key="at"
                type="button"
                class="hover:bg-accent flex h-6 max-w-56 items-center gap-1 rounded-full border px-2"
                :class="{
                    'bg-accent': open === at,
                    'border-destructive/40 text-destructive': !action.ok,
                }"
                :aria-expanded="open === at"
                :title="`${action.tool}, on ${action.server}`"
                data-answer-action
                @click="open = open === at ? null : at"
            >
                <CircleAlert v-if="!action.ok" class="size-3 shrink-0" />
                <Wrench v-else class="text-muted-foreground size-3 shrink-0" />
                <span class="truncate">{{ action.tool }}</span>
            </button>
        </div>
        <div
            v-if="open !== null && actions[open]"
            class="bg-muted/50 grid gap-1 rounded-md border-l-2 px-2.5 py-1.5"
            data-answer-action-detail
        >
            <p class="text-muted-foreground">
                {{ actions[open].server }} · asked
                <code class="text-[11px] [overflow-wrap:anywhere]">{{
                    JSON.stringify(actions[open].arguments)
                }}</code>
            </p>
            <p
                class="max-h-48 overflow-y-auto font-mono text-[11px] [overflow-wrap:anywhere] whitespace-pre-wrap"
            >
                {{ actions[open].said }}
            </p>
        </div>
    </div>
</template>
