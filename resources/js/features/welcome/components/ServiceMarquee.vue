<script setup lang="ts">
// What a workflow can start on and what it can do, drifting past as two
// rows of names: the real steps, by the names the editor gives them.
const STARTS = [
    ['Telegram message', 2],
    ['LINE message', 4],
    ['WhatsApp message', 4],
    ['Instagram message', 5],
    ['Instagram comment', 5],
    ['Threads reply', 1],
    ['Webhook', 3],
    ['On a schedule', 1],
    ['Chat message', 2],
] as const;

const STEPS = [
    ['Ask AI', 1],
    ['Send on Telegram', 2],
    ['Reply on LINE', 4],
    ['Post on Instagram', 5],
    ['Post on Threads', 1],
    ['Post on a Facebook Page', 2],
    ['Post on LinkedIn', 2],
    ['Add a row', 3],
    ['Save to Drive', 3],
    ['Search Google', 4],
    ['HTTP request', 1],
    ['Run code', 5],
] as const;
</script>

<template>
    <div class="welcome-fade space-y-3 overflow-hidden" aria-hidden="true">
        <div
            v-for="(row, at) in [STARTS, STEPS]"
            :key="at"
            class="welcome-row flex w-max gap-3"
            :class="{ 'welcome-row-back': at === 1 }"
        >
            <!-- twice over, so the row that leaves one side is already coming in at the other -->
            <template v-for="copy in 2" :key="copy">
                <span
                    v-for="[name, tone] in row"
                    :key="`${copy}-${name}`"
                    class="bg-card border-border/70 flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-2 text-sm"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ background: `var(--chart-${tone})` }"
                    />
                    {{ name }}
                </span>
            </template>
        </div>
    </div>
</template>

<style scoped>
.welcome-fade {
    mask-image: linear-gradient(
        to right,
        transparent,
        black 12%,
        black 88%,
        transparent
    );
}

.welcome-row {
    animation: marquee 48s linear infinite;
}

.welcome-row-back {
    animation-direction: reverse;
    animation-duration: 60s;
}

.welcome-fade:hover .welcome-row {
    animation-play-state: paused;
}

/* Half its width: one copy's worth, and the gap after it */
@keyframes marquee {
    to {
        transform: translateX(calc(-50% - 0.375rem));
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-row {
        animation: none;
    }
}
</style>
