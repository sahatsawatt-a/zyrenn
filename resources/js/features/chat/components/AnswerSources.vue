<script setup lang="ts">
import {
    ExternalLink,
    FileText,
    Paperclip,
    Table2,
    TriangleAlert,
} from '@lucide/vue';
import { ref } from 'vue';
import Tip from '@/components/common/Tip.vue';

// What an agent was given to answer from -- the passages of its knowledge
// base nearest the question -- shown beside its answer for whoever reads it
// to check it by. None found is said plainly: the answer is then the
// model's own.

export type AnswerSource = {
    title: string;
    url: string | null;
    kind: string;
    score: number;
    text: string;
};

defineProps<{
    sources: AnswerSource[];
    // Whether the agent has a knowledge base at all
    knows?: boolean;
    // Whether it looked things up with its tools: then an answer with no passages is not its own guess
    looked?: boolean;
}>();

const open = ref<number | null>(null);

const iconOf = (kind: string) =>
    kind === 'table' ? Table2 : kind === 'file' ? Paperclip : FileText;
</script>

<template>
    <div
        v-if="sources.length || !looked"
        class="grid gap-1 text-xs"
        data-answer-sources
    >
        <p
            v-if="!sources.length"
            class="flex items-start gap-1.5 text-amber-700 dark:text-amber-400"
            data-answer-unsourced
        >
            <TriangleAlert class="mt-px size-3.5 shrink-0" />
            {{
                knows === false
                    ? "This agent has no knowledge base: the answer is the model's own. Check it before it goes."
                    : "Nothing in the knowledge base matched: the answer is the model's own. Check it before it goes."
            }}
        </p>
        <template v-else>
            <div class="flex flex-wrap items-center gap-1">
                <span class="text-muted-foreground">Sources</span>
                <Tip
                    v-for="(source, at) in sources"
                    :key="at"
                    :text="`${Math.round(source.score * 100)}% near the question`"
                >
                    <button
                        type="button"
                        class="hover:bg-accent flex h-6 max-w-56 items-center gap-1 rounded-full border px-2"
                        :class="{ 'bg-accent': open === at }"
                        :aria-expanded="open === at"
                        data-answer-source
                        @click="open = open === at ? null : at"
                    >
                        <component
                            :is="iconOf(source.kind)"
                            class="text-muted-foreground size-3 shrink-0"
                        />
                        <span class="truncate">{{ source.title }}</span>
                        <span class="text-muted-foreground tabular-nums"
                            >{{ Math.round(source.score * 100) }}%</span
                        >
                    </button>
                </Tip>
            </div>
            <!-- What it says, opened to read against the answer -->
            <blockquote
                v-if="open !== null && sources[open]"
                class="bg-muted/50 rounded-md border-l-2 px-2.5 py-1.5 whitespace-pre-line"
                data-answer-passage
            >
                {{ sources[open].text }}
                <a
                    v-if="sources[open].url"
                    :href="sources[open].url!"
                    target="_blank"
                    rel="noopener"
                    class="text-primary mt-1 flex w-fit items-center gap-1 underline-offset-2 hover:underline"
                >
                    Open {{ sources[open].title }}
                    <ExternalLink class="size-3" />
                </a>
            </blockquote>
        </template>
    </div>
</template>
