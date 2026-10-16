<script setup lang="ts">
import { computed } from 'vue';
import { Circle, Line, Path, Rect, Shape } from 'vue-konva';
import type { Item } from '../../model/items';
import { PATHS } from '../../model/items';
import { hasHeader, headerDepth } from '../../model/rows';
import { lookOf, shapeOf } from '../../model/shapes';
import { CARD_RADIUS, drawStructure } from '../../model/structure';
import { iconPath } from '../../model/styles';
import { SELECTED, boxHit, useItemLook } from '../itemLook';
import { paint } from '../paint';

// A shape drawn from a path fitted to its box: the flowchart set, and
// everything in the shape library -- in its kind's own drawing, or the design
// picked for it: a database as a stack of discs, or as a card with an icon. A
// table, a class or a lane holds its rows under a titled band; a lifeline is
// who it is over a dashed line.
const props = defineProps<{
    item: Item;
    selected: boolean;
    editing: boolean;
}>();

const { theme, shown, ink, edge } = useItemLook(props);

const look = computed(() => lookOf(props.item));

/** A colour a shade darker: a lid or a side, for depth. */
const darker = (colour: string): string => {
    const found = /^#([0-9a-f]{6})$/i.exec(colour);

    if (!found) {
        return 'rgba(15,23,42,0.12)';
    }

    const value = parseInt(found[1], 16);
    const channel = (shift: number) =>
        Math.round(((value >> shift) & 255) * 0.86)
            .toString(16)
            .padStart(2, '0');

    return `#${channel(16)}${channel(8)}${channel(0)}`;
};

/**
 * Where a design's icon goes, and how big it is drawn, square whatever the
 * box: over the words on a card, in the middle of a badge's disc, or
 * filling the box on its own.
 */
const iconBox = computed(() => {
    const { width, height } = props.item;

    if (look.value.frame === 'card') {
        const size = Math.min(width * 0.36, height * 0.4);

        return { x: (width - size) / 2, y: height * 0.1, size };
    }

    // A badge or a bare icon sits at the foot of its box, just over the
    // name written under it
    const round = Math.min(width, height);
    const size = round * (look.value.frame === 'badge' ? 0.52 : 0.9);

    return {
        x: (width - size) / 2,
        y: height - round / 2 - size / 2,
        size,
    };
});

// The icon is drawn in the words' colour on a card or a badge, where it sits
// on the fill; on its own it is the shape's line
const iconColour = computed(() =>
    props.selected
        ? SELECTED
        : look.value.frame
          ? theme.value.inkOn(props.item)
          : shown(props.item.stroke),
);
</script>

<template>
    <!-- A lifeline: who it is in a box of its own depth, however long the
         dashed line under it is drawn -->
    <template v-if="shapeOf(item.kind)?.slide">
        <!-- (the whole of its box takes the pointer, and a message) -->
        <Rect
            :config="{
                width: item.width,
                height: item.height,
                fill: 'rgba(0,0,0,0)',
            }"
        />
        <Line
            :config="{
                points: [
                    item.width / 2,
                    headerDepth(item),
                    item.width / 2,
                    item.height,
                ],
                stroke: edge.stroke,
                strokeWidth: Math.max(1, item.lineWidth * 0.75),
                dash: [7, 5],
                listening: false,
            }"
        />
        <Rect
            :config="{
                width: item.width,
                height: headerDepth(item),
                fill: item.fill,
                ...edge,
                cornerRadius: CARD_RADIUS,
                listening: false,
            }"
        />
    </template>
    <!-- A table, a class or a lane is a card: its corners are rounded
         whatever its proportions, which a path fitted to the box cannot be -->
    <Rect
        v-else-if="hasHeader(item)"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            ...edge,
            cornerRadius: CARD_RADIUS,
        }"
    />
    <!-- A design with a frame of its own: a card's corners are rounded
         whatever its proportions, and a badge is a round disc -->
    <Rect
        v-else-if="look.frame === 'card'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            ...edge,
            cornerRadius: Math.min(14, item.width / 4, item.height / 4),
        }"
    />
    <Circle
        v-else-if="look.frame === 'badge'"
        :config="{
            x: item.width / 2,
            y: item.height - Math.min(item.width, item.height) / 2,
            radius: Math.min(item.width, item.height) / 2,
            fill: item.fill,
            ...edge,
            hitFunc: boxHit,
        }"
    />
    <Path
        v-else
        :config="{
            data: look.path ?? PATHS[item.kind],
            scaleX: item.width / 100,
            scaleY: item.height / 100,
            ...(look.noFill ? {} : paint(item, 100, 100)),
            ...edge,
            strokeScaleEnabled: false,
            lineJoin: shapeOf(item.kind) ? 'round' : undefined,
            lineCap: shapeOf(item.kind) ? 'round' : undefined,
            hitFunc: look.hit === 'box' ? boxHit : undefined,
        }"
    />
    <!-- A design's darker parts, for depth: a lid, a side -->
    <Path
        v-if="look.shade"
        :config="{
            data: look.shade,
            scaleX: item.width / 100,
            scaleY: item.height / 100,
            fill: darker(item.fill),
            stroke: edge.stroke,
            strokeWidth: edge.strokeWidth,
            strokeScaleEnabled: false,
            lineJoin: 'round',
            listening: false,
        }"
    />
    <!-- A design's icon (lib/icons.json, from Lucide), kept square -->
    <Path
        v-if="look.icon && iconPath(look.icon)"
        :config="{
            data: iconPath(look.icon),
            x: iconBox.x,
            y: iconBox.y,
            scaleX: iconBox.size / 24,
            scaleY: iconBox.size / 24,
            stroke: iconColour,
            strokeWidth: 2,
            lineCap: 'round',
            lineJoin: 'round',
            listening: false,
        }"
    />
    <!-- What is inside a table, a class or a lane: its titled band, and a
         row for each column or member -->
    <Shape
        v-if="hasHeader(item) && !shapeOf(item.kind)?.slide"
        :config="{
            sceneFunc: drawStructure(item, {
                ink,
                line: edge.stroke,
                editing,
            }),
            listening: false,
        }"
    />
    <!-- The parts filled in the line's colour: an end state's dot -->
    <Path
        v-if="look.solid"
        :config="{
            data: look.solid,
            scaleX: item.width / 100,
            scaleY: item.height / 100,
            fill: edge.stroke,
            listening: false,
        }"
    />
</template>
