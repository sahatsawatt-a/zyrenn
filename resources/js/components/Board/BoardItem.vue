<script setup lang="ts">
import type Konva from 'konva';
import {
    Circle,
    Ellipse,
    Image as KonvaImage,
    Line,
    Path,
    Rect,
    Shape,
    Star,
    Text,
} from 'vue-konva';
import {
    dashFor,
    headPoints,
    headsOf,
    midpointOf,
    trimmedPoints,
} from '../../composables/board/connectors';
import { polygonPoints } from '../../composables/board/geometry';
import type { Item } from '../../composables/board/items';
import {
    PATHS,
    POLYGONS,
    hasText,
    isConnector,
    isPath,
} from '../../composables/board/items';
import {
    INK,
    LINE_HEIGHT,
    drawRich,
    fontOf,
    labelBox,
    labelHeight,
    labelStyle,
} from '../../composables/board/labels';
import { FRAME_TITLE } from '../../composables/board/useLabelEditor';
import { VIDEO_PLAY } from '../../composables/board/useVideos';

// How one thing on the board is drawn. The canvas decides where it sits and
// what may be done to it; this decides what it looks like.
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
    // A connector's path, worked out from what it is pinned to
    path: number[];
}>();

/** The video's own shape, as large as fits the item, with bars round it. */
const fitted = (item: Item, video: HTMLVideoElement) => {
    const width = video.videoWidth || item.width;
    const height = video.videoHeight || item.height;
    const scale = Math.min(item.width / width, item.height / height);

    return {
        x: (item.width - width * scale) / 2,
        y: (item.height - height * scale) / 2,
        width: width * scale,
        height: height * scale,
    };
};

/**
 * Where a picture is drawn in its box, by its fit: stretched to the box,
 * whole inside it with room either side, or covering it with the picture's
 * overflow cut away. The box is always what is picked up and resized.
 */
const pictured = (item: Item, image: HTMLImageElement) => {
    const width = image.naturalWidth || item.width;
    const height = image.naturalHeight || item.height;

    if (item.fit === 'contain') {
        const scale = Math.min(item.width / width, item.height / height);

        return {
            x: (item.width - width * scale) / 2,
            y: (item.height - height * scale) / 2,
            width: width * scale,
            height: height * scale,
        };
    }

    if (item.fit === 'cover') {
        // The part of the picture, in its own pixels, that fills the box
        const scale = Math.max(item.width / width, item.height / height);
        const across = item.width / scale;
        const down = item.height / scale;

        return {
            width: item.width,
            height: item.height,
            crop: {
                x: (width - across) / 2,
                y: (height - down) / 2,
                width: across,
                height: down,
            },
        };
    }

    return { width: item.width, height: item.height };
};

/**
 * How far through it is, as a line along the bottom. Read from the player on
 * every frame the canvas draws, rather than through Vue, which would redraw
 * the whole board to move it.
 */
const progressLine =
    (item: Item, video: HTMLVideoElement) => (context: Konva.Context) => {
        if (!video.duration || !video.currentTime) {
            return;
        }

        const thickness = Math.max(3, item.height * 0.012);

        context.setAttr('fillStyle', 'rgba(255,255,255,0.3)');
        context.fillRect(0, item.height - thickness, item.width, thickness);
        context.setAttr('fillStyle', '#6366f1');
        context.fillRect(
            0,
            item.height - thickness,
            (item.width * video.currentTime) / video.duration,
            thickness,
        );
    };

const playRadius = (item: Item) =>
    Math.max(12, Math.min(36, Math.min(item.width, item.height) / 5));

/** The triangle on the play button, pointing right, centred on its middle. */
const playTriangle = (item: Item) => {
    const radius = playRadius(item) * 0.45;

    return [-radius * 0.7, -radius, -radius * 0.7, radius, radius * 1.05, 0];
};

/** A rich label takes clicks anywhere in its box, not only on its letters. */
const labelHit = (context: Konva.Context, shape: Konva.Shape) => {
    context.beginPath();
    context.rect(0, 0, shape.width(), shape.height());
    context.closePath();
    context.fillStrokeShape(shape);
};

/**
 * The label's box, and how tall to draw it. A label with more words than
 * room is drawn at the height its words need, running out past the edge
 * where it can be seen, rather than losing its last lines without a sign.
 */
const label = (item: Item) => {
    const box = labelBox(item);

    return { ...box, height: Math.max(box.height, labelHeight(item)) };
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

    <template v-else-if="item.kind === 'image' && image">
        <!-- The whole box takes the pointer, room either side included -->
        <Rect
            :config="{
                width: item.width,
                height: item.height,
                fill: 'rgba(0,0,0,0)',
            }"
        />
        <KonvaImage :config="{ image: image, ...pictured(item, image) }" />
        <!-- A border, in the item's line colour -->
        <Rect
            v-if="item.stroke && item.stroke !== 'transparent'"
            :config="{
                width: item.width,
                height: item.height,
                stroke: item.stroke,
                strokeWidth: item.lineWidth,
                listening: false,
            }"
        />
    </template>
    <Rect
        v-else-if="item.kind === 'image'"
        :config="{
            width: item.width,
            height: item.height,
            stroke: '#cbd5e1',
            dash: [6, 6],
        }"
    />

    <!-- A video: a frame of it, redrawn as it plays, with a play button
         over it while it is paused. Every part stays mounted and is only
         shown or hidden: vue-konva adds a node that mounts later on top of
         the rest, which would bury the button under the frame. -->
    <template v-else-if="item.kind === 'video'">
        <Rect
            :config="{
                width: item.width,
                height: item.height,
                fill: item.fill,
                cornerRadius: 4,
            }"
        />
        <KonvaImage
            :config="{
                image: video,
                visible: !!video,
                ...(video ? fitted(item, video) : {}),
            }"
        />
        <Shape
            :config="{
                sceneFunc: video ? progressLine(item, video) : () => {},
                visible: !!video,
                listening: false,
            }"
        />
        <Circle
            :config="{
                name: VIDEO_PLAY,
                x: item.width / 2,
                y: item.height / 2,
                radius: playRadius(item),
                fill: 'rgba(15,23,42,0.6)',
                stroke: '#ffffff',
                strokeWidth: 2,
                visible: !playing,
            }"
        />
        <Line
            :config="{
                x: item.width / 2,
                y: item.height / 2,
                points: playTriangle(item),
                closed: true,
                fill: '#ffffff',
                listening: false,
                visible: !playing,
            }"
        />
        <Rect
            :config="{
                width: item.width,
                height: item.height,
                stroke: '#6366f1',
                strokeWidth: 2,
                cornerRadius: 4,
                listening: false,
                visible: selected,
            }"
        />
    </template>

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
    <template
        v-if="hasText(item) && item.kind !== 'frame' && item.kind !== 'math'"
    >
        <!-- Rich: headings, bullets and bold words, laid out line by line -->
        <Shape
            v-if="item.rich"
            :config="{
                name: 'rich-label',
                ...label(item),
                sceneFunc: drawRich(item),
                hitFunc: labelHit,
                fill: INK,
                listening: item.kind === 'text',
                opacity: editing ? 0 : 1,
            }"
        />
        <Text
            v-else
            :config="{
                text: item.text,
                ...label(item),
                fontSize: item.fontSize,
                fontFamily: fontOf(item),
                fontStyle: labelStyle(item),
                lineHeight: LINE_HEIGHT,
                fill: INK,
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
</template>
