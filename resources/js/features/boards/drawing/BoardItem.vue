<script setup lang="ts">
import { Ellipse, Line, Path, Rect, Star } from 'vue-konva';
import type { Hops } from '@/lib/lineHops';
import { polygonPoints } from '../model/geometry';
import { pressed } from '../model/ink';
import type { Item } from '../model/items';
import { POLYGONS, hasText, isConnector, isPath } from '../model/items';
import { hasHeader, headerSide } from '../model/rows';
import { SELECTED, useItemLook } from './itemLook';
import ConnectorShape from './kinds/ConnectorShape.vue';
import FrameShape from './kinds/FrameShape.vue';
import InkShape from './kinds/InkShape.vue';
import ItemLabel from './kinds/ItemLabel.vue';
import LibraryShape from './kinds/LibraryShape.vue';
import PictureShape from './kinds/PictureShape.vue';
import SymbolShape from './kinds/SymbolShape.vue';
import VideoShape from './kinds/VideoShape.vue';
import { paint, strokePaint } from './paint';

// How one thing on the board is drawn. The canvas decides where it sits and
// what may be done to it; this decides what it looks like. A kind with much
// to it is drawn by a component of its own (drawing/kinds/); the plain
// shapes are drawn here, and every item's words over whatever it is.
const props = defineProps<{
    item: Item;
    selected: boolean;
    // Whether its label is being typed into, and so drawn by the editor
    editing: boolean;
    // A picture's bitmap, once it has decoded
    image?: HTMLImageElement;
    // A video's player, once it has a frame to show, and whether it plays
    video?: HTMLVideoElement;
    playing?: boolean;
    // In a logic circuit: whether this wire, input or lamp is high just now
    level?: 0 | 1 | null;
    // On a display: which of its segments are lit
    lit?: string[];
    // What its circuit was worked out to: under a part, what flows through
    // it and is across it; beside a wire, the voltage it is at
    worked?: string;
    // A connector's path, worked out from what it is pinned to
    path: number[];
    // Where it hops over the lines it crosses, by the run of its path
    hops?: Hops;
}>();

const { shown, edge } = useItemLook(props);
</script>

<template>
    <FrameShape
        v-if="item.kind === 'frame'"
        :item="item"
        :selected="selected"
        :editing="editing"
        :image="image"
    />

    <Rect
        v-else-if="item.kind === 'sticky'"
        :config="{
            width: item.width,
            height: item.height,
            ...paint(item, item.width, item.height),
            cornerRadius: item.radius ?? 4,
            shadowColor: 'black',
            shadowOpacity: 0.12,
            shadowBlur: 8,
            shadowOffsetY: 3,
            shadowForStrokeEnabled: false,
            stroke: selected ? SELECTED : undefined,
            strokeWidth: selected ? 2 : 0,
        }"
    />

    <Rect
        v-else-if="item.kind === 'rect'"
        :config="{
            width: item.width,
            height: item.height,
            ...paint(item, item.width, item.height),
            ...edge,
            cornerRadius: item.radius ?? 8,
        }"
    />

    <PictureShape
        v-else-if="item.kind === 'image'"
        :item="item"
        :selected="selected"
        :image="image"
    />

    <VideoShape
        v-else-if="item.kind === 'video'"
        :item="item"
        :selected="selected"
        :image="image"
        :video="video"
        :playing="playing"
    />

    <Rect
        v-else-if="item.kind === 'math'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            ...edge,
            cornerRadius: 6,
        }"
    />

    <SymbolShape
        v-else-if="item.kind === 'symbol'"
        :item="item"
        :selected="selected"
        :editing="editing"
        :level="level"
        :lit="lit"
        :worked="worked"
    />

    <LibraryShape
        v-else-if="isPath(item.kind)"
        :item="item"
        :selected="selected"
        :editing="editing"
    />

    <Rect
        v-else-if="item.kind === 'pill'"
        :config="{
            width: item.width,
            height: item.height,
            ...paint(item, item.width, item.height),
            ...edge,
            cornerRadius: Math.min(item.width, item.height) / 2,
        }"
    />

    <Line
        v-else-if="POLYGONS.includes(item.kind)"
        :config="{
            points: polygonPoints(item),
            closed: true,
            ...paint(item, item.width, item.height),
            ...edge,
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
            ...paint(
                item,
                item.width,
                item.height,
                -item.width / 2,
                -item.height / 2,
            ),
            ...edge,
        }"
    />

    <Ellipse
        v-else-if="item.kind === 'ellipse'"
        :config="{
            x: item.width / 2,
            y: item.height / 2,
            radiusX: item.width / 2,
            radiusY: item.height / 2,
            ...paint(
                item,
                item.width,
                item.height,
                -item.width / 2,
                -item.height / 2,
            ),
            ...edge,
        }"
    />

    <!-- A vector path: its points scaled from their own box to the item's,
         its line kept the width it was given whatever the scale -->
    <Path
        v-else-if="item.kind === 'path'"
        :config="{
            data: item.d ?? '',
            scaleX: item.width / Math.max(1, item.viewWidth ?? item.width),
            scaleY: item.height / Math.max(1, item.viewHeight ?? item.height),
            ...paint(
                item,
                Math.max(1, item.viewWidth ?? item.width),
                Math.max(1, item.viewHeight ?? item.height),
            ),
            ...edge,
            ...(selected
                ? {}
                : strokePaint(
                      { ...item, stroke: shown(item.stroke) },
                      Math.max(1, item.viewWidth ?? item.width),
                      Math.max(1, item.viewHeight ?? item.height),
                  )),
            strokeScaleEnabled: false,
            lineCap: 'round',
            lineJoin: 'round',
            hitStrokeWidth: Math.max(12, item.lineWidth),
        }"
    />
    <!-- Pressed by a stylus: as heavy as it was pressed, point by point -->
    <InkShape
        v-else-if="item.kind === 'draw' && pressed(item)"
        :item="item"
        :selected="selected"
        :shown="shown"
    />
    <Line
        v-else-if="item.kind === 'draw'"
        :config="{
            points: item.points,
            stroke: selected ? SELECTED : shown(item.stroke),
            strokeWidth: item.inkWidth ?? 3,
            lineCap: 'round',
            lineJoin: 'round',
            tension: 0.4,
            hitStrokeWidth: 16,
        }"
    />

    <ConnectorShape
        v-else-if="isConnector(item)"
        :item="item"
        :selected="selected"
        :editing="editing"
        :level="level"
        :worked="worked"
        :path="path"
        :hops="hops"
    />

    <!-- A label sits inside every shape except plain text, which is the
         label. Double-click any of them. -->
    <ItemLabel
        v-if="
            hasText(item) &&
            item.kind !== 'frame' &&
            item.kind !== 'math' &&
            item.kind !== 'symbol' &&
            !(hasHeader(item) && headerSide(item) === 'left')
        "
        :item="item"
        :selected="selected"
        :editing="editing"
    />
</template>
