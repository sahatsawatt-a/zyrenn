<script setup lang="ts">
import { Circle, Line, Rect } from 'vue-konva';
import { anchorsOf } from '../../composables/board/geometry';
import type { Guide } from '../../composables/board/guides';
import type { Item, Side } from '../../composables/board/items';

// What the board draws over itself while something is being done to it: the
// dots a connector can pin to, the handles on a chosen line, the ruler that
// lines things up, and the box drawn round a selection.
const props = defineProps<{
    /** The shapes a connector may pin itself to. */
    connectable: Item[];
    /** Out while the arrow tool is chosen, or an end is in the air. */
    aiming: boolean;
    /** The shape being aimed at, and the dot under the pointer. */
    hoveredTarget: string | null;
    hoveredAnchor: { id: string; side: Side } | null;
    /** The ends of the one selected connector. */
    handles: { end: 'from' | 'to'; x: number; y: number }[];
    endpointName: string;
    guides: Guide[];
    marquee: { x: number; y: number; width: number; height: number } | null;
    /** Everything is drawn in board units, so it has to be scaled back. */
    scale: number;
}>();

/** Whether this is the dot an end is about to be dropped on. */
const isAimedAt = (id: string, side: Side) =>
    props.hoveredAnchor?.id === id && props.hoveredAnchor.side === side;
</script>

<template>
    <!-- Where a connector can pin itself: out while the
     arrow tool is chosen, and while an end is in the
     air, so there is always something to aim at. Drop
     on a dot and the end keeps that face; drop
     anywhere else on the shape and it stays free. -->
    <template v-if="aiming">
        <template v-for="shape in connectable" :key="shape.id">
            <Circle
                v-for="anchor in anchorsOf(shape)"
                :key="anchor.side"
                :config="{
                    x: anchor.x,
                    y: anchor.y,
                    radius:
                        (isAimedAt(shape.id, anchor.side)
                            ? 8
                            : hoveredTarget === shape.id
                              ? 6
                              : 4) / scale,
                    fill:
                        isAimedAt(shape.id, anchor.side) ||
                        hoveredTarget === shape.id
                            ? '#6366f1'
                            : '#ffffff',
                    stroke: '#6366f1',
                    strokeWidth:
                        (isAimedAt(shape.id, anchor.side) ? 2.5 : 1.5) / scale,
                    listening: false,
                }"
            />
        </template>
    </template>

    <!-- Drag either end of a selected connector onto
     another shape to re-aim it -->
    <Circle
        v-for="handle in handles"
        :key="handle.end"
        :config="{
            name: `${endpointName}-${handle.end}`,
            x: handle.x,
            y: handle.y,
            radius: 6 / scale,
            fill: '#ffffff',
            stroke: '#6366f1',
            strokeWidth: 2 / scale,
            draggable: true,
        }"
    />

    <!-- The ruler the board holds up while you drag -->
    <Line
        v-for="(guide, index) in guides"
        :key="`guide-${index}`"
        :config="{
            points:
                guide.axis === 'x'
                    ? [guide.at, guide.from, guide.at, guide.to]
                    : [guide.from, guide.at, guide.to, guide.at],
            stroke: '#ec4899',
            strokeWidth: 1 / scale,
            dash: [4 / scale, 4 / scale],
            listening: false,
        }"
    />

    <Rect
        v-if="marquee"
        :config="{
            ...marquee,
            fill: 'rgba(99,102,241,0.08)',
            stroke: '#6366f1',
            strokeWidth: 1,
            listening: false,
        }"
    />
</template>
