<script setup lang="ts">
import type Konva from 'konva';
import { computed } from 'vue';
import { Circle, Group, Line, Rect, Shape, Text } from 'vue-konva';
import { besideRun } from '../../circuit/values';
import {
    dashFor,
    headParts,
    headsOf,
    hopRadius,
    isSwept,
    midpointOf,
    trimmedPoints,
} from '../../model/connectors';
import type { HeadPart } from '../../model/connectors';
import type { Item } from '../../model/items';
import { bumpAt, runsOf, stillOn } from '@/lib/lineHops';
import type { Hops } from '@/lib/lineHops';
import { DIRECTION, LIVE, SELECTED, WORKED, useItemLook } from '../itemLook';
import { strokePaint } from '../paint';

// A connector: drawn between two anchors the way its own routing, dash and
// head settings say, hopping over the lines it crosses, with its label on a
// chip -- and, on a wire, the voltage its net was worked out to be at.
const props = defineProps<{
    item: Item;
    selected: boolean;
    editing: boolean;
    // A live wire in a running logic circuit
    level?: 0 | 1 | null;
    // The voltage its net was worked out to be at
    worked?: string;
    // Its path, worked out from what it is pinned to
    path: number[];
    // Where it hops over the lines it crosses, by the run of its path
    hops?: Hops;
}>();

const { theme, shown, effects } = useItemLook(props);

/** The colour its caps are drawn in: its own, or the selection's. */
const lineInk = computed(() =>
    props.selected ? SELECTED : shown(props.item.stroke),
);

/**
 * What a piece of a cap is filled with: the line's colour, or -- hollow --
 * whatever the line lies on, so the line does not show through it.
 */
const headFill = (part: HeadPart) =>
    part.fill === 'ink'
        ? lineInk.value
        : part.fill === 'paper'
          ? theme.value.behind(props.item)
          : undefined;

/**
 * Drawn along its path with a bump at each line it crosses: straight to just
 * before the crossing, half round over it, and on.
 */
const hopped =
    (points: number[], hops: Hops | undefined) =>
    (context: Konva.Context, shape: Konva.Shape) => {
        if (!hops?.size || points.length < 4) {
            return;
        }

        const radius = hopRadius(props.item);

        context.beginPath();
        context.moveTo(points[0], points[1]);

        for (const [index, run] of runsOf(points).entries()) {
            for (const at of hops.get(index) ?? []) {
                if (!stillOn(run, at, radius)) {
                    continue;
                }

                const bump = bumpAt(run, at, radius);

                context.lineTo(bump.before.x, bump.before.y);
                context.arc(
                    at.x,
                    at.y,
                    radius,
                    bump.start,
                    bump.start + Math.PI,
                    !bump.clockwise,
                );
            }

            context.lineTo(run.to.x, run.to.y);
        }

        context.strokeShape(shape);
    };

/**
 * Its line: the selection's colour, a live wire's, or its own -- fading from
 * its start to its end when it has a gradient.
 */
const lineStroke = (points: number[]) => {
    if (props.selected) {
        return { stroke: SELECTED };
    }

    if (props.level === 1) {
        return { stroke: LIVE };
    }

    return strokePaint(
        { ...props.item, stroke: shown(props.item.stroke) },
        0,
        0,
        points.length >= 4
            ? {
                  start: { x: points[0], y: points[1] },
                  end: {
                      x: points[points.length - 2],
                      y: points[points.length - 1],
                  },
              }
            : undefined,
    );
};

// Close enough for a backing chip: Konva would have to measure the text to do
// better, and this only has to keep the line out of the words.
const labelWidth = (item: Item) => Math.max(28, item.text.length * 7 + 16);
</script>

<template>
    <!-- Where it crosses another line it hops over it. Both stay mounted,
         the one shown as it crosses or not: vue-konva puts a node mounted
         later on top, which would cover the caps -->
    <Shape
        :config="{
            visible: !!hops?.size,
            sceneFunc: hopped(trimmedPoints(item, path), hops),
            ...lineStroke(trimmedPoints(item, path)),
            ...effects,
            strokeWidth: item.lineWidth,
            dash: dashFor(item.lineStyle),
            lineCap: 'round',
            lineJoin: 'round',
            hitStrokeWidth: 18,
        }"
    />
    <Line
        :config="{
            visible: !hops?.size,
            points: trimmedPoints(item, path),
            ...lineStroke(trimmedPoints(item, path)),
            ...effects,
            strokeWidth: item.lineWidth,
            dash: dashFor(item.lineStyle),
            bezier: isSwept(item, path),
            lineCap: 'round',
            lineJoin: 'round',
            hitStrokeWidth: 18,
        }"
    />

    <!-- Each end's cap, turned to follow the line: an arrow is one piece, a
         crow's foot with its ring two -->
    <Group
        v-for="head in headsOf(item, path)"
        :key="head.key"
        :config="{
            x: head.x,
            y: head.y,
            rotation: head.rotation,
            listening: false,
        }"
    >
        <template
            v-for="(part, index) in headParts(head.type, item.headSize)"
            :key="index"
        >
            <Circle
                v-if="part.circle"
                :config="{
                    x: part.circle.x,
                    radius: part.circle.radius,
                    fill: headFill(part),
                    stroke: part.stroked ? lineInk : undefined,
                    strokeWidth: part.stroked ? item.lineWidth : 0,
                }"
            />
            <Line
                v-else
                :config="{
                    points: part.points,
                    closed: !!part.closed,
                    fill: headFill(part),
                    stroke: lineInk,
                    strokeWidth: item.lineWidth,
                    lineCap: 'round',
                    lineJoin: 'round',
                }"
            />
        </template>
    </Group>

    <!-- The label sits on a chip so the line does not run through the words -->
    <Rect
        v-if="item.text"
        :config="{
            x: midpointOf(path, isSwept(item, path)).x - labelWidth(item) / 2,
            y: midpointOf(path, isSwept(item, path)).y - 11,
            width: labelWidth(item),
            height: 22,
            fill: theme.behind(item),
            cornerRadius: 4,
            listening: false,
            opacity: editing ? 0 : 1,
        }"
    />
    <Text
        v-if="item.text"
        :config="{
            direction: DIRECTION,
            text: item.text,
            x: midpointOf(path, isSwept(item, path)).x - labelWidth(item) / 2,
            y: midpointOf(path, isSwept(item, path)).y - 9,
            // As wide as the chip under it: narrower, a long label wrapped
            // onto a second line off the chip
            width: labelWidth(item),
            fontSize: 13,
            fill: theme.inkOver(theme.behind(item)),
            align: 'center',
            listening: false,
            opacity: editing ? 0 : 1,
        }"
    />

    <!-- What a wire's net was worked out to be at, beside its longest run -->
    <Text
        v-if="worked && besideRun(path)"
        :config="{
            direction: DIRECTION,
            text: worked,
            x: besideRun(path)!.x - (besideRun(path)!.level ? 40 : 0),
            y: besideRun(path)!.y - 9,
            width: 80,
            align: besideRun(path)!.level ? 'center' : 'left',
            fontSize: 11,
            fill: WORKED,
            listening: false,
        }"
    />
</template>
