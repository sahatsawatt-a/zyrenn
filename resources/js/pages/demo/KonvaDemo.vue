<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Minus,
    Plus,
    Redo2,
    Undo2,
    X,
} from '@lucide/vue';
import { useElementSize, useEventListener } from '@vueuse/core';
import Konva from 'konva';
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue';
// Imported into the page rather than installed with app.use(VueKonva): the
// plugin would put every Konva shape in the main bundle for one demo.
import {
    Circle,
    Ellipse,
    Group,
    Layer,
    Line,
    Path,
    Rect,
    Stage,
    Star,
    Text,
    Transformer,
} from 'vue-konva';
import InspectorPanel from './konva/InspectorPanel.vue';
import ShapeLibrary from './konva/ShapeLibrary.vue';
import type { Item, Side, Tool } from './konva/board';
import {
    PATHS,
    POLYGONS,
    anchorAt,
    anchorsOf,
    boundsOf,
    boundsOfAll,
    hasText,
    connectorPoints,
    dashFor,
    headPoints,
    headsOf,
    isConnectable,
    isConnector,
    isPath,
    isStroke,
    midpointOf,
    nearestSide,
    overlaps,
    polygonPoints,
} from './konva/board';
import { useBoard } from './konva/useBoard';
import { useCamera } from './konva/useCamera';

const board = useBoard();

// ------------------------------------------------------------------ Canvas
const canvas = useTemplateRef<HTMLDivElement>('canvas');
const { width, height } = useElementSize(canvas);

const stageRef = useTemplateRef<{ getNode: () => Konva.Stage }>('stageRef');
const stage = () => stageRef.value?.getNode();

const camera = useCamera(stage, { width, height });

const tool = ref<Tool>('select');
// What the next shape is drawn with; recolouring a selection updates it too, so
// the toolbar always shows the colour you last worked in.
const fillColour = ref('#ffffff');
const strokeColour = ref('#0f172a');

const paintFill = (colour: string) => {
    fillColour.value = colour;
    board.paint(colour);
};

const paintStroke = (colour: string) => {
    strokeColour.value = colour;
    board.paintStroke(colour);
};
const presenting = ref(false);
const spaceHeld = ref(false);

const panning = computed(
    () => spaceHeld.value || (tool.value === 'select' && presenting.value),
);

const stageConfig = computed(() => ({
    width: width.value || 1,
    height: height.value || 1,
    // While a tween runs it owns the transform; handing it these every frame
    // would fight it.
    ...(camera.animating.value
        ? {}
        : {
              scaleX: camera.scale.value,
              scaleY: camera.scale.value,
              x: camera.position.value.x,
              y: camera.position.value.y,
          }),
    draggable: panning.value,
}));

// A dot grid that tracks the camera costs nothing as a CSS background, and
// avoids drawing thousands of Konva circles that are never interacted with.
const gridStyle = computed(() => {
    const step = 40 * camera.scale.value;

    return {
        backgroundImage:
            'radial-gradient(circle, var(--border) 1px, transparent 1px)',
        backgroundSize: `${step}px ${step}px`,
        backgroundPosition: `${camera.position.value.x}px ${camera.position.value.y}px`,
        opacity: presenting.value ? 0 : 1,
    };
});

// --------------------------------------------------------------- Selection
const transformerRef = useTemplateRef<{ getNode: () => Konva.Transformer }>(
    'transformerRef',
);

watch(
    [board.selection, board.items, presenting],
    () => {
        const transformer = transformerRef.value?.getNode();
        const target = stage();

        if (!transformer || !target) {
            return;
        }

        // A connector has no box to resize: it is wherever its ends are
        const nodes = presenting.value
            ? []
            : board.selection.value
                  .filter(
                      (id) =>
                          !isConnector(
                              board.byId.value.get(id) ?? ({} as Item),
                          ),
                  )
                  .map((id) => target.findOne(`#${id}`))
                  .filter((node): node is Konva.Node => !!node);

        transformer.nodes(nodes);
    },
    { flush: 'post', deep: true },
);

// ------------------------------------------------------- Pointer behaviour
type Draft = { item: Item; originX: number; originY: number } | null;

const draft = ref<Draft>(null);
const hoveredTarget = ref<string | null>(null);
const marquee = ref<{
    x: number;
    y: number;
    width: number;
    height: number;
} | null>(null);

let marqueeStart: { x: number; y: number } | null = null;
let dragOrigin: Map<string, { x: number; y: number }> | null = null;

const pointerOnBoard = () => {
    const point = stage()?.getPointerPosition();

    return point ? camera.toBoard(point) : null;
};

// The shape under a board point, topmost first, ignoring frames and ink so a
// connector pins itself to something it can sensibly leave from.
const shapeAt = (point: { x: number; y: number }): Item | null => {
    for (let index = board.items.value.length - 1; index >= 0; index--) {
        const item = board.items.value[index];

        if (
            isConnectable(item) &&
            overlaps(boundsOf(item), { ...point, width: 1, height: 1 })
        ) {
            return item;
        }
    }

    return null;
};

/** The anchor a connector should use when it meets `item` at `point`. */
const pinTo = (item: Item | null, point: { x: number; y: number }) =>
    item
        ? {
              item: item.id,
              side: nearestSide(item, point),
              x: point.x,
              y: point.y,
          }
        : { item: null, side: null as Side | null, x: point.x, y: point.y };

const onPointerDown = (event: Konva.KonvaEventObject<PointerEvent>) => {
    if (presenting.value || panning.value) {
        return;
    }

    const point = pointerOnBoard();

    if (!point) {
        return;
    }

    const onEmptySpace = event.target === stage();

    if (tool.value === 'select') {
        if (onEmptySpace) {
            board.select([]);
            marqueeStart = point;
            marquee.value = { x: point.x, y: point.y, width: 0, height: 0 };
        }

        return;
    }

    // Every other tool draws something, sized by the drag that follows
    const item = board.makeItem(tool.value, point.x, point.y, {
        fill: fillColour.value,
        stroke: strokeColour.value,
    });

    if (isConnector(item)) {
        // Connectors live in board coordinates: their ends decide where they are
        item.x = 0;
        item.y = 0;
        item.from = pinTo(shapeAt(point), point);
        item.to = { item: null, side: null, x: point.x, y: point.y };
    } else if (isStroke(item)) {
        item.points = [0, 0];
    } else {
        item.width = 0;
        item.height = 0;
    }

    draft.value = { item, originX: point.x, originY: point.y };
};

const onPointerMove = () => {
    const point = pointerOnBoard();

    if (!point) {
        return;
    }

    if (marqueeStart) {
        marquee.value = {
            x: Math.min(marqueeStart.x, point.x),
            y: Math.min(marqueeStart.y, point.y),
            width: Math.abs(point.x - marqueeStart.x),
            height: Math.abs(point.y - marqueeStart.y),
        };

        return;
    }

    const current = draft.value;

    if (!current) {
        return;
    }

    if (current.item.kind === 'draw') {
        // A new array rather than a push: vue-konva compares the config it was
        // handed, and pushing leaves the same array reference in place, so the
        // stroke only appeared once the item was committed on release.
        current.item.points = [
            ...current.item.points,
            point.x - current.originX,
            point.y - current.originY,
        ];

        return;
    }

    if (isConnector(current.item)) {
        const over = shapeAt(point);
        hoveredTarget.value = over?.id ?? null;

        const source = current.item.from?.item
            ? board.byId.value.get(current.item.from.item)
            : null;

        // It leaves by the side facing the cursor...
        if (source && current.item.from) {
            current.item.from.side = nearestSide(source, point);
        }

        // ...and lands on the side facing where it came from, so the elbow
        // reads the way it would in any diagram tool.
        const leaving =
            source && current.item.from?.side
                ? anchorAt(source, current.item.from.side)
                : { x: current.originX, y: current.originY };

        current.item.to = over
            ? {
                  item: over.id,
                  side: nearestSide(over, leaving),
                  x: point.x,
                  y: point.y,
              }
            : { item: null, side: null, x: point.x, y: point.y };

        return;
    }

    current.item.x = Math.min(current.originX, point.x);
    current.item.y = Math.min(current.originY, point.y);
    current.item.width = Math.abs(point.x - current.originX);
    current.item.height = Math.abs(point.y - current.originY);
};

const onPointerUp = () => {
    if (marqueeStart) {
        const box = marquee.value;

        if (box && box.width > 4 && box.height > 4) {
            board.select(
                board.items.value
                    .filter((item) => overlaps(boundsOf(item), box))
                    .map((item) => item.id),
            );
        }

        marqueeStart = null;
        marquee.value = null;

        return;
    }

    const current = draft.value;
    draft.value = null;

    if (!current) {
        return;
    }

    const item = current.item;

    // A click rather than a drag: give the item its default size so a tap
    // still puts something usable on the board.
    if (!isStroke(item) && item.width < 8 && item.height < 8) {
        const fresh = board.makeItem(item.kind, item.x, item.y);

        item.width = fresh.width;
        item.height = fresh.height;
    }

    if (isConnector(item)) {
        hoveredTarget.value = null;

        const from = item.from;
        const to = item.to;
        const tiny =
            Math.hypot(
                (to?.x ?? 0) - (from?.x ?? 0),
                (to?.y ?? 0) - (from?.y ?? 0),
            ) < 12;

        // A stray click, or a loop from a shape back to itself
        if (tiny || (from?.item && from.item === to?.item)) {
            return;
        }

        board.add(item);
        tool.value = 'select';

        return;
    }

    if (isStroke(item) && item.points.length < 4) {
        return;
    }

    board.add(item);
    tool.value = 'select';

    if (hasText(item) && item.kind !== 'frame') {
        nextTick(() => startEditing(item.id));
    }
};

// Dragging one of several selected items should move the whole selection
const onItemDragStart = (event: Konva.KonvaEventObject<DragEvent>) => {
    const id = event.target.id();

    if (!board.selection.value.includes(id)) {
        board.select([id]);
    }

    board.commit();
    dragOrigin = new Map(
        board.selected.value.map((item) => [item.id, { x: item.x, y: item.y }]),
    );
};

const onItemDragMove = (event: Konva.KonvaEventObject<DragEvent>) => {
    const origins = dragOrigin;
    const id = event.target.id();
    const origin = origins?.get(id);

    if (!origins || !origin || !board.byId.value.has(id)) {
        return;
    }

    const dx = event.target.x() - origin.x;
    const dy = event.target.y() - origin.y;

    origins.forEach((start, otherId) => {
        const item = board.byId.value.get(otherId);

        if (!item) {
            return;
        }

        item.x = otherId === id ? event.target.x() : start.x + dx;
        item.y = otherId === id ? event.target.y() : start.y + dy;
    });
};

const onItemDragEnd = () => {
    dragOrigin = null;
};

// Konva resizes by scaling; fold it back into width/height so text and corner
// radii keep their proportions.
const onTransformEnd = () => {
    board.commit();

    board.selection.value.forEach((id) => {
        const node = stage()?.findOne(`#${id}`);
        const item = board.byId.value.get(id);

        if (!node || !item) {
            return;
        }

        const scaleX = node.scaleX();
        const scaleY = node.scaleY();

        item.x = node.x();
        item.y = node.y();
        item.rotation = node.rotation();

        if (isStroke(item)) {
            item.points = item.points.map((value, index) =>
                index % 2 === 0 ? value * scaleX : value * scaleY,
            );
        } else {
            item.width = Math.max(12, item.width * scaleX);
            item.height = Math.max(12, item.height * scaleY);
        }

        node.scaleX(1);
        node.scaleY(1);
    });
};

const onWheel = (event: Konva.KonvaEventObject<WheelEvent>) => {
    event.evt.preventDefault();
    camera.zoomBy(event.evt.deltaY > 0 ? 0.92 : 1.08);
};

const onStageDragEnd = (event: Konva.KonvaEventObject<DragEvent>) => {
    if (event.target === stage()) {
        camera.position.value = { x: event.target.x(), y: event.target.y() };
    }
};

const onItemClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
    if (presenting.value || tool.value !== 'select') {
        return;
    }

    const id = event.target.id() || event.target.getParent()?.id();

    if (!id || !board.byId.value.has(id)) {
        return;
    }

    if (event.evt.shiftKey) {
        board.toggleInSelection(id);

        return;
    }

    board.select([id]);
};

// ------------------------------------------------------------ Text editing
const editingId = ref<string | null>(null);
const editorText = ref('');
const editor = useTemplateRef<HTMLTextAreaElement>('editor');

const editingItem = computed(() =>
    editingId.value ? board.byId.value.get(editingId.value) : null,
);

// The overlay sits on top of the canvas, so it has to be placed in screen
// coordinates that follow the camera.
const editorStyle = computed(() => {
    const item = editingItem.value;

    if (!item) {
        return { display: 'none' };
    }

    const scale = camera.scale.value;

    if (isConnector(item)) {
        const middle = midpointOf(connectorPath(item));

        return {
            left: `${(middle.x - 70) * scale + camera.position.value.x}px`,
            top: `${(middle.y - 11) * scale + camera.position.value.y}px`,
            width: `${140 * scale}px`,
            height: `${22 * scale}px`,
            fontSize: `${13 * scale}px`,
            textAlign: 'center' as const,
        };
    }

    return {
        left: `${item.x * scale + camera.position.value.x}px`,
        top: `${item.y * scale + camera.position.value.y}px`,
        width: `${item.width * scale}px`,
        height: `${(item.kind === 'frame' ? 32 : item.height) * scale}px`,
        fontSize: `${item.fontSize * scale}px`,
        textAlign:
            item.kind === 'sticky' ? ('center' as const) : ('left' as const),
    };
});

const startEditing = (id: string) => {
    const item = board.byId.value.get(id);

    // Ink has nowhere to put a label; everything else, connectors included, does
    if (!item || (!hasText(item) && !isConnector(item))) {
        return;
    }

    editingId.value = id;
    editorText.value = item.text;

    nextTick(() => {
        editor.value?.focus();
        editor.value?.select();
    });
};

const stopEditing = (keep = true) => {
    const id = editingId.value;

    if (id && keep) {
        board.setText(id, editorText.value);
    }

    editingId.value = null;
};

const onItemDoubleClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
    const id = event.target.id() || event.target.getParent()?.id();

    if (id && !presenting.value) {
        startEditing(id);
    }
};

// --------------------------------------------------------------- Presenting
const frameIndex = ref(0);

const currentFrame = computed(
    () => board.frames.value[frameIndex.value] ?? null,
);

const showFrame = (index: number) => {
    const frames = board.frames.value;

    if (!frames.length) {
        return;
    }

    frameIndex.value = Math.min(frames.length - 1, Math.max(0, index));
    camera.focus(boundsOf(frames[frameIndex.value]), {
        animate: true,
        padding: 24,
    });
};

// Entering and leaving presentation hides or restores the side panels, so the
// canvas changes width a frame later. The camera is recomputed once the new
// size lands -- which also keeps the frame filling the screen if the window is
// resized mid-presentation.
let refitOnResize: 'frame' | 'all' | null = null;

watch([width, height], () => {
    if (presenting.value) {
        const frame = currentFrame.value;

        if (frame) {
            camera.focus(boundsOf(frame), { padding: 24 });
        }

        return;
    }

    if (refitOnResize === 'all') {
        refitOnResize = null;
        camera.focus(boundsOfAll(board.items.value));
    }
});

const startPresenting = () => {
    if (!board.frames.value.length) {
        return;
    }

    board.select([]);
    presenting.value = true;
    showFrame(0);
};

const stopPresenting = () => {
    presenting.value = false;
    refitOnResize = 'all';
    camera.focus(boundsOfAll(board.items.value), { animate: true });
};

// ------------------------------------------------------------------ Actions
const fitAll = () => camera.focus(boundsOfAll(board.items.value));

const removeSelection = () => board.remove([...board.selection.value]);

// Typing a number in the inspector edits the one selected item
const resizeSelection = (change: {
    x?: number;
    y?: number;
    width?: number;
    height?: number;
}) => {
    const item = board.selected.value[0];

    if (!item) {
        return;
    }

    board.commit();

    if (change.x !== undefined) item.x = change.x;
    if (change.y !== undefined) item.y = change.y;
    if (change.width !== undefined) item.width = Math.max(12, change.width);
    if (change.height !== undefined) item.height = Math.max(12, change.height);
};

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    const typing =
        editingId.value !== null ||
        document.activeElement instanceof HTMLInputElement ||
        document.activeElement instanceof HTMLTextAreaElement;

    if (event.code === 'Space' && !typing) {
        spaceHeld.value = true;
        event.preventDefault();
    }

    if (presenting.value) {
        if (event.key === 'ArrowRight' || event.key === 'PageDown') {
            showFrame(frameIndex.value + 1);
        }
        if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
            showFrame(frameIndex.value - 1);
        }
        if (event.key === 'Escape') {
            stopPresenting();
        }

        return;
    }

    if (typing) {
        if (event.key === 'Escape') {
            stopEditing(false);
        }

        return;
    }

    const meta = event.ctrlKey || event.metaKey;

    if (meta && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        event.shiftKey ? board.redo() : board.undo();

        return;
    }

    if (meta && event.key.toLowerCase() === 'd') {
        event.preventDefault();
        board.duplicate();

        return;
    }

    if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        removeSelection();

        return;
    }

    if (event.key === 'Escape') {
        board.select([]);
        tool.value = 'select';
    }

    const shortcuts: Record<string, Tool> = {
        v: 'select',
        s: 'sticky',
        t: 'text',
        r: 'rect',
        p: 'pill',
        o: 'ellipse',
        g: 'triangle',
        m: 'diamond',
        h: 'hexagon',
        k: 'star',
        b: 'cylinder',
        i: 'parallelogram',
        u: 'document',
        n: 'process',
        c: 'cloud',
        a: 'arrow',
        d: 'draw',
        f: 'frame',
    };

    const next = shortcuts[event.key.toLowerCase()];

    if (next && !meta) {
        tool.value = next;
    }
});

useEventListener(window, 'keyup', (event: KeyboardEvent) => {
    if (event.code === 'Space') {
        spaceHeld.value = false;
    }
});

// vue-konva components render a fragment, so Vue cannot inherit listeners onto
// them and warns on every mount. Konva's own events bubble, so one set on the
// layer covers every item and stays cheap with hundreds of them.
const contentLayer = useTemplateRef<{ getNode: () => Konva.Layer }>(
    'contentLayer',
);

onMounted(() => {
    const layer = contentLayer.value?.getNode();

    layer?.on('dragstart', onItemDragStart);
    layer?.on('dragmove', onItemDragMove);
    layer?.on('dragend', onItemDragEnd);
    layer?.on('transformend', onTransformEnd);

    nextTick(fitAll);
});

// What the item list looks like to the template, in paint order
const drawn = computed(() =>
    draft.value ? [...board.items.value, draft.value.item] : board.items.value,
);

const strokePoints = (item: Item) => item.points;

const isSelected = (item: Item) => board.selection.value.includes(item.id);

const connectable = computed(() => board.items.value.filter(isConnectable));

const connectorPath = (item: Item) => connectorPoints(item, board.byId.value);

// Close enough for a backing chip: Konva would have to measure the text to do
// better, and this only has to keep the line out of the words.
const labelWidth = (item: Item) => Math.max(28, item.text.length * 7 + 16);

const strokeOf = (item: Item) => (isSelected(item) ? '#6366f1' : item.stroke);

// How far a label sits from the top of its shape. A cylinder's lid and a
// triangle's point leave no room at the edges, so their text starts lower.
const labelInset = (item: Item): number => {
    if (item.kind === 'cylinder') {
        return item.height * 0.2;
    }

    if (item.kind === 'triangle') {
        return item.height * 0.35;
    }

    return 12;
};
</script>

<template>
    <Head title="Konva board" />

    <div class="flex flex-1 flex-col gap-3 overflow-hidden p-4 md:p-6">
        <div
            v-show="!presenting"
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <div>
                <h1 class="text-lg font-semibold">Konva board</h1>
                <p class="text-muted-foreground text-xs">
                    Pick a shape on the left, drag it out on the canvas, group
                    work into frames and present them. Space drags the canvas,
                    scroll zooms, double-click any shape to label it.
                </p>
            </div>

            <div class="flex items-center gap-1 rounded-lg border p-1">
                <button
                    type="button"
                    class="board-zoom"
                    title="Undo (Ctrl+Z)"
                    data-test="undo"
                    :disabled="!board.canUndo.value"
                    @click="board.undo"
                >
                    <Undo2 class="size-4" />
                </button>
                <button
                    type="button"
                    class="board-zoom"
                    title="Redo (Ctrl+Shift+Z)"
                    :disabled="!board.canRedo.value"
                    @click="board.redo"
                >
                    <Redo2 class="size-4" />
                </button>
                <span class="bg-border mx-1 h-5 w-px" />
                <button
                    type="button"
                    class="board-zoom"
                    title="Zoom out"
                    @click="camera.zoomBy(0.8)"
                >
                    <Minus class="size-3.5" />
                </button>
                <button
                    type="button"
                    class="board-zoom w-14"
                    data-test="zoom"
                    title="Fit everything"
                    @click="fitAll"
                >
                    {{ Math.round(camera.scale.value * 100) }}%
                </button>
                <button
                    type="button"
                    class="board-zoom"
                    title="Zoom in"
                    @click="camera.zoomBy(1.25)"
                >
                    <Plus class="size-3.5" />
                </button>
            </div>
        </div>

        <div class="flex min-h-0 flex-1 gap-3">
            <ShapeLibrary
                v-show="!presenting"
                :tool="tool"
                @update:tool="tool = $event"
            />

            <div
                ref="canvas"
                :data-zoom="Math.round(camera.scale.value * 100)"
                class="border-sidebar-border/70 dark:border-sidebar-border relative min-h-[480px] flex-1 overflow-hidden rounded-xl border bg-white"
                :class="{ 'cursor-grab': panning }"
            >
                <div
                    class="pointer-events-none absolute inset-0"
                    :style="gridStyle"
                />

                <Stage
                    ref="stageRef"
                    :config="stageConfig"
                    @wheel="onWheel"
                    @dragend="onStageDragEnd"
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerUp"
                    @click="onItemClick"
                    @dblclick="onItemDoubleClick"
                >
                    <Layer ref="contentLayer">
                        <Group
                            v-for="item in drawn"
                            :key="item.id"
                            :config="{
                                id: item.id,
                                x: item.x,
                                y: item.y,
                                rotation: item.rotation,
                                width: item.width,
                                height: item.height,
                                draggable:
                                    !presenting &&
                                    tool === 'select' &&
                                    !spaceHeld,
                            }"
                        >
                            <!-- A frame is the slide: a plain board-coloured card -->
                            <Rect
                                v-if="item.kind === 'frame'"
                                :config="{
                                    width: item.width,
                                    height: item.height,
                                    fill: item.fill,
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
                                    strokeWidth: 1,
                                    shadowColor: 'black',
                                    shadowOpacity: 0.06,
                                    shadowBlur: 18,
                                }"
                            />
                            <Text
                                v-if="item.kind === 'frame'"
                                :config="{
                                    text: item.text,
                                    y: -28,
                                    fontSize: 18,
                                    fontStyle: '600',
                                    fill: '#64748b',
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
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : undefined,
                                    strokeWidth: isSelected(item) ? 2 : 0,
                                }"
                            />

                            <Rect
                                v-else-if="item.kind === 'rect'"
                                :config="{
                                    width: item.width,
                                    height: item.height,
                                    fill: item.fill,
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
                                    strokeWidth: isSelected(item) ? 2 : 1.5,
                                    cornerRadius: 8,
                                }"
                            />

                            <Path
                                v-else-if="isPath(item.kind)"
                                :config="{
                                    data: PATHS[item.kind],
                                    scaleX: item.width / 100,
                                    scaleY: item.height / 100,
                                    fill: item.fill,
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
                                    strokeWidth: isSelected(item) ? 2 : 1.5,
                                    strokeScaleEnabled: false,
                                }"
                            />

                            <Rect
                                v-else-if="item.kind === 'pill'"
                                :config="{
                                    width: item.width,
                                    height: item.height,
                                    fill: item.fill,
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
                                    strokeWidth: isSelected(item) ? 2 : 1.5,
                                    cornerRadius:
                                        Math.min(item.width, item.height) / 2,
                                }"
                            />

                            <Line
                                v-else-if="POLYGONS.includes(item.kind)"
                                :config="{
                                    points: polygonPoints(item),
                                    closed: true,
                                    fill: item.fill,
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
                                    strokeWidth: isSelected(item) ? 2 : 1.5,
                                    lineJoin: 'round',
                                }"
                            />

                            <Star
                                v-else-if="item.kind === 'star'"
                                :config="{
                                    x: item.width / 2,
                                    y: item.height / 2,
                                    numPoints: 5,
                                    innerRadius:
                                        Math.min(item.width, item.height) / 4,
                                    outerRadius:
                                        Math.min(item.width, item.height) / 2,
                                    fill: item.fill,
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
                                    strokeWidth: isSelected(item) ? 2 : 1.5,
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
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
                                    strokeWidth: isSelected(item) ? 2 : 1.5,
                                }"
                            />

                            <Line
                                v-else-if="item.kind === 'draw'"
                                :config="{
                                    points: item.points,
                                    stroke: isSelected(item)
                                        ? '#6366f1'
                                        : item.stroke,
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
                                        points: connectorPath(item),
                                        stroke: strokeOf(item),
                                        strokeWidth: item.lineWidth,
                                        dash: dashFor(item.lineStyle),
                                        tension:
                                            item.routing === 'curved' ? 0.5 : 0,
                                        lineCap: 'round',
                                        lineJoin: 'round',
                                        hitStrokeWidth: 18,
                                    }"
                                />

                                <!-- Each end's cap, rotated to follow the line -->
                                <template
                                    v-for="head in headsOf(
                                        item,
                                        connectorPath(item),
                                    )"
                                    :key="head.key"
                                >
                                    <Circle
                                        v-if="head.type === 'circle'"
                                        :config="{
                                            x: head.x,
                                            y: head.y,
                                            radius: item.headSize * 0.4,
                                            fill: strokeOf(item),
                                            listening: false,
                                        }"
                                    />
                                    <Line
                                        v-else
                                        :config="{
                                            x: head.x,
                                            y: head.y,
                                            rotation: head.rotation,
                                            points: headPoints(
                                                head.type,
                                                item.headSize,
                                            ),
                                            closed:
                                                head.type === 'arrow' ||
                                                head.type === 'diamond',
                                            fill:
                                                head.type === 'arrow' ||
                                                head.type === 'diamond'
                                                    ? strokeOf(item)
                                                    : undefined,
                                            stroke: strokeOf(item),
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
                                        x:
                                            midpointOf(connectorPath(item)).x -
                                            labelWidth(item) / 2,
                                        y:
                                            midpointOf(connectorPath(item)).y -
                                            11,
                                        width: labelWidth(item),
                                        height: 22,
                                        fill: '#ffffff',
                                        cornerRadius: 4,
                                        listening: false,
                                        opacity: editingId === item.id ? 0 : 1,
                                    }"
                                />
                                <Text
                                    v-if="item.text"
                                    :config="{
                                        text: item.text,
                                        x:
                                            midpointOf(connectorPath(item)).x -
                                            70,
                                        y:
                                            midpointOf(connectorPath(item)).y -
                                            9,
                                        width: 140,
                                        fontSize: 13,
                                        fill: '#0f172a',
                                        align: 'center',
                                        listening: false,
                                        opacity: editingId === item.id ? 0 : 1,
                                    }"
                                />
                            </template>

                            <!-- A label sits inside every shape except plain text,
                             which is the label. Double-click any of them. -->
                            <Text
                                v-if="hasText(item) && item.kind !== 'frame'"
                                :config="{
                                    text: item.text,
                                    x: item.kind === 'text' ? 0 : 12,
                                    y:
                                        item.kind === 'text'
                                            ? 0
                                            : labelInset(item),
                                    width:
                                        item.kind === 'text'
                                            ? item.width
                                            : item.width - 24,
                                    height:
                                        item.kind === 'text'
                                            ? undefined
                                            : item.height -
                                              labelInset(item) * 2,
                                    fontSize: item.fontSize,
                                    fontStyle:
                                        item.kind === 'text' ? '600' : 'normal',
                                    fill: '#0f172a',
                                    align:
                                        item.kind === 'text'
                                            ? 'left'
                                            : 'center',
                                    verticalAlign: 'middle',
                                    listening: false,
                                    opacity: editingId === item.id ? 0 : 1,
                                }"
                            />
                        </Group>
                    </Layer>

                    <Layer>
                        <Rect
                            v-if="marquee"
                            :config="{
                                ...marquee,
                                fill: 'rgba(99,102,241,0.08)',
                                stroke: '#6366f1',
                                strokeWidth: 1,
                                listening: false,
                            }"
                        />
                        <Transformer
                            ref="transformerRef"
                            :config="{
                                rotateEnabled: true,
                                keepRatio: false,
                                borderStroke: '#6366f1',
                                anchorStroke: '#6366f1',
                                anchorSize: 8,
                            }"
                        />
                    </Layer>
                </Stage>

                <!-- Typing happens in a real textarea laid over the canvas -->
                <textarea
                    v-if="editingItem"
                    ref="editor"
                    v-model="editorText"
                    class="board-editor"
                    :style="editorStyle"
                    data-test="text-editor"
                    @blur="stopEditing()"
                    @keydown.enter.exact.prevent="stopEditing()"
                />

                <!-- Presenting: frame position, arrows, and a way out -->
                <div
                    v-if="presenting"
                    class="bg-background/95 absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full border px-2 py-1.5 shadow-lg"
                >
                    <button
                        type="button"
                        class="board-zoom"
                        data-test="prev-frame"
                        :disabled="frameIndex === 0"
                        @click="showFrame(frameIndex - 1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <span class="px-1 text-sm" data-test="frame-position">
                        {{ frameIndex + 1 }} / {{ board.frames.value.length }}
                        <span class="text-muted-foreground">
                            · {{ currentFrame?.text }}
                        </span>
                    </span>
                    <button
                        type="button"
                        class="board-zoom"
                        data-test="next-frame"
                        :disabled="frameIndex >= board.frames.value.length - 1"
                        @click="showFrame(frameIndex + 1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="board-zoom"
                        title="Leave (Esc)"
                        data-test="exit-present"
                        @click="stopPresenting"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </div>

            <InspectorPanel
                v-show="!presenting"
                :selection="board.selected.value"
                :fill="fillColour"
                :stroke="strokeColour"
                :item-count="board.items.value.length"
                :frame-count="board.frames.value.length"
                @paint="paintFill"
                @paint-stroke="paintStroke"
                @resize="resizeSelection"
                @duplicate="board.duplicate"
                @remove="removeSelection"
                @reorder="board.reorder"
                @update="board.updateSelected"
                @present="startPresenting"
            />
        </div>
    </div>
</template>

<style scoped>
.board-editor {
    position: absolute;
    z-index: 20;
    padding: 0;
    margin: 0;
    font-family: var(--font-sans);
    font-weight: 600;
    line-height: 1.3;
    color: #0f172a;
    background: transparent;
    border: none;
    outline: 2px solid var(--primary);
    outline-offset: 4px;
    resize: none;
    overflow: hidden;
}

.board-zoom {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 1.75rem;
    min-width: 1.75rem;
    padding: 0 0.25rem;
    border-radius: var(--radius-md);
    cursor: pointer;
}
.board-zoom:hover:not(:disabled) {
    background-color: var(--muted);
}
.board-zoom:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>
