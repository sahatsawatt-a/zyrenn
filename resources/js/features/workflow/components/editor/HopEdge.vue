<script setup lang="ts">
import { BaseEdge, Position, getSmoothStepPath } from '@vue-flow/core';
import type { EdgeProps } from '@vue-flow/core';
import { computed, onBeforeUnmount, watch } from 'vue';
import {
    HOP_RADIUS,
    hideEdge,
    hopsOf,
    readPath,
    showEdge,
} from '@/features/workflow/edgeHops';
import { bumpAt, stillOn } from '@/lib/lineHops';

// A line between two steps, routed the way Vue Flow's smooth step routes it,
// with a small bump wherever it hops over another line -- so two lines that
// cross are not mistaken for one that branches.
const props = defineProps<EdgeProps>();

const route = computed(
    () =>
        getSmoothStepPath({
            sourceX: props.sourceX,
            sourceY: props.sourceY,
            sourcePosition: props.sourcePosition ?? Position.Bottom,
            targetX: props.targetX,
            targetY: props.targetY,
            targetPosition: props.targetPosition ?? Position.Top,
        })[0],
);

const read = computed(() => readPath(route.value));

watch(
    () => [props.id, read.value.runs] as const,
    ([id, runs], before) => {
        if (before && before[0] !== id) {
            hideEdge(before[0]);
        }

        showEdge(id, runs);
    },
    { immediate: true },
);

onBeforeUnmount(() => hideEdge(props.id));

/** The route again, with a bump over each line it hops. */
const drawn = computed(() => {
    const hops = hopsOf(props.id);
    const { commands, runs, runOf } = read.value;

    if (!hops?.size) {
        return route.value;
    }

    return commands
        .map((command, index) => {
            const run = runOf.has(index) ? runs[runOf.get(index)!] : undefined;
            const plain = `${command.letter}${command.numbers.join(' ')}`;

            if (!run) {
                return plain;
            }

            const bumps = (hops.get(runOf.get(index)!) ?? [])
                .filter((at) => stillOn(run, at, HOP_RADIUS))
                .map((at) => {
                    const bump = bumpAt(run, at, HOP_RADIUS);

                    return `L${bump.before.x} ${bump.before.y}A${HOP_RADIUS} ${HOP_RADIUS} 0 0 ${bump.clockwise ? 1 : 0} ${bump.after.x} ${bump.after.y}`;
                });

            return `${bumps.join('')}${plain}`;
        })
        .join('');
});
</script>

<template>
    <BaseEdge
        :id="id"
        :path="drawn"
        :marker-end="markerEnd"
        :marker-start="markerStart"
        :style="style"
        :interaction-width="interactionWidth"
    />
</template>
