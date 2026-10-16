<script setup lang="ts">
import { PanelLeftOpen, PanelRightOpen } from '@lucide/vue';
import type { HocuspocusProvider } from '@hocuspocus/provider';
import { useDebounceFn, useElementSize, useEventListener } from '@vueuse/core';
import type Konva from 'konva';
import type { Ref } from 'vue';
import type * as Y from 'yjs';
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
// Imported into the page rather than installed with app.use(VueKonva): the
// plugin would put every Konva shape in the main bundle for one demo.
import { Layer, Stage, Transformer } from 'vue-konva';
import MediaPickerDialog from '@/components/media/MediaPickerDialog.vue';
import MediaViewer from '@/components/media/MediaViewer.vue';
import CircuitTextDialog from '../circuit/CircuitTextDialog.vue';
import { useBoardCircuit } from '../circuit/useBoardCircuit';
import type { Deck } from '../deck/deck';
import { provideDeck, slidesRestyled } from '../deck/deck';
import PresentControls from '../deck/PresentControls.vue';
import { useShow } from '../deck/useShow';
import BoardFormulae from '../drawing/BoardFormulae.vue';
import BoardNode from '../drawing/BoardNode.vue';
import { provideBoardTheme } from '../drawing/useBoardTheme';
import { useVideos } from '../drawing/useVideos';
import InspectorPanel from '../inspector/InspectorPanel.vue';
import {
    attachmentsOf,
    connectorHops,
    connectorPoints,
} from '../model/connectors';
import { boundsOf, boundsOfAll } from '../model/geometry';
import type { Item, Tool } from '../model/items';
import { isTable } from '../model/schema';
import { restyled } from '../model/styles';
import PenOverlay from '../pen/PenOverlay.vue';
import { usePen } from '../pen/usePen';
import BoardBar from './BoardBar.vue';
import BoardOverlay from './BoardOverlay.vue';
import BoardPointers from './BoardPointers.vue';
import BoardVideoControls from './BoardVideoControls.vue';
import FrameMenu from './FrameMenu.vue';
import LayersPanel from './LayersPanel.vue';
import ShapeLibrary from './ShapeLibrary.vue';
import TemplateDialog from './TemplateDialog.vue';
import ZoomPill from './ZoomPill.vue';
import { useBoard } from './useBoard';
import { useBoardPointers, useBoardSync } from './useBoardSync';
import { useCamera } from './useCamera';
import { useConnectorEnds } from './useConnectorEnds';
import { useDrawing } from './useDrawing';
import { useFrameMenu } from './useFrameMenu';
import { useGestures } from './useGestures';
import { useLabelEditor } from './useLabelEditor';
import { useOnScreen } from './useOnScreen';
import { usePanels } from './usePanels';
import { usePictures } from './usePictures';
import { usePlacing } from './usePlacing';
import { useShortcuts } from './useShortcuts';
import { useTransformer } from './useTransformer';
import { useVideoControls } from './useVideoControls';

// The board editor: the canvas and everything laid over it. What each part
// does lives in its own composable or component; this joins them up.

const props = withDefaults(
    defineProps<{
        // What to open the board with; nothing means the sample board
        items?: Item[] | null;
        title?: string;
        // Shown beside the title, e.g. "Saved 2 minutes ago"
        status?: string;
        // Drawn on live with others (useShared): the board is the shared document
        shared?: {
            document: Y.Doc;
            provider: HocuspocusProvider;
            me: { name: string; color: string };
            synced: Ref<boolean>;
        } | null;
        // Draws one frame as a picture, on the server: given, a right-click
        // on a frame offers to save it. The demo board is saved nowhere, so
        // there is nothing for the server to draw and no menu.
        pictureOfFrame?: ((frame: Item) => Promise<File>) | null;
        // The deck its frames make: one look, and what every slide says;
        // and whether it can be changed yet (shared, once it has arrived)
        deck?: Deck | null;
        deckReady?: boolean;
        // The presenter's window: where it opens, and the channel it hears
        // the show on. The demo board has none.
        presenter?: { url: string; channel: string } | null;
    }>(),
    {
        items: null,
        title: '',
        status: '',
        shared: null,
        pictureOfFrame: null,
        deck: null,
        deckReady: true,
        presenter: null,
    },
);

const emit = defineEmits<{ change: [Item[]]; deck: [Deck | null] }>();

const board = useBoard(props.items);

// Light, or dark with the rest of the app: everything drawn below follows it
const theme = provideBoardTheme(() => board.items.value);
// ...and every slide has what the deck writes on it
const slideDecor = provideDeck(
    () => board.items.value,
    () => props.deck,
    () => props.title,
);

if (props.shared) {
    useBoardSync(board, props.shared.document, props.shared.synced);
}

// Anything that changes an item changes the board: one watcher rather than a
// call in every action, which is the same reason history takes snapshots.
watch(board.revision, () => emit('change', board.items.value));

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

// ---------------------------------------------------- Everyone else's hand
const pointers = props.shared
    ? useBoardPointers(props.shared.provider, props.shared.me)
    : null;

const onCanvasPointer = (event: PointerEvent) => {
    const box = canvas.value?.getBoundingClientRect();

    if (pointers && box) {
        pointers.point(
            camera.toBoard({
                x: event.clientX - box.left,
                y: event.clientY - box.top,
            }),
        );
    }
};

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

// ------------------------------------------------------------------ Panels
const { compact, sheet, panelOpen, layersOpen, uncovered } = usePanels(width);

const fitAll = ({ animate = false } = {}) =>
    camera.focus(boundsOfAll(board.items.value), {
        animate,
        padding: 48,
        inset: uncovered(),
    });

// -------------------------------------------------------------- Presenting
const {
    presenting,
    frameIndex,
    currentFrame,
    showFrame,
    fullScreen,
    enterFullScreen,
    startPresenting,
    stopPresenting,
    notesShown,
    openPresenter,
    addSlide,
} = useShow({
    board,
    camera,
    width,
    height,
    screen: () => page.value,
    overview: (options) => fitAll(options),
    uncovered,
    tool,
    title: () => props.title,
    deck: () => props.deck,
    presenter: () => props.presenter,
    slideDecor,
});

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
    connectorLink,
    endpointHandles,
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

useTransformer({
    board,
    transformer: () => transformerRef.value?.getNode(),
    stage,
    presenting,
    editingId,
    applyTransform: () => applyTransform(),
});

// ------------------------------------------------------- Pointer behaviour
const {
    draft,
    guides,
    marquee,
    shapeAt,
    onPointerDown,
    onPointerMove,
    onPointerUp,
    cancelPointer,
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
    connectorPath: (item) => connectorPath(item),
});

// The vector pen: drawing a path a point at a time, and moving a path's
// points about once it is double-clicked
const pen = usePen({
    board,
    camera,
    stage,
    tool,
    fill: fillColour,
    stroke: strokeColour,
});

// Which pointer that is, where there are several: a pen, the hand resting
// beside it, and the fingers that slide and pinch the board. While the
// vector pen is at work, it has the pointer instead
const { onStagePointerDown, onStagePointerMove } = useGestures({
    stage,
    surface: canvas,
    camera,
    tool,
    panning,
    select: board.select,
    onPointerDown: (event) =>
        pen.active() && !panning.value ? pen.down() : onPointerDown(event),
    onPointerMove: (event) =>
        pen.active() ? pen.move() : onPointerMove(event),
    onPointerUp: () => (pen.active() ? pen.up() : onPointerUp()),
    cancelPointer,
});

// A double-click opens a path's points. While one is being drawn the pen
// tells a double-click itself, by a second click on the same point: two
// quick clicks in different places are two points
const onDouble = (event: Konva.KonvaEventObject<MouseEvent>) => {
    if (pen.drawing.value) {
        return;
    }

    const id = event.target
        .findAncestor(
            (node: Konva.Node) => board.byId.value.has(node.id()),
            true,
        )
        ?.id();

    if (id && tool.value === 'select' && pen.edit(id)) {
        return;
    }

    onDoubleClick(event);
};

// --------------------------------------------------- A frame, right-clicked
const { frameMenu, onContextMenu } = useFrameMenu({
    board,
    camera,
    stage,
    presenting,
    offered: () => !!props.pictureOfFrame,
});

// ------------------------------------------------------------------ Pictures
const {
    importing,
    importKind,
    startImport,
    imageFor,
    addSvg,
    addMermaid,
    onMediaPicked,
    onDropFiles,
} = usePictures({
    board,
    middleOfView: () =>
        camera.toBoard({ x: width.value / 2, y: height.value / 2 }),
    editingId,
});

// ------------------------------------------------------------------ Actions
// Picked from the layers, it is brought into view: the list is often the only
// way to find something on a big board
const pickLayer = ({ id, add }: { id: string; add: boolean }) => {
    if (add) {
        board.toggleInSelection(id);
    } else {
        board.select([id]);
    }

    const item = board.byId.value.get(id);

    if (item) {
        camera.reveal(boundsOf(item), { inset: uncovered() });
    }
};

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

// ----------------------------------------------------------------- Circuits
const {
    hasCircuit,
    canWorkOut,
    showValues,
    worked,
    signals,
    turnSelection,
    onStagePress,
    onStageClick,
    onStageHover,
} = useBoardCircuit({
    board,
    tool,
    presenting,
    stage,
    click: (event) => onClick(event),
});

const hasSchema = computed(() => board.items.value.some(isTable));

const writingCircuit = ref(false);
const choosingTemplate = ref(false);
const readingSchema = ref(false);

const { placeCircuit, placeTemplate } = usePlacing({
    board,
    camera,
    width,
    height,
    tool,
    uncovered,
});

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
    turnSelection,
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

// -------------------------------------------------------------------- Videos
// A hidden video is let go of, so it does not play on where nobody sees it
const videos = useVideos(
    computed(() => board.items.value.filter((item) => !item.hidden)),
    () => contentLayer.value?.getNode(),
);

const { onClick, onDoubleClick, expandVideo, videoBar } = useVideoControls({
    board,
    videos,
    camera,
    tool,
    presenting,
    onItemClick,
    onItemDoubleClick,
});

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

    // Opened on everything there is: as soon as the canvas has a size, and
    // again once the page has finished laying itself out -- its size settles
    // over a few frames, and can even read nothing for one of them -- unless
    // the camera has been moved in between
    let fittedTo = '';
    const cameraNow = () =>
        `${camera.position.value.x},${camera.position.value.y},${camera.scale.value}`;
    const fitAgain = useDebounceFn(() => {
        stopWaiting();

        if (width.value && cameraNow() === fittedTo) {
            fitAll();
        }
    }, 150);
    const stopWaiting = watch(
        [width, height],
        ([across, down]) => {
            if (!across || !down) {
                return;
            }

            if (!fittedTo) {
                fitAll();
                fittedTo = cameraNow();
            }

            void fitAgain();
        },
        { immediate: true },
    );
});

// What the item list looks like to the template, in paint order: what is in
// view or near it, and the shape being drawn on top
const onScreen = useOnScreen({
    items: () => board.items.value,
    camera,
    width,
    height,
    kept: () =>
        new Set(
            editingId.value
                ? [...board.selection.value, editingId.value]
                : board.selection.value,
        ),
    presenting,
    connectorPath: (item) => connectorPath(item),
});

const drawn = computed(() =>
    draft.value ? [...onScreen.value, draft.value.item] : onScreen.value,
);

// While an item is being edited the editor draws the only frame around it;
// its selected outline would sit just inside that as a second box.
const isSelected = (item: Item) =>
    board.selection.value.includes(item.id) && editingId.value !== item.id;

// Which connectors hang off each shape: only connectors coming and going
// change it, so moving shapes about leaves it be
const attached = computed(() => attachmentsOf(board.items.value));
// Where lines cross, which hops over which
const hops = computed(() => connectorHops(board.items.value, connectorPath));

const connectorPath = (item: Item) =>
    connectorPoints(item, board.byId.value, attached.value);
</script>

<template>
    <div
        ref="page"
        :style="pageStyle"
        class="flex min-h-0 flex-none flex-col overflow-hidden"
        :class="{ 'bg-background': presenting }"
    >
        <!-- One slim bar: what this is, where it stands, and what to do with it -->
        <BoardBar
            v-show="!presenting"
            v-model:show-values="showValues"
            v-model:reading-schema="readingSchema"
            :title="title"
            :status="status"
            :compact="compact"
            :items="board.items.value"
            :can-work-out="canWorkOut"
            :has-circuit="hasCircuit"
            :has-schema="hasSchema"
            :frame-count="board.frames.value.length"
            @templates="choosingTemplate = true"
            @add-slide="addSlide"
            @present="startPresenting"
        >
            <!-- The page's own, when it has them -->
            <template v-if="$slots.title" #title
                ><slot name="title"
            /></template>
            <template #actions><slot name="actions" /></template>
        </BoardBar>

        <div
            ref="canvas"
            :data-zoom="Math.round(camera.scale.value * 100)"
            :data-pen="
                pen.editing.value
                    ? 'editing'
                    : pen.drawing.value
                      ? 'drawing'
                      : undefined
            "
            @dragover.prevent
            @drop="onDropFiles"
            @pointermove="onCanvasPointer"
            @pointerleave="pointers?.point(null)"
            :data-camera="`${camera.position.value.x},${camera.position.value.y},${camera.scale.value}`"
            class="relative min-h-[420px] flex-1 overflow-hidden"
            :style="{ backgroundColor: theme.paper }"
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
                @dragmove="
                    onStageDragEnd /* the camera follows the board as it is dragged, not only once it is let go */
                "
                @dragend="onStageDragEnd"
                @pointerdown="onStagePointerDown"
                @pointermove="onStagePointerMove"
                @click="onStageClick"
                @tap="onStageClick"
                @mousedown="onStagePress"
                @touchstart="onStagePress"
                @mouseover="onStageHover"
                @mouseout="onStageHover"
                @dblclick="onDouble"
                @dbltap="onDouble"
                @contextmenu="onContextMenu"
            >
                <Layer ref="contentLayer">
                    <BoardNode
                        v-for="item in drawn"
                        :key="item.id"
                        :item="item"
                        :selected="isSelected(item)"
                        :editing="editingId === item.id"
                        :can-drag="
                            !presenting &&
                            tool === 'select' &&
                            !spaceHeld &&
                            !pen.editing.value
                        "
                        :image="imageFor(item)"
                        :video="videos.videoFor(item)"
                        :playing="videos.isPlaying(item.id)"
                        :level="signals?.levels.get(item.id)"
                        :lit="signals?.lit.get(item.id)"
                        :worked="
                            worked?.notes.get(item.id)?.text ??
                            worked?.wires.get(item.id)
                        "
                        :path-of="connectorPath"
                        :hops="hops.get(item.id)"
                        :snap="snapWhileDragging"
                    />
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

                    <PenOverlay
                        :preview="pen.preview.value"
                        :scale="camera.scale.value"
                    />

                    <!-- The handles for resizing and turning, bound to
                         whatever is selected -->
                    <Transformer
                        ref="transformerRef"
                        :config="{
                            rotateEnabled: true,
                            // Turning catches lightly at the quarter turns:
                            // square again, for any shape
                            rotationSnaps: [0, 90, 180, 270],
                            rotationSnapTolerance: 6,
                            keepRatio: false,
                            borderStroke: '#6366f1',
                            anchorStroke: '#6366f1',
                            anchorSize: 8,
                        }"
                    />
                </Layer>
            </Stage>

            <!-- Where the others' pointers are, in their colours -->
            <BoardPointers
                v-if="pointers"
                :pointers="pointers.pointers.value"
                :scale="camera.scale.value"
                :position="camera.position.value"
            />

            <BoardFormulae
                :items="board.items.value"
                :camera="camera"
                :editing-id="editingId"
            />

            <BoardVideoControls
                v-if="videoBar"
                :video="videoBar.element"
                :playing="videos.isPlaying(videoBar.item.id)"
                :style="videoBar.style"
                @toggle="videos.toggle(videoBar.item.id)"
                @expand="expandVideo(videoBar.item)"
            />

            <!-- Typing happens in a real textarea laid over the canvas -->
            <textarea
                v-if="editingItem"
                ref="editor"
                v-model="editorText"
                class="board-editor"
                :style="[editorStyle, { color: theme.inkOn(editingItem) }]"
                data-test="text-editor"
                @blur="stopEditing()"
                @keydown.enter.exact.prevent="stopEditing()"
            />

            <!-- What a right-click on a frame offers, where it was clicked -->
            <FrameMenu
                v-if="pictureOfFrame"
                v-model="frameMenu"
                :picture-of-frame="pictureOfFrame"
            />

            <!-- The tools, along the bottom middle so the sides keep the panels -->
            <div
                v-show="!presenting"
                class="board-float board-tools bottom-3 left-1/2 -translate-x-1/2"
            >
                <ShapeLibrary
                    :tool="tool"
                    :can-undo="board.canUndo.value"
                    :can-redo="board.canRedo.value"
                    @update:tool="tool = $event"
                    @add-picture="startImport('image')"
                    @add-video="startImport('video')"
                    :compact="compact"
                    :has-schema="hasSchema"
                    @circuit-text="writingCircuit = true"
                    @templates="choosingTemplate = true"
                    @schema="readingSchema = true"
                    @undo="board.undo"
                    @redo="board.redo"
                />
            </div>

            <!-- The stack, grouped by frame, down the left; put away, it
                 leaves an icon in the corner to bring it back -->
            <div
                v-show="!presenting && layersOpen"
                class="board-float"
                :class="compact ? 'board-sheet' : 'top-3 left-3'"
            >
                <LayersPanel
                    :items="board.items.value"
                    :selection="board.selection.value"
                    @select="pickLayer"
                    @move="board.moveTo($event.id, $event.index)"
                    @toggle="board.toggle($event.id, $event.field)"
                    @close="layersOpen = false"
                />
            </div>

            <button
                v-show="!presenting && !layersOpen"
                type="button"
                class="board-pill board-corner top-3 left-3"
                title="Show the layers"
                data-test="layers-open"
                @click="layersOpen = true"
            >
                <PanelLeftOpen class="size-4" />
            </button>

            <!-- What is selected, and everything about it, down the right -->
            <div
                v-show="!presenting && panelOpen"
                class="board-float"
                :class="compact ? 'board-sheet' : 'top-3 right-3 bottom-16'"
            >
                <InspectorPanel
                    :selection="board.selected.value"
                    :fill="board.selected.value[0]?.fill ?? fillColour"
                    :stroke="board.selected.value[0]?.stroke ?? strokeColour"
                    :item-count="board.items.value.length"
                    :frame-count="board.frames.value.length"
                    :link="connectorLink"
                    :deck="deck"
                    :deck-ready="deckReady"
                    @deck="emit('deck', $event)"
                    @restyle-deck="
                        board.updateEach(
                            slidesRestyled(board.items.value, deck),
                        )
                    "
                    @paint="paintFill"
                    @paint-stroke="paintStroke"
                    @resize="resizeSelection"
                    @duplicate="board.duplicate"
                    @remove="removeSelection"
                    @reorder="board.reorder"
                    @update="board.updateSelected"
                    @restyle="board.updateEach"
                    @restyle-board="
                        board.updateEach(
                            restyled(
                                board.items.value,
                                $event.set,
                                $event.style,
                            ),
                        )
                    "
                    @turn="turnSelection"
                    @close="panelOpen = false"
                />
            </div>

            <button
                v-show="!presenting && !panelOpen"
                type="button"
                class="board-pill board-corner top-3 right-3"
                title="Show the panel"
                data-test="panel-open"
                @click="panelOpen = true"
            >
                <PanelRightOpen class="size-4" />
            </button>

            <!-- How close in, and the way back to everything -->
            <ZoomPill
                v-show="!presenting && !(compact && sheet)"
                :scale="camera.scale.value"
                :compact="compact"
                @zoom="camera.zoomBy($event, { x: width / 2, y: height / 2 })"
                @fit="fitAll()"
            />

            <!-- Presenting: the notes under the slide, and the way through -->
            <PresentControls
                v-if="presenting"
                v-model:notes-shown="notesShown"
                :frame-index="frameIndex"
                :count="board.frames.value.length"
                :current-frame="currentFrame"
                :can-open-presenter="!!presenter && !compact"
                :full-screen="fullScreen"
                @show="showFrame"
                @presenter="openPresenter"
                @full-screen="enterFullScreen"
                @stop="stopPresenting"
            />
        </div>

        <MediaPickerDialog
            v-model:open="importing"
            :kind="importKind"
            destination="the board"
            allow-markup
            @insert="onMediaPicked"
            @markup="addSvg"
            @mermaid="addMermaid"
        />

        <CircuitTextDialog
            v-model:open="writingCircuit"
            @insert="placeCircuit"
        />

        <TemplateDialog
            v-model:open="choosingTemplate"
            @insert="placeTemplate"
        />

        <MediaViewer />
    </div>
</template>

<style scoped>
.board-editor {
    position: absolute;
    z-index: 20;
    padding: 0;
    margin: 0;
    /* The canvas's own font (Konva's default) and spacing, so a label
       doesn't rewrap the moment typing stops */
    font-family: Arial, sans-serif;
    font-weight: 600;
    line-height: 1.3;
    background: transparent;
    border: none;
    outline: 2px solid var(--primary);
    outline-offset: 2px;
    resize: none;
    overflow: hidden;
}

/* The canvas takes every touch itself: left to the browser, a finger or a
   pen drawing on it starts to scroll the page instead, and the stroke stops
   a few points in */
[data-camera] :deep(.konvajs-content) {
    touch-action: none;
    -webkit-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}

/* Anything laid over the canvas: above the stage and the editors on it */
.board-float {
    position: absolute;
    z-index: 30;
    display: flex;
    flex-direction: column;
    min-height: 0;
    max-height: calc(100% - 1.5rem);
}
.board-float > :deep(*) {
    min-height: 0;
    overflow-y: auto;
}

/* The tools never run off the canvas: on a narrow one they are slid along */
.board-tools {
    max-width: calc(100% - 1rem);
}
.board-tools > :deep(*) {
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
}

/* On a phone a panel comes up over the bottom of the board, as wide as it,
   clear of the tools */
.board-sheet {
    right: 0.5rem;
    bottom: 4rem;
    left: 0.5rem;
    max-height: min(55%, 24rem);
}
.board-sheet > :deep(*) {
    width: 100%;
}

.board-corner {
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    color: var(--muted-foreground);
    cursor: pointer;
}
.board-corner:hover {
    color: var(--foreground);
}
</style>

<!-- What the pieces laid over the canvas share (ZoomPill, PresentControls),
     which a scoped style would not reach -->
<style>
.board-pill {
    position: absolute;
    z-index: 30;
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 0.25rem;
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: 0 4px 16px -4px rgb(15 23 42 / 0.14);
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
.board-zoom.is-on {
    color: var(--primary);
}
.board-zoom:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>
