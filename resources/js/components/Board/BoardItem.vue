<script setup lang="ts">
import {
    Circle,
    Ellipse,
    Image as KonvaImage,
    Line,
    Path,
    Rect,
    Star,
    Text,
} from 'vue-konva';
import {
    dashFor,
    headPoints,
    headsOf,
    midpointOf,
    trimmedPoints,
} from './connectors';
import { polygonPoints } from './geometry';
import type { Item } from './items';
import { PATHS, POLYGONS, hasText, isConnector, isPath } from './items';
import { FRAME_TITLE } from './useLabelEditor';

// How one thing on the board is drawn. The canvas decides where it sits and
// what may be done to it; this decides what it looks like.
const props = defineProps<{
    item: Item;
    selected: boolean;
    // Whether its label is being typed into, and so drawn by the editor
    editing: boolean;
    // A picture's bitmap, once it has decoded
    image?: HTMLImageElement;
    // A connector's path, worked out from what it is pinned to
    path: number[];
}>();

/**
 * How far a label sits from the top of its shape. A cylinder's lid and a
 * triangle's point leave no room at the edges, so their text starts lower.
 */
const labelInset = (item: Item): number => {
    if (item.kind === 'cylinder') {
        return item.height * 0.2;
    }

    if (item.kind === 'triangle') {
        return item.height * 0.35;
    }

    return 12;
};

// Close enough for a backing chip: Konva would have to measure the text to do
// better, and this only has to keep the line out of the words.
const labelWidth = (item: Item) => Math.max(28, item.text.length * 7 + 16);
</script>

<template>
    <!-- A frame is the slide: a plain board-coloured card -->
    <Rect
        v-if="item.kind === 'frame'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: 1,
            shadowColor: 'black',
            shadowOpacity: 0.06,
            shadowBlur: 18,
        }"
    />
    <!-- The frame's title sits above it and is edited
         on its own, the way a slide is named -->
    <Text
        v-if="item.kind === 'frame'"
        :config="{
            name: FRAME_TITLE,
            text: item.text || 'Untitled frame',
            y: -28,
            width: item.width,
            fontSize: 18,
            fontStyle: '600',
            fill: selected ? '#6366f1' : '#64748b',
            opacity: editing ? 0 : 1,
        }"
    />

    <Rect
        v-else-if="item.kind === 'sticky'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            cornerRadius: 4,
            shadowColor: 'black',
            shadowOpacity: 0.12,
            shadowBlur: 8,
            shadowOffsetY: 3,
            stroke: selected ? '#6366f1' : undefined,
            strokeWidth: selected ? 2 : 0,
        }"
    />

    <Rect
        v-else-if="item.kind === 'rect'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: selected ? 2 : 1.5,
            cornerRadius: 8,
        }"
    />

    <KonvaImage
        v-else-if="item.kind === 'image' && image"
        :config="{
            image: image,
            width: item.width,
            height: item.height,
        }"
    />
    <Rect
        v-else-if="item.kind === 'image'"
        :config="{
            width: item.width,
            height: item.height,
            stroke: '#cbd5e1',
            dash: [6, 6],
        }"
    />

    <Rect
        v-else-if="item.kind === 'math'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: selected ? 2 : 1.5,
            cornerRadius: 6,
        }"
    />

    <Path
        v-else-if="isPath(item.kind)"
        :config="{
            data: PATHS[item.kind],
            scaleX: item.width / 100,
            scaleY: item.height / 100,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: selected ? 2 : 1.5,
            strokeScaleEnabled: false,
        }"
    />

    <Rect
        v-else-if="item.kind === 'pill'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: selected ? 2 : 1.5,
            cornerRadius: Math.min(item.width, item.height) / 2,
        }"
    />

    <Line
        v-else-if="POLYGONS.includes(item.kind)"
        :config="{
            points: polygonPoints(item),
            closed: true,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: selected ? 2 : 1.5,
            lineJoin: 'round',
        }"
    />

    <Star
        v-else-if="item.kind === 'star'"
        :config="{
            x: item.width / 2,
            y: item.height / 2,
            numPoints: 5,
            innerRadius: Math.min(item.width, item.height) / 4,
            outerRadius: Math.min(item.width, item.height) / 2,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: selected ? 2 : 1.5,
        }"
    />

    <Ellipse
        v-else-if="item.kind === 'ellipse'"
        :config="{
            x: item.width / 2,
            y: item.height / 2,
            radiusX: item.width / 2,
            radiusY: item.height / 2,
            fill: item.fill,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: selected ? 2 : 1.5,
        }"
    />

    <Line
        v-else-if="item.kind === 'draw'"
        :config="{
            points: item.points,
            stroke: selected ? '#6366f1' : item.stroke,
            strokeWidth: 3,
            lineCap: 'round',
            lineJoin: 'round',
            tension: 0.4,
            hitStrokeWidth: 16,
        }"
    />

    <!-- A connector: drawn between two anchors the way
         its own routing, dash and head settings say -->
    <template v-else-if="isConnector(item)">
        <Line
            :config="{
                points: trimmedPoints(item, path),
                stroke: selected ? '#6366f1' : item.stroke,
                strokeWidth: item.lineWidth,
                dash: dashFor(item.lineStyle),
                tension: item.routing === 'curved' ? 0.5 : 0,
                lineCap: 'round',
                lineJoin: 'round',
                hitStrokeWidth: 18,
            }"
        />

        <!-- Each end's cap, rotated to follow the line -->
        <template v-for="head in headsOf(item, path)" :key="head.key">
            <Circle
                v-if="head.type === 'circle'"
                :config="{
                    x: head.x,
                    y: head.y,
                    radius: item.headSize * 0.4,
                    fill: selected ? '#6366f1' : item.stroke,
                    listening: false,
                }"
            />
            <Line
                v-else
                :config="{
                    x: head.x,
                    y: head.y,
                    rotation: head.rotation,
                    points: headPoints(head.type, item.headSize),
                    closed: head.type === 'arrow' || head.type === 'diamond',
                    fill:
                        head.type === 'arrow' || head.type === 'diamond'
                            ? selected
                                ? '#6366f1'
                                : item.stroke
                            : undefined,
                    stroke: selected ? '#6366f1' : item.stroke,
                    strokeWidth: item.lineWidth,
                    lineCap: 'round',
                    lineJoin: 'round',
                    listening: false,
                }"
            />
        </template>

        <!-- The label sits on a chip so the line does
             not run through the words -->
        <Rect
            v-if="item.text"
            :config="{
                x: midpointOf(path).x - labelWidth(item) / 2,
                y: midpointOf(path).y - 11,
                width: labelWidth(item),
                height: 22,
                fill: '#ffffff',
                cornerRadius: 4,
                listening: false,
                opacity: editing ? 0 : 1,
            }"
        />
        <Text
            v-if="item.text"
            :config="{
                text: item.text,
                x: midpointOf(path).x - 70,
                y: midpointOf(path).y - 9,
                width: 140,
                fontSize: 13,
                fill: '#0f172a',
                align: 'center',
                listening: false,
                opacity: editing ? 0 : 1,
            }"
        />
    </template>

    <!-- A label sits inside every shape except plain text,
     which is the label. Double-click any of them. -->
    <Text
        v-if="hasText(item) && item.kind !== 'frame' && item.kind !== 'math'"
        :config="{
            text: item.text,
            x: item.kind === 'text' ? 0 : 12,
            y: item.kind === 'text' ? 0 : labelInset(item),
            width: item.kind === 'text' ? item.width : item.width - 24,
            height:
                item.kind === 'text'
                    ? item.height
                    : item.height - labelInset(item) * 2,
            fontSize: item.fontSize,
            fontStyle: item.kind === 'text' ? '600' : 'normal',
            fill: '#0f172a',
            align: item.align,
            verticalAlign: item.verticalAlign,
            // A text item has no shape behind it, so
            // its label is the only thing that can be
            // clicked; on other shapes the box is the
            // hit area and the label stays out of it.
            listening: item.kind === 'text',
            opacity: editing ? 0 : 1,
        }"
    />
</template>
