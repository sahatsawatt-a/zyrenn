<script setup lang="ts">
// An AI assistant working in the app over MCP: what was asked, the tools
// it called -- by their real names -- and what came of it. The lines come
// one after another once the card is on the screen.
import { useIntersectionObserver } from '@vueuse/core';
import { Check, CornerDownRight } from '@lucide/vue';
import { ref } from 'vue';

const CALLS = [
    ['search-places', '“Fushimi Inari”, “Nishiki Market”'],
    ['create-trip', 'Kyoto, 3 days'],
    ['create-table', 'Kyoto costs · 6 rows'],
    ['create-note', 'Packing list'],
] as const;

const card = ref<HTMLElement | null>(null);
const seen = ref(false);

const { stop } = useIntersectionObserver(
    card,
    ([entry]) => {
        if (entry?.isIntersecting) {
            seen.value = true;
            stop();
        }
    },
    { threshold: 0.4 },
);
</script>

<template>
    <div
        ref="card"
        class="bg-card border-border/80 overflow-hidden rounded-2xl border font-mono text-[13px] shadow-xl shadow-black/5"
        :class="{ 'welcome-seen': seen }"
    >
        <div
            class="border-border/70 bg-muted/40 text-muted-foreground flex items-center gap-2 border-b px-4 py-2.5 font-sans text-xs"
        >
            <span class="bg-chart-4 size-2 rounded-full" aria-hidden="true" />
            Your assistant · connected over MCP
        </div>
        <div class="space-y-2.5 p-4 sm:p-5">
            <p class="welcome-line">
                <span class="text-muted-foreground">You</span>
                <span class="text-foreground ml-2 font-sans text-sm"
                    >Plan three days in Kyoto and keep the costs in a
                    table.</span
                >
            </p>
            <p
                v-for="([tool, what], at) in CALLS"
                :key="tool"
                class="welcome-line flex items-start gap-2"
                :style="{ animationDelay: `${0.7 + at * 0.55}s` }"
            >
                <CornerDownRight
                    class="text-muted-foreground mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span class="min-w-0">
                    <span class="text-primary">{{ tool }}</span>
                    <span class="text-muted-foreground ml-2 font-sans">{{
                        what
                    }}</span>
                </span>
            </p>
            <p
                class="welcome-line text-chart-4 flex items-center gap-2 font-sans text-sm font-medium"
                :style="{
                    animationDelay: `${0.7 + CALLS.length * 0.55 + 0.2}s`,
                }"
            >
                <Check class="size-4" stroke-width="3" aria-hidden="true" />
                Done: the trip, the table and the note are in your workspace.
            </p>
        </div>
    </div>
</template>

<style scoped>
.welcome-line {
    opacity: 0;
}

.welcome-seen .welcome-line {
    animation: terminal-line 0.4s ease-out both;
}

@keyframes terminal-line {
    from {
        opacity: 0;
        transform: translateY(4px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-line {
        opacity: 1;
    }

    .welcome-seen .welcome-line {
        animation: none;
    }
}
</style>
