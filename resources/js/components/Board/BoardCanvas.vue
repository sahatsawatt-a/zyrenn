<script setup lang="ts">
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
import {
    computed,
    nextTick,
    onMounted,
    ref,
    shallowRef,
    useTemplateRef,
    watch,
} from 'vue';
// Imported into the page rather than installed with app.use(VueKonva): the
// plugin would put every Konva shape in the main bundle for one demo.
import {
    Circle,
    Ellipse,
    Group,
    Image as KonvaImage,
    Layer,
    Line,
    Path,
    Rect,
    Stage,
    Star,
    Text,
    Transformer,
} from 'vue-konva';
import InspectorPanel from './InspectorPanel.vue';
import { isImageFile, uploadToDrive } from '@/lib/drive';
import ImagePickerDialog from '@/components/media/ImagePickerDialog.vue';
import BoardItem from './BoardItem.vue';
import BoardOverlay from './BoardOverlay.vue';
import type { PickedImage } from '@/components/media/ImagePickerDialog.vue';
import ShapeLibrary from './ShapeLibrary.vue';
import { connectorPoints } from './connectors';
import type { Guide } from './connectors';
import {
    anchorsOf,
    boundsOf,
    boundsOfAll,
    fitOnBoard,
    overlaps,
} from './geometry';
import { alignmentFor } from './guides';
import { hasText, isConnectable, isConnector, isStroke } from './items';
import type { Item, Side, Tool } from './items';
import { svgSource } from './pictures';
import { FRAME_TITLE, useLabelEditor } from './useLabelEditor';
import { usePresenting } from './usePresenting';
import { useConnectorEnds } from './useConnectorEnds';
import { useDrawing } from './useDrawing';
import { useShortcuts } from './useShortcuts';
import { useFormulae } from './useFormulae';
import { usePictures } from './usePictures';
import { useBoard } from './useBoard';
import { useCamera } from './useCamera';

const props = withDefaults(
    defineProps<{
        // What to open the board with; nothing means the sample board
        items?: Item[] | null;
        title?: string;
        // Shown beside the title, e.g. "Saved 2 minutes ago"
        status?: string;
    }>(),
    { items: null, title: '', status: '' },
);

const emit = defineEmits<{ change: [Item[]] }>();

const board = useBoard(props.items);

// Anything that changes an item changes the board: one watcher rather than a
// call in every action, which is the same reason history takes snapshots.
watch(board.items, (items) => emit('change', items), { deep: true });

// The app's pages grow with their content, which would let the board run off
// the bottom of the window: the canvas is then partly unreachable, and the
// whole page scrolls instead of panning. Hold it to the space actually on
// screen. The offset is taken from the document, not the viewport -- read
// while the page happens to be scrolled, a viewport-relative top is negative
// and makes the board taller still.
const page = useTemplateRef<HTMLElement>('page');
const pageOffset = ref(0);

const measurePage = () => {
    const element = page.value;

    if (element) {
        pageOffset.value = Math.round(
            element.getBoundingClientRect().top + window.scrollY,
        );
    }
};

onMounted(measurePage);
useEventListener(window, 'resize', measurePage);

const pageStyle = computed(() => ({
    height: `calc(100svh - ${pageOffset.value}px)`,
}));

// ------------------------------------------------------------------ Canvas
const canvas = useTemplateRef<HTMLDivElement>('canvas');
const { width, height } = useElementSize(canvas);

const stageRef = useTemplateRef<{ getNode: () => Konva.Stage }>('stageRef');
const stage = () => stageRef.value?.getNode();

const camera = useCamera(stage, { width, height });

const tool = ref<Tool>('select');

// Konva node name, so a double-click can tell the frame's title from its body
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
const {
    presenting,
    frameIndex,
    currentFrame,
    showFrame,
    startPresenting,
    stopPresenting,
} = usePresenting({ board, camera, width, height });

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

// -------------------------------------------------------- Connector endpoints
const {
    ENDPOINT,
    connectable,
    hoveredTarget,
    hoveredAnchor,
    draggingEnd,
    soleConnector,
    connectorLink,
    endpointHandles,
    isAimedAt,
    showTarget,
    pinTo,
    onEndpointDragMove,
} = useConnectorEnds({
    board,
    camera,
    shapeAt: (point) => shapeAt(point),
    presenting,
    connectorPath: (item) => connectorPath(item),
});

// ------------------------------------------------------------ Text editing
// Before the transformer's watcher, which reads what is being typed the moment
// it is created.
const {
    editingId,
    editingItem,
    editorText,
    editorStyle,
    startEditing,
    stopEditing,
    onItemDoubleClick,
} = useLabelEditor({
    board,
    camera,
    presenting,
    // Wrapped, so the path can be worked out later rather than read now
    connectorPath: (item) => connectorPath(item),
});

// --------------------------------------------------------------- Selection
const transformerRef = useTemplateRef<{ getNode: () => Konva.Transformer }>(
    'transformerRef',
);

watch(
    [board.selection, board.items, presenting, editingId],
    () => {
        const transformer = transformerRef.value?.getNode();
        const target = stage();

        if (!transformer || !target) {
            return;
        }

        // While typing, the caret is the only frame worth showing: the
        // transformer's box and handles sat behind the editor as a second,
        // slightly larger box around the same words.
        const nodes =
            presenting.value || editingId.value
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
const {
    draft,
    guides,
    marquee,
    shapeAt,
    onPointerDown,
    onPointerMove,
    onPointerUp,
    onItemDragStart,
    onItemDragMove,
    onItemDragEnd,
    snapWhileDragging,
    applyTransform,
    onWheel,
    onStageDragEnd,
    onItemClick,
} = useDrawing({
    board,
    camera,
    stage,
    tool,
    presenting,
    panning,
    fill: fillColour,
    stroke: strokeColour,
    pinTo: (item, point) => pinTo(item, point),
    showTarget: (point, over) => showTarget(point, over),
    clearTarget: () => {
        hoveredTarget.value = null;
        hoveredAnchor.value = null;
    },
    startEditing: (id) => startEditing(id),
});

// ------------------------------------------------------------------ Formulae
const { formulae, mathHtml, mathStyle } = useFormulae({
    items: board.items,
    camera,
    editingId,
});

// ------------------------------------------------------------------ Pictures
const { importing, imageFor, addSvg, addMermaid, onImagesPicked, onDropFiles } =
    usePictures({
        board,
        middleOfView: () =>
            camera.toBoard({ x: width.value / 2, y: height.value / 2 }),
        editingId,
    });

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

useShortcuts({
    board,
    tool,
    spaceHeld,
    editingId,
    stopEditing,
    presenting,
    frameIndex,
    showFrame,
    stopPresenting,
    removeSelection,
});

// vue-konva components render a fragment, so Vue cannot inherit listeners onto
// them and warns on every mount. Konva's own events bubble, so one set on the
// layer covers every item and stays cheap with hundreds of them.
const contentLayer = useTemplateRef<{ getNode: () => Konva.Layer }>(
    'contentLayer',
);
const overlayLayer = useTemplateRef<{ getNode: () => Konva.Layer }>(
    'overlayLayer',
);

onMounted(() => {
    const layer = contentLayer.value?.getNode();

    const overlay = overlayLayer.value?.getNode();

    overlay?.on('dragstart', (event) => {
        if (event.target.name().startsWith(ENDPOINT)) {
            board.commit();
            // The dots come out while an end is in the air, so there is
            // something to aim at
            draggingEnd.value = true;
        }
    });
    overlay?.on('dragmove', (event) => {
        if (event.target.name().startsWith(ENDPOINT)) {
            onEndpointDragMove(event as Konva.KonvaEventObject<DragEvent>);
        }
    });
    overlay?.on('dragend', () => {
        hoveredTarget.value = null;
        hoveredAnchor.value = null;
        draggingEnd.value = false;
    });

    layer?.on('dragstart', onItemDragStart);
    layer?.on('dragmove', onItemDragMove);
    layer?.on('dragend', onItemDragEnd);
    // Bound on the transformer itself: Konva announces a transform with
    // node._fire(), which does not bubble, so a layer-level handler never
    // hears it -- which is why a resize only landed when the handle was let go.
    const transformer = transformerRef.value?.getNode();

    // The undo step is taken before the first frame changes anything
    transformer?.on('transformstart', () => board.commit());
    transformer?.on('transform', applyTransform);
    transformer?.on('transformend', applyTransform);

    nextTick(fitAll);
});

// What the item list looks like to the template, in paint order
const drawn = computed(() => {
    const visible = board.items.value.filter((item) => !item.hidden);

    return draft.value ? [...visible, draft.value.item] : visible;
});

const strokePoints = (item: Item) => item.points;

// While an item is being edited the editor draws the only frame around it;
// its selected outline would sit just inside that as a second box.
const isSelected = (item: Item) =>
    board.selection.value.includes(item.id) && editingId.value !== item.id;

// Which dot the pointer is over, so it can light up under it

const connectorPath = (item: Item) => connectorPoints(item, board.byId.value);
</script>

<template>
    <div
        ref="page"
        :style="pageStyle"
        class="flex min-h-0 flex-none flex-col gap-3 overflow-hidden p-4 md:p-6"
    >
        <div
            v-show="!presenting"
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <div class="min-w-0">
                <h1 class="truncate text-lg font-semibold">
                    {{ title || 'Board' }}
                </h1>
                <p class="text-muted-foreground text-xs">
                    <template v-if="status">{{ status }} · </template>Pick a
                    shape on the left and drag it out. Space drags the canvas,
                    scroll zooms, double-click any shape to label it.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <slot name="actions" />

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
        </div>

        <div class="flex min-h-0 flex-1 gap-3">
            <ShapeLibrary
                v-show="!presenting"
                :tool="tool"
                @update:tool="tool = $event"
                @add-picture="importing = true"
            />

            <div
                ref="canvas"
                :data-zoom="Math.round(camera.scale.value * 100)"
                @dragover.prevent
                @drop="onDropFiles"
                :data-camera="`${camera.position.value.x},${camera.position.value.y},${camera.scale.value}`"
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
                                // Locked: still drawn, but the pointer goes
                                // straight through it
                                listening: !item.locked,
                                draggable:
                                    !presenting &&
                                    tool === 'select' &&
                                    !spaceHeld &&
                                    // A connector is wherever its ends are:
                                    // dragging its body would slide the line
                                    // off the shapes it is pinned to
                                    !isConnector(item),
                                dragBoundFunc: snapWhileDragging(item),
                            }"
                        >
                            <BoardItem
                                :item="item"
                                :selected="isSelected(item)"
                                :editing="editingId === item.id"
                                :image="imageFor(item)"
                                :path="connectorPath(item)"
                            />
                        </Group>
                    </Layer>

                    <Layer ref="overlayLayer">
                        <BoardOverlay
                            :connectable="connectable"
                            :aiming="
                                (tool === 'arrow' || draggingEnd) && !presenting
                            "
                            :hovered-target="hoveredTarget"
                            :hovered-anchor="hoveredAnchor"
                            :handles="endpointHandles"
                            :endpoint-name="ENDPOINT"
                            :guides="guides"
                            :marquee="marquee"
                            :scale="camera.scale.value"
                        />

                        <!-- The handles for resizing and turning, bound to
                             whatever is selected -->
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

                <!-- Formulae, set by KaTeX over the canvas. They take no
                     clicks: the shape underneath is what gets selected. -->
                <div
                    v-for="item in formulae"
                    :key="item.id"
                    class="math-item"
                    :style="mathStyle(item)"
                    :data-test="`math-${item.id}`"
                    v-html="mathHtml(item)"
                />

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

            <ImagePickerDialog
                v-model:open="importing"
                destination="the board"
                allow-markup
                @insert="onImagesPicked"
                @markup="addSvg"
                @mermaid="addMermaid"
            />

            <InspectorPanel
                v-show="!presenting"
                :selection="board.selected.value"
                :fill="fillColour"
                :stroke="strokeColour"
                :items="board.items.value"
                :item-count="board.items.value.length"
                :frame-count="board.frames.value.length"
                :link="connectorLink"
                @paint="paintFill"
                @paint-stroke="paintStroke"
                @resize="resizeSelection"
                @duplicate="board.duplicate"
                @remove="removeSelection"
                @reorder="board.reorder"
                @select="
                    $event.add
                        ? board.toggleInSelection($event.id)
                        : board.select([$event.id])
                "
                @move="board.moveTo($event.id, $event.index)"
                @toggle-layer="board.toggle($event.id, $event.field)"
                @update="board.updateSelected"
                @present="startPresenting"
            />
        </div>
    </div>
</template>

<style scoped>
/* A formula sits over the canvas, but never in the way of it */
.math-item {
    position: absolute;
    display: flex;
    overflow: hidden;
    color: #0f172a;
    pointer-events: none;
}
/* KaTeX sets its own size; the wrapper's font-size is what scales it */
.math-item :deep(.katex-display) {
    margin: 0;
}
.math-item :deep(.katex) {
    font-size: 1em;
}

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
    outline-offset: 2px;
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
