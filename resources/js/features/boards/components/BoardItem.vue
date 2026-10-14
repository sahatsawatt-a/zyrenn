<script setup lang="ts">
import type Konva from 'konva';
import { computed } from 'vue';
import {
    Circle,
    Ellipse,
    Group,
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
    headParts,
    headsOf,
    isSwept,
    midpointOf,
    trimmedPoints,
} from '@/features/boards/composables/connectors';
import type { HeadPart } from '@/features/boards/composables/connectors';
import {
    LABEL_LINE,
    LABEL_SIZE,
    inItem,
    pinTexts,
    symbolLabels,
    symbolOf,
} from '@/features/boards/circuit/symbols';
import { polygonPoints } from '@/features/boards/composables/geometry';
import type { Item } from '@/features/boards/composables/items';
import {
    PATHS,
    POLYGONS,
    hasText,
    isConnector,
    isPath,
} from '@/features/boards/composables/items';
import {
    hasHeader,
    headerDepth,
    headerSide,
} from '@/features/boards/composables/rows';
import { labelledOutside, shapeOf } from '@/features/boards/composables/shapes';
import {
    CARD_RADIUS,
    drawStructure,
} from '@/features/boards/composables/structure';
import {
    INK,
    LINE_HEIGHT,
    drawRich,
    fontOf,
    labelBox,
    labelHeight,
    labelStyle,
} from '@/features/boards/composables/labels';
import { useBoardTheme } from '@/features/boards/composables/useBoardTheme';
import { FRAME_TITLE } from '@/features/boards/composables/useLabelEditor';
import { VIDEO_PLAY } from '@/features/boards/composables/useVideos';

// How one thing on the board is drawn. The canvas decides where it sits and
// what may be done to it; this decides what it looks like.

// Konva's text is "inherit" by default, and then asks the canvas which way it
// runs every time it draws -- which has the browser work out the canvas's
// style again, for every label, on every frame of a zoom. The app runs left
// to right throughout, so it is said once.
const DIRECTION = 'ltr';
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
    // A connector's path, worked out from what it is pinned to
    path: number[];
}>();

// What is high in a running logic circuit is drawn in this
const LIVE = '#16a34a';

// On a dark board, a line in the board's ink is drawn light where it lies
// on the board itself; every other colour is drawn as it was chosen
const theme = useBoardTheme();
const shown = (colour: string) => theme.value.drawn(colour, props.item);

const SELECTED = '#6366f1';

/**
 * The colour its label is written in: read against the shape, or -- for one
 * named outside itself, a figure with its name under it -- against whatever
 * it stands on.
 */
const ink = computed(() =>
    labelledOutside(props.item.kind)
        ? theme.value.inkOver(theme.value.behind(props.item))
        : theme.value.inkOn(props.item),
);

/** A rule across a rich label is drawn as the shape's own edge is. */
const ruled = computed(() =>
    props.item.stroke && props.item.stroke !== 'transparent'
        ? shown(props.item.stroke)
        : ink.value,
);

/** The colour a connector's caps are drawn in: its own, or the selection's. */
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

/** A drawing of thin lines takes the pointer anywhere in its box. */
const boxHit = (context: Konva.Context, shape: Konva.Shape) => {
    context.beginPath();
    context.rect(0, 0, 100, 100);
    context.closePath();
    context.fillStrokeShape(shape);
};

/**
 * A shape's edge: its own line at its own weight -- none at all, at 0 -- or
 * the selection's colour over it, heavy enough to see.
 */
const edge = computed(() => ({
    stroke: props.selected ? SELECTED : shown(props.item.stroke),
    strokeWidth: props.selected
        ? Math.max(2, props.item.lineWidth)
        : props.item.lineWidth,
}));

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

/** How much a symbol is drawn larger than the library has it. */
const symbolScale = (item: Item) => {
    const symbol = symbolOf(item.symbol);

    return symbol
        ? (item.width / symbol.width + item.height / symbol.height) / 2
        : 1;
};

/**
 * Draws a symbol's words -- its name and value beside it, a module's pin
 * names inside it. They are placed on the board, the right way up whichever
 * way the part is turned, so each is put back into the part's own turned
 * space and turned the other way to match.
 *
 * One shape of no size draws them all: words that were items of their own
 * were measured into the part's box, and stretched the frame drawn round a
 * selected part out to wherever they were.
 */
const drawWords = (item: Item, ink: string) => (context: Konva.Context) => {
    const upright = (-item.rotation * Math.PI) / 180;

    context.setAttr('fillStyle', ink);
    context.setAttr('textBaseline', 'middle');

    for (const label of [...symbolLabels(item), ...pinTexts(item)]) {
        const at = inItem(item, label);

        context.save();
        context.translate(at.x, at.y);
        context.rotate(upright);
        context.setAttr(
            'font',
            `${label.name ? '600 ' : ''}${label.size ?? LABEL_SIZE}px Arial`,
        );
        context.setAttr('textAlign', label.align);
        context.fillText(
            label.text,
            0,
            label.baseline === 'top'
                ? LABEL_LINE / 2
                : label.baseline === 'bottom'
                  ? -LABEL_LINE / 2
                  : 0,
        );
        context.restore();
    }
};

// Close enough for a backing chip: Konva would have to measure the text to do
// better, and this only has to keep the line out of the words.
const labelWidth = (item: Item) => Math.max(28, item.text.length * 7 + 16);
</script>

<template>
    <!-- A frame is the slide: a plain board-coloured card. Its shadow falls
         from the fill alone: one cast by the outline as well has Konva draw
         the card off-screen first, a canvas the size of the card every time
         the board is drawn -->
    <Rect
        v-if="item.kind === 'frame'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            stroke: selected ? SELECTED : shown(item.stroke),
            strokeWidth: 1,
            shadowColor: 'black',
            shadowOpacity: 0.06,
            shadowBlur: 18,
            shadowForStrokeEnabled: false,
        }"
    />
    <!-- The frame's title sits above it and is edited
         on its own, the way a slide is named -->
    <Text
        v-if="item.kind === 'frame'"
        :config="{
            name: FRAME_TITLE,
            direction: DIRECTION,
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
            shadowForStrokeEnabled: false,
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
            ...edge,
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
                stroke: shown(item.stroke),
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
            ...edge,
            cornerRadius: 6,
        }"
    />

    <!-- A part of a circuit: the library's drawing of it, fitted to the
         box, with its name and value beside it -->
    <template v-else-if="item.kind === 'symbol'">
        <!-- The whole box takes the pointer: the drawing is thin lines -->
        <Rect
            :config="{
                width: item.width,
                height: item.height,
                fill: 'rgba(0,0,0,0)',
            }"
        />
        <!-- A logic input that is set, a lamp that is fed: filled in -->
        <Path
            v-if="symbolOf(item.symbol)?.lit && level === 1"
            :config="{
                data: symbolOf(item.symbol)!.lit,
                scaleX: item.width / symbolOf(item.symbol)!.width,
                scaleY: item.height / symbolOf(item.symbol)!.height,
                fill: LIVE,
                opacity: 0.55,
                listening: false,
            }"
        />
        <Path
            v-if="symbolOf(item.symbol)?.path"
            :config="{
                data: symbolOf(item.symbol)!.path,
                scaleX: item.width / symbolOf(item.symbol)!.width,
                scaleY: item.height / symbolOf(item.symbol)!.height,
                stroke: selected ? SELECTED : shown(item.stroke),
                // As heavy as a wire, at any zoom: the line is drawn in
                // board units, made up for whatever the part is scaled by
                strokeWidth: 2 / symbolScale(item),
                lineCap: 'round',
                lineJoin: 'round',
                listening: false,
            }"
        />
        <!-- A switch's knob: at one end while off, the other while on -->
        <Path
            v-if="symbolOf(item.symbol)?.states"
            :config="{
                data: symbolOf(item.symbol)!.states![
                    level === 1 ? 'on' : 'off'
                ],
                scaleX: item.width / symbolOf(item.symbol)!.width,
                scaleY: item.height / symbolOf(item.symbol)!.height,
                fill: shown('#ffffff'),
                stroke: selected ? SELECTED : shown(item.stroke),
                strokeWidth: 2 / symbolScale(item),
                listening: false,
            }"
        />
        <Path
            v-if="symbolOf(item.symbol)?.fill"
            :config="{
                data: symbolOf(item.symbol)!.fill,
                scaleX: item.width / symbolOf(item.symbol)!.width,
                scaleY: item.height / symbolOf(item.symbol)!.height,
                fill: selected ? SELECTED : shown(item.stroke),
                listening: false,
            }"
        />
        <Shape
            :config="{
                sceneFunc: drawWords(item, shown(INK)),
                listening: false,
                opacity: editing ? 0 : 1,
            }"
        />
    </template>

    <!-- A shape drawn from a path fitted to its box: the flowchart set, and
         everything in the shape library -->
    <template v-else-if="isPath(item.kind)">
        <!-- A lifeline: who it is in a box of its own depth, however long
             the dashed line under it is drawn -->
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
        <Path
            v-else
            :config="{
                data: PATHS[item.kind],
                scaleX: item.width / 100,
                scaleY: item.height / 100,
                fill: shapeOf(item.kind)?.noFill ? undefined : item.fill,
                ...edge,
                strokeScaleEnabled: false,
                lineJoin: shapeOf(item.kind) ? 'round' : undefined,
                lineCap: shapeOf(item.kind) ? 'round' : undefined,
                hitFunc: shapeOf(item.kind)?.hit === 'box' ? boxHit : undefined,
            }"
        />
        <!-- What is inside a table, a class or a lane: its titled band,
             and a row for each column or member -->
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
            v-if="shapeOf(item.kind)?.solid"
            :config="{
                data: shapeOf(item.kind)!.solid,
                scaleX: item.width / 100,
                scaleY: item.height / 100,
                fill: edge.stroke,
                listening: false,
            }"
        />
    </template>

    <Rect
        v-else-if="item.kind === 'pill'"
        :config="{
            width: item.width,
            height: item.height,
            fill: item.fill,
            ...edge,
            cornerRadius: Math.min(item.width, item.height) / 2,
        }"
    />

    <Line
        v-else-if="POLYGONS.includes(item.kind)"
        :config="{
            points: polygonPoints(item),
            closed: true,
            fill: item.fill,
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
            fill: item.fill,
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
            fill: item.fill,
            ...edge,
        }"
    />

    <Line
        v-else-if="item.kind === 'draw'"
        :config="{
            points: item.points,
            stroke: selected ? SELECTED : shown(item.stroke),
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
                stroke: selected
                    ? SELECTED
                    : level === 1
                      ? LIVE
                      : shown(item.stroke),
                strokeWidth: item.lineWidth,
                dash: dashFor(item.lineStyle),
                bezier: isSwept(item, path),
                lineCap: 'round',
                lineJoin: 'round',
                hitStrokeWidth: 18,
            }"
        />

        <!-- Each end's cap, turned to follow the line: an arrow is one
             piece, a crow's foot with its ring two -->
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

        <!-- The label sits on a chip so the line does
             not run through the words -->
        <Rect
            v-if="item.text"
            :config="{
                x:
                    midpointOf(path, isSwept(item, path)).x -
                    labelWidth(item) / 2,
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
                x:
                    midpointOf(path, isSwept(item, path)).x -
                    labelWidth(item) / 2,
                y: midpointOf(path, isSwept(item, path)).y - 9,
                // As wide as the chip under it: narrower, a long label
                // wrapped onto a second line off the chip
                width: labelWidth(item),
                fontSize: 13,
                fill: theme.inkOver(theme.behind(item)),
                align: 'center',
                listening: false,
                opacity: editing ? 0 : 1,
            }"
        />
    </template>

    <!-- A label sits inside every shape except plain text,
     which is the label. Double-click any of them. -->
    <template
        v-if="
            hasText(item) &&
            item.kind !== 'frame' &&
            item.kind !== 'math' &&
            item.kind !== 'symbol' &&
            !(hasHeader(item) && headerSide(item) === 'left')
        "
    >
        <!-- Rich: headings, bullets and bold words, laid out line by line -->
        <Shape
            v-if="item.rich"
            :config="{
                name: 'rich-label',
                ...label(item),
                sceneFunc: drawRich(item, ink, ruled),
                hitFunc: labelHit,
                fill: ink,
                listening: item.kind === 'text',
                opacity: editing ? 0 : 1,
            }"
        />
        <Text
            v-else
            :config="{
                direction: DIRECTION,
                text: item.text,
                ...label(item),
                fontSize: item.fontSize,
                fontFamily: fontOf(item),
                fontStyle: labelStyle(item),
                lineHeight: LINE_HEIGHT,
                fill: ink,
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
