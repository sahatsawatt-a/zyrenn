<script setup lang="ts">
import { Circle, Line, Path, Rect } from 'vue-konva';
import type { Run } from '@/features/boards/pen/usePen';

// The pen at work: the path drawn so far, the line to where the pointer is,
// each point as a square and each handle as a dot on a line from its point.
// Everything is in board units, scaled back to stay the same size on screen.
defineProps<{
    preview: { d: string; reaching: string; runs: Run[] } | null;
    scale: number;
}>();

const INK = '#6366f1';
</script>

<template>
    <template v-if="preview">
        <Path
            :config="{
                data: preview.d,
                stroke: INK,
                strokeWidth: 1.5 / scale,
                listening: false,
            }"
        />
        <Path
            v-if="preview.reaching"
            :config="{
                data: preview.reaching,
                stroke: INK,
                strokeWidth: 1 / scale,
                dash: [4 / scale, 4 / scale],
                listening: false,
            }"
        />
        <template v-for="(run, r) in preview.runs" :key="`run-${r}`">
            <template v-for="(anchor, a) in run.anchors" :key="`${r}-${a}`">
                <Line
                    v-if="anchor.inX !== undefined"
                    :config="{
                        points: [anchor.x, anchor.y, anchor.inX, anchor.inY],
                        stroke: INK,
                        strokeWidth: 1 / scale,
                        listening: false,
                    }"
                />
                <Line
                    v-if="anchor.outX !== undefined"
                    :config="{
                        points: [anchor.x, anchor.y, anchor.outX, anchor.outY],
                        stroke: INK,
                        strokeWidth: 1 / scale,
                        listening: false,
                    }"
                />
                <Circle
                    v-if="anchor.inX !== undefined"
                    :config="{
                        x: anchor.inX,
                        y: anchor.inY,
                        radius: 3.5 / scale,
                        fill: '#ffffff',
                        stroke: INK,
                        strokeWidth: 1 / scale,
                        listening: false,
                    }"
                />
                <Circle
                    v-if="anchor.outX !== undefined"
                    :config="{
                        x: anchor.outX,
                        y: anchor.outY,
                        radius: 3.5 / scale,
                        fill: '#ffffff',
                        stroke: INK,
                        strokeWidth: 1 / scale,
                        listening: false,
                    }"
                />
                <Rect
                    :config="{
                        x: anchor.x - 4 / scale,
                        y: anchor.y - 4 / scale,
                        width: 8 / scale,
                        height: 8 / scale,
                        fill: a === 0 && !run.closed ? INK : '#ffffff',
                        stroke: INK,
                        strokeWidth: 1.5 / scale,
                        listening: false,
                    }"
                />
            </template>
        </template>
    </template>
</template>
