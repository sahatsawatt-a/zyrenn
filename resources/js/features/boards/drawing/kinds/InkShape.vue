<script setup lang="ts">
import type Konva from 'konva';
import { computed } from 'vue';
import { Shape } from 'vue-konva';
import { pressedWidths } from '../../model/ink';
import type { Item } from '../../model/items';
import { SELECTED } from '../itemLook';

// Pencil ink a stylus pressed: drawn a piece at a time, each as heavy as it
// was pressed there, round at the joins so it reads as one stroke.

const props = defineProps<{
    item: Item;
    selected: boolean;
    shown: (colour: string) => string;
}>();

const widths = computed(() => pressedWidths(props.item));

const pieces = (context: Konva.Context, colour: string, extra = 0) => {
    const points = props.item.points;

    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = colour;

    for (let at = 0; at + 3 < points.length; at += 2) {
        context.beginPath();
        context.moveTo(points[at], points[at + 1]);
        context.lineTo(points[at + 2], points[at + 3]);
        context.lineWidth = (widths.value[at / 2] ?? 3) + extra;
        context.stroke();
    }
};

const config = computed(() => ({
    sceneFunc: (context: Konva.Context) =>
        pieces(
            context,
            props.selected ? SELECTED : props.shown(props.item.stroke),
        ),
    // Easy to pick, however light it was pressed
    hitFunc: (context: Konva.Context, shape: Konva.Shape) =>
        pieces(context, shape.colorKey, 12),
}));
</script>

<template>
    <Shape :config="config" />
</template>
