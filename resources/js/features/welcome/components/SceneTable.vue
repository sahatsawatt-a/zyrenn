<script setup lang="ts">
// A table whose columns each have a kind: a person, a choice, a date, a
// rating. One row's status changes while you watch, and a new row arrives.
import { Star } from '@lucide/vue';

const ROWS = [
    {
        task: 'Landing page copy',
        who: 'MA',
        tone: 5,
        status: 'Done',
        due: '12 May',
        stars: 3,
    },
    {
        task: 'Pricing table',
        who: 'KN',
        tone: 2,
        status: 'Doing',
        due: '14 May',
        stars: 2,
        turns: true,
    },
    {
        task: 'Demo video',
        who: 'SA',
        tone: 4,
        status: 'To do',
        due: '19 May',
        stars: 3,
    },
    {
        task: 'Press list',
        who: 'MA',
        tone: 5,
        status: 'To do',
        due: '21 May',
        stars: 1,
        arrives: true,
    },
];

const CHIP: Record<string, string> = {
    Done: 'bg-chart-4/15 text-chart-4',
    Doing: 'bg-chart-3/15 text-chart-3',
    'To do': 'bg-muted text-muted-foreground',
};
</script>

<template>
    <div class="h-full px-4 py-5 sm:px-7 sm:py-6">
        <div class="flex items-baseline justify-between gap-3">
            <h3 class="text-lg font-semibold tracking-tight">Launch tasks</h3>
            <span class="text-muted-foreground text-xs"
                >4 rows · Table view</span
            >
        </div>

        <div
            class="border-border mt-3 overflow-hidden rounded-lg border text-sm"
        >
            <div
                class="bg-muted/60 text-muted-foreground grid grid-cols-[1.6fr_auto_auto] gap-3 px-3 py-2 text-xs font-medium sm:grid-cols-[1.6fr_3rem_5rem_4.5rem_4.5rem]"
            >
                <span>Task</span>
                <span>Owner</span>
                <span>Status</span>
                <span class="hidden sm:block">Due</span>
                <span class="hidden sm:block">Priority</span>
            </div>
            <div
                v-for="row in ROWS"
                :key="row.task"
                class="border-border grid grid-cols-[1.6fr_auto_auto] items-center gap-3 border-t px-3 py-2 sm:grid-cols-[1.6fr_3rem_5rem_4.5rem_4.5rem]"
                :class="{ 'welcome-arrives': row.arrives }"
            >
                <span class="truncate">{{ row.task }}</span>
                <span
                    class="flex size-6 items-center justify-center rounded-full text-[10px] font-semibold text-white"
                    :style="{ background: `var(--chart-${row.tone})` }"
                    >{{ row.who }}</span
                >
                <span class="relative h-5 w-16">
                    <span
                        class="absolute inset-y-0 left-0 rounded-full px-2 text-xs leading-5 font-medium"
                        :class="[
                            CHIP[row.status],
                            { 'welcome-was': row.turns },
                        ]"
                        >{{ row.status }}</span
                    >
                    <span
                        v-if="row.turns"
                        class="welcome-now absolute inset-y-0 left-0 rounded-full px-2 text-xs leading-5 font-medium"
                        :class="CHIP.Done"
                        >Done</span
                    >
                </span>
                <span
                    class="text-muted-foreground hidden tabular-nums sm:block"
                    >{{ row.due }}</span
                >
                <span class="hidden gap-0.5 sm:flex">
                    <Star
                        v-for="n in 3"
                        :key="n"
                        class="size-3.5"
                        :class="
                            n <= row.stars
                                ? 'fill-chart-3 text-chart-3'
                                : 'text-border'
                        "
                    />
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.welcome-was {
    animation: table-out 4.5s ease both;
}

.welcome-now {
    animation: table-in 4.5s ease both;
}

.welcome-arrives {
    animation: table-row 4.5s ease both;
}

@keyframes table-out {
    0%,
    30% {
        opacity: 1;
    }

    38%,
    100% {
        opacity: 0;
    }
}

@keyframes table-in {
    0%,
    30% {
        opacity: 0;
        transform: scale(0.9);
    }

    38%,
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes table-row {
    0%,
    55% {
        opacity: 0;
        transform: translateY(-8px);
    }

    68%,
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-was,
    .welcome-now,
    .welcome-arrives {
        animation-duration: 1ms;
    }
}
</style>
