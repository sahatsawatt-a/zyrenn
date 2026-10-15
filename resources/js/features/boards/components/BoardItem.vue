<script setup lang="ts">
import {
    paint,
    strokePaint,
    withAlpha,
} from '@/features/boards/composables/paint';
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
    hopRadius,
    isSwept,
    midpointOf,
    trimmedPoints,
} from '@/features/boards/composables/connectors';
import type { HeadPart } from '@/features/boards/composables/connectors';
import { bumpAt, runsOf, stillOn } from '@/lib/lineHops';
import type { Hops } from '@/lib/lineHops';
import {
    LABEL_LINE,
    LABEL_SIZE,
    inItem,
    pinTexts,
    symbolLabels,
    symbolOf,
} from '@/features/boards/circuit/symbols';
import { WARNED, besideRun } from '@/features/boards/circuit/values';
import { polygonPoints } from '@/features/boards/composables/geometry';
import type { SlideDecor } from '@/features/boards/composables/deck';
import { useSlideDecor } from '@/features/boards/composables/deck';
import type { Item } from '@/features/boards/composables/items';
import {
    FONT_FAMILIES,
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
import {
    labelledOutside,
    lookOf,
    shapeOf,
} from '@/features/boards/composables/shapes';
import { iconPath } from '@/features/boards/composables/styles';
import {
    CARD_RADIUS,
    drawStructure,
} from '@/features/boards/composables/structure';
import {
    drawRich,
    fontOf,
    INK,
    labelBox,
    labelHeight,
    labelStyle,
    LINE_HEIGHT,
    shownText,
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

// What is high in a running logic circuit is drawn in this
const LIVE = '#16a34a';
// What a circuit was worked out to, and that which is over its rating
const WORKED = '#6366f1';
const OVER = '#dc2626';

// On a dark board, a line in the board's ink is drawn light where it lies
// on the board itself; every other colour is drawn as it was chosen
const theme = useBoardTheme();
const shown = (colour: string) => theme.value.drawn(colour, props.item);

const SELECTED = '#6366f1';

// A slide's header, footer and number, as the deck writes them on it
const slideDecor = useSlideDecor();
const decor = computed(() =>
    props.item.kind === 'frame'
        ? slideDecor.value.get(props.item.id)
        : undefined,
);

/** One line of them: at the top of the slide or its foot, either side. */
const decorLine = (
    on: SlideDecor,
    text: string,
    edge: 'top' | 'bottom',
    align: 'left' | 'right',
) => {
    const size = 12 * on.scale;
    const inset = 22 * on.scale;

    return {
        direction: DIRECTION,
        text,
        x: on.margin,
        y: edge === 'top' ? inset : props.item.height - inset - size,
        width: props.item.width - on.margin * 2,
        fontSize: size,
        fontFamily: FONT_FAMILIES[on.font],
        fill: on.color,
        align,
        wrap: 'none',
        ellipsis: true,
        listening: false,
    };
};

/**
 * The colour its label is written in: read against the shape, or -- for one
 * named outside itself, a figure with its name under it -- against whatever
 * it stands on.
 */
const ink = computed(() =>
    labelledOutside(props.item.kind, props.item.variant)
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

/**
 * A connector drawn along its path with a bump at each line it crosses:
 * straight to just before the crossing, half round over it, and on.
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
    stroke: props.selected
        ? SELECTED
        : withAlpha(shown(props.item.stroke), props.item.strokeOpacity),
    strokeWidth: props.selected
        ? Math.max(2, props.item.lineWidth)
        : props.item.lineWidth,
    ...effects.value,
}));

/**
 * What lifts it off the board or lights it: a glow round it in a colour;
 * else a shadow -- the soft one `shadow` puts under the fill, or one of its
 * own. A line with nothing inside casts it from the line itself.
 */
const effects = computed(() => {
    const item = props.item;
    const hollow = !item.fill || item.fill === 'transparent';

    if (item.glow) {
        return {
            shadowColor: item.glow,
            shadowBlur: item.glowSize ?? 24,
            shadowOpacity: 0.9,
            shadowOffsetX: 0,
            shadowOffsetY: 0,
            shadowForStrokeEnabled: hollow,
        };
    }

    if (item.shadow || item.shadowColor) {
        return {
            shadowColor: item.shadowColor ?? '#0f172a',
            shadowOpacity: item.shadowOpacity ?? 0.2,
            shadowBlur: item.shadowBlur ?? 16,
            shadowOffsetX: item.shadowX ?? 0,
            shadowOffsetY: item.shadowY ?? 6,
            shadowForStrokeEnabled: hollow,
        };
    }

    return {};
});

/**
 * A connector's line: the selection's colour, a live wire's, or its own --
 * fading from its start to its end when it has a gradient.
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

// ------------------------------------------------------------------ designs
// How a shape drawn from a path looks: its kind's own drawing, or the design
// picked for it -- a database as a stack of discs, or as a card with an icon
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

        // The point kept in view: the middle, unless the item names another
        return {
            width: item.width,
            height: item.height,
            crop: {
                x: (width - across) * (item.focusX ?? 0.5),
                y: (height - down) * (item.focusY ?? 0.5),
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
const drawWords =
    (item: Item, ink: string, worked = '') =>
    (context: Konva.Context) => {
        const upright = (-item.rotation * Math.PI) / 180;

        context.setAttr('textBaseline', 'middle');

        for (const label of [
            ...symbolLabels(item, worked ? [worked] : []),
            ...pinTexts(item),
        ]) {
            const at = inItem(item, label);

            context.setAttr(
                'fillStyle',
                label.note
                    ? label.text.startsWith(WARNED)
                        ? OVER
                        : WORKED
                    : ink,
            );
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
            ...paint(item, item.width, item.height),
            stroke: selected ? SELECTED : shown(item.stroke),
            strokeWidth: 1,
            cornerRadius: item.radius ?? 0,
            shadowColor: 'black',
            shadowOpacity: 0.06,
            shadowBlur: 18,
            shadowForStrokeEnabled: false,
        }"
    />
    <!-- A slide's own background picture, filling it unless told to fit -->
    <KonvaImage
        v-if="item.kind === 'frame' && image"
        :config="{
            image,
            ...pictured(
                { ...item, fit: item.fit === 'contain' ? 'contain' : 'cover' },
                image,
            ),
            cornerRadius: item.radius ?? 0,
            listening: false,
        }"
    />
    <!-- What the deck writes on every slide: under whatever is put on it -->
    <template v-if="decor">
        <Text
            v-if="decor.header"
            :config="decorLine(decor, decor.header, 'top', 'left')"
        />
        <Text
            v-if="decor.footer"
            :config="decorLine(decor, decor.footer, 'bottom', 'left')"
        />
        <Text
            v-if="decor.number"
            :config="decorLine(decor, decor.number, 'bottom', 'right')"
        />
    </template>
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
            ...paint(item, item.width, item.height),
            cornerRadius: item.radius ?? 4,
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
            ...paint(item, item.width, item.height),
            ...edge,
            cornerRadius: item.radius ?? 8,
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
        <KonvaImage
            :config="{
                image: image,
                ...pictured(item, image),
                cornerRadius: item.radius ?? 0,
            }"
        />
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
                visible: !!video && !(image && !playing),
                ...(video ? fitted(item, video) : {}),
            }"
        />
        <!-- Its poster, in place of a still while it isn't playing -->
        <KonvaImage
            :config="{
                image: image,
                visible: !!image && !playing,
                ...(image ? pictured({ ...item, fit: 'contain' }, image) : {}),
                listening: false,
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
        <!-- A display's segments: each faint until its pin is high -->
        <Path
            v-for="(segment, pin) in symbolOf(item.symbol)?.segments ?? {}"
            :key="pin"
            :config="{
                data: segment,
                scaleX: item.width / symbolOf(item.symbol)!.width,
                scaleY: item.height / symbolOf(item.symbol)!.height,
                fill: lit?.includes(String(pin)) ? LIVE : shown(item.stroke),
                opacity: lit?.includes(String(pin)) ? 1 : 0.1,
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
                sceneFunc: drawWords(item, shown(INK), worked),
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
        <!-- A design with a frame of its own: a card's corners are
             rounded whatever its proportions, and a badge is a round disc -->
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
        <!-- Where it crosses another line it hops over it. Both stay
             mounted, the one shown as it crosses or not: vue-konva puts a
             node mounted later on top, which would cover the caps -->
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

    <!-- What a wire's net was worked out to be at, beside its longest run -->
    <Text
        v-if="isConnector(item) && worked && besideRun(path)"
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
                text: shownText(item),
                ...label(item),
                fontSize: item.fontSize,
                fontFamily: fontOf(item),
                fontStyle: labelStyle(item),
                lineHeight: LINE_HEIGHT,
                letterSpacing: item.letterSpacing ?? 0,
                fill: ink,
                // Words that fade across the label to a second colour
                ...(item.colorTo
                    ? {
                          fillPriority: 'linear-gradient',
                          fillLinearGradientStartPoint: { x: 0, y: 0 },
                          fillLinearGradientEndPoint: {
                              x: labelBox(item).width,
                              y: 0,
                          },
                          fillLinearGradientColorStops: [
                              0,
                              ink,
                              1,
                              item.colorTo,
                          ],
                      }
                    : {}),
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
