<script setup lang="ts">
import type { RunState } from './steps';

// How a suite's last runs went, oldest first, as a row of small bars: green
// for one that passed, red for one that failed, blue for one still going.
// Empty places stand for runs not had yet.

const props = withDefaults(
    defineProps<{ states: RunState[]; places?: number; tall?: boolean }>(),
    { places: 10, tall: false },
);

const TONE: Record<RunState, string> = {
    passed: 'bg-emerald-500',
    failed: 'bg-red-500',
    running: 'bg-sky-500 animate-pulse',
    waiting: 'bg-sky-500/50 animate-pulse',
};

const empty = () => Math.max(0, props.places - props.states.length);
</script>

<template>
    <div
        class="flex items-end gap-0.5"
        :aria-label="`The last ${states.length} runs: ${states.filter((one) => one === 'passed').length} passed`"
        role="img"
    >
        <span
            v-for="left in empty()"
            :key="`empty-${left}`"
            class="bg-muted w-1.5 rounded-sm"
            :class="tall ? 'h-6' : 'h-3'"
        />
        <span
            v-for="(state, at) in states.slice(-places)"
            :key="at"
            class="w-1.5 rounded-sm"
            :class="[TONE[state], tall ? 'h-6' : 'h-3']"
            :title="state"
        />
    </div>
</template>
