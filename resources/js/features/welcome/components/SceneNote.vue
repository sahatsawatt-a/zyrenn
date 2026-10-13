<script setup lang="ts">
// A note being written by two people: a task gets ticked while you watch.
import { Check, Lightbulb } from '@lucide/vue';
import LiveCursor from './LiveCursor.vue';

const TASKS = [
    { text: 'Agree the launch date', done: true },
    { text: 'Draft the announcement', done: true },
    { text: 'Book the demo room', done: false },
];
</script>

<template>
    <div class="relative h-full px-5 py-5 sm:px-8 sm:py-7">
        <div class="text-muted-foreground text-xs">
            Projects / Spring launch
        </div>
        <h3 class="mt-1 text-xl font-semibold tracking-tight sm:text-2xl">
            Launch plan
        </h3>
        <p class="text-muted-foreground mt-2 max-w-md text-sm">
            Everything we need before the 14th, in the order we need it.
        </p>

        <ul class="mt-4 space-y-2 text-sm">
            <li
                v-for="(task, at) in TASKS"
                :key="task.text"
                class="flex items-center gap-2.5"
            >
                <span
                    class="border-border flex size-4 shrink-0 items-center justify-center rounded border"
                    :class="
                        task.done
                            ? 'bg-primary border-primary text-primary-foreground'
                            : 'welcome-tick'
                    "
                >
                    <Check
                        class="size-3"
                        :class="{ 'welcome-tick-mark': !task.done }"
                        stroke-width="3"
                    />
                </span>
                <span
                    :class="[
                        task.done ? 'text-muted-foreground line-through' : '',
                        at === 2 ? 'welcome-struck' : '',
                    ]"
                    >{{ task.text }}</span
                >
            </li>
        </ul>

        <div
            class="bg-chart-3/10 border-chart-3/30 mt-5 flex max-w-md items-start gap-2.5 rounded-lg border p-3 text-sm"
        >
            <Lightbulb class="text-chart-3 mt-0.5 size-4 shrink-0" />
            <span
                >Type
                <kbd class="bg-background rounded border px-1 text-xs">/</kbd>
                for a table, a board, maths or code.</span
            >
        </div>

        <LiveCursor name="Mai" :tone="5" class="welcome-cursor-note" />
    </div>
</template>

<style scoped>
/* Mai's cursor comes to the last task and ticks it */
.welcome-cursor-note {
    top: 30%;
    left: 70%;
    animation: note-cursor 4.5s ease-in-out both;
}

.welcome-tick {
    animation: note-tick 4.5s ease-out both;
}

.welcome-tick-mark {
    animation: note-mark 4.5s ease-out both;
}

.welcome-struck {
    animation: note-struck 4.5s ease-out both;
}

@keyframes note-cursor {
    0%,
    15% {
        top: 30%;
        left: 70%;
    }

    45%,
    62% {
        top: 57%;
        left: 4.5%;
    }

    85%,
    100% {
        top: 44%;
        left: 72%;
    }
}

@keyframes note-tick {
    0%,
    50% {
        background: transparent;
    }

    58%,
    100% {
        background: var(--primary);
        border-color: var(--primary);
        color: var(--primary-foreground);
    }
}

@keyframes note-mark {
    0%,
    50% {
        opacity: 0;
        transform: scale(0.4);
    }

    58%,
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes note-struck {
    0%,
    58% {
        color: inherit;
        text-decoration-line: none;
    }

    66%,
    100% {
        color: var(--muted-foreground);
        text-decoration-line: line-through;
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-cursor-note,
    .welcome-tick,
    .welcome-tick-mark,
    .welcome-struck {
        animation-duration: 1ms;
    }
}
</style>
