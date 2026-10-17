<script setup lang="ts">
import type Konva from 'konva';
import type { OpSet } from 'roughjs/bin/core';
import { computed } from 'vue';
import { Shape } from 'vue-konva';
import type { Item } from '../../model/items';
import { useItemLook } from '../itemLook';
import { withAlpha } from '../paint';
import type { Figure } from '../sketch';
import { opsPath, outlineOf, seedOf, sketchOf } from '../sketch';

// An item drawn as by hand: its outline gone over twice with an unsteady
// line, its inside filled a little outside the lines or hatched across.
// Takes the pointer by its crisp outline, so a wobble never misses a click.
const props = withDefaults(
    defineProps<{
        item: Item;
        selected: boolean;
        figure: Figure;
        // Left out: its own edge, and its own fill. A frame keeps its fill crisp
        // and has only its edge drawn by hand; a connector's line is its own
        fill?: string | null;
        stroke?: string;
        lineWidth?: number;
        dash?: number[];
        listening?: boolean;
    }>(),
    // (a boolean left out would be false)
    { listening: true },
);

const { edge } = useItemLook(props);

const fillColour = computed(() => {
    const fill = props.fill === undefined ? props.item.fill : props.fill;

    return fill && fill !== 'transparent'
        ? withAlpha(fill, props.item.fillOpacity)
        : null;
});

const strokeColour = computed(() => props.stroke ?? edge.value.stroke);
const weight = computed(() => props.lineWidth ?? edge.value.strokeWidth);

const sets = computed(() =>
    sketchOf(props.figure, {
        seed: seedOf(props.item.id),
        level: props.item.sketch === 2 ? 2 : 1,
        lineWidth: weight.value,
        filled: !!fillColour.value,
        hatch: props.item.hatch ?? 'solid',
    }),
);

// Each part made a Path2D once: a board pans and zooms by drawing it again
const paths = new WeakMap<OpSet, Path2D>();
const pathOf = (set: OpSet) => {
    let path = paths.get(set);

    if (!path) {
        path = new Path2D(opsPath(set.ops));
        paths.set(set, path);
    }

    return path;
};

/**
 * How it is painted, made again whenever what it is drawn from changes, so
 * Konva is told to draw it again.
 */
const config = computed(() => {
    const parts = sets.value;
    const fill = fillColour.value;
    const stroke = strokeColour.value;
    const width = weight.value;
    const dash = props.dash ?? [];
    const figure = props.figure;
    const outline = new Path2D(outlineOf(figure));

    return {
        name: 'sketch',
        listening: props.listening,
        sceneFunc: (context: Konva.Context) => {
            const canvas = context._context;

            canvas.lineCap = 'round';
            canvas.lineJoin = 'round';

            for (const set of parts) {
                if (set.type === 'fillPath' && fill) {
                    canvas.fillStyle = fill;
                    canvas.fill(pathOf(set), 'evenodd');
                } else if (set.type === 'fillSketch' && fill) {
                    canvas.setLineDash([]);
                    canvas.strokeStyle = fill;
                    canvas.lineWidth = Math.max(1, width / 2);
                    canvas.stroke(pathOf(set));
                } else if (set.type === 'path' && stroke) {
                    canvas.setLineDash(dash);
                    canvas.strokeStyle = stroke;
                    canvas.lineWidth = width;
                    canvas.stroke(pathOf(set));
                }
            }
        },
        hitFunc: (context: Konva.Context, shape: Konva.Shape) => {
            const canvas = context._context;

            canvas.fillStyle = shape.colorKey;
            canvas.strokeStyle = shape.colorKey;
            canvas.lineWidth = Math.max(12, width);

            if (figure.type !== 'line') {
                canvas.fill(outline);
            }

            canvas.stroke(outline);
        },
    };
});
</script>

<template>
    <Shape :config="config" />
</template>
