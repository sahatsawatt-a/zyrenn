<script setup lang="ts">
import { computed } from 'vue';

// A project's tickets still to be done, as one bar: how many are open, in
// progress and on hold -- or, with none left, that nothing is.

const props = defineProps<{
    counts: { open: number; in_progress: number; on_hold: number };
}>();

const PARTS = [
    { key: 'open', label: 'open', colour: 'bg-slate-400 dark:bg-slate-500' },
    { key: 'in_progress', label: 'in progress', colour: 'bg-amber-500' },
    { key: 'on_hold', label: 'on hold', colour: 'bg-sky-500' },
] as const;

const total = computed(
    () => props.counts.open + props.counts.in_progress + props.counts.on_hold,
);

const said = computed(() =>
    total.value === 0
        ? 'No tickets left to do'
        : PARTS.map((part) => `${props.counts[part.key]} ${part.label}`).join(
              ', ',
          ),
);
</script>

<template>
    <div class="grid gap-1.5">
        <div
            class="bg-muted flex h-2 overflow-hidden rounded-full"
            role="img"
            :aria-label="said"
        >
            <template v-if="total">
                <span
                    v-for="part in PARTS"
                    :key="part.key"
                    :class="part.colour"
                    :style="{ width: `${(counts[part.key] / total) * 100}%` }"
                />
            </template>
            <span v-else class="w-full bg-emerald-500" />
        </div>
        <div
            class="text-muted-foreground flex flex-wrap gap-x-3 gap-y-0.5 text-xs"
        >
            <template v-if="total">
                <span
                    v-for="part in PARTS.filter((p) => counts[p.key] > 0)"
                    :key="part.key"
                    class="inline-flex items-center gap-1.5"
                >
                    <span class="size-2 rounded-sm" :class="part.colour" />
                    <b class="text-foreground font-semibold tabular-nums">{{
                        counts[part.key]
                    }}</b>
                    {{ part.label }}
                </span>
            </template>
            <span v-else class="inline-flex items-center gap-1.5">
                <span class="size-2 rounded-sm bg-emerald-500" />Nothing left to
                do
            </span>
        </div>
    </div>
</template>
