<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    ClipboardCopy,
    Download,
    HardDrive,
    Maximize,
    Minus,
    PanelLeftOpen,
    PanelRightOpen,
    Play,
    Plus,
    X,
} from '@lucide/vue';
import type { HocuspocusProvider } from '@hocuspocus/provider';
import {
    useDebounceFn,
    useElementSize,
    useEventListener,
    useLocalStorage,
} from '@vueuse/core';
import Konva from 'konva';
import type { Ref } from 'vue';
import type * as Y from 'yjs';
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
import BoardNode from './BoardNode.vue';
import InspectorPanel from './InspectorPanel.vue';
import LayersPanel from './LayersPanel.vue';
import { isImageFile, uploadToDrive } from '@/lib/drive';
import MediaPickerDialog from '@/components/media/MediaPickerDialog.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { deliver } from '@/lib/exporting';
import { toast } from 'vue-sonner';
import MediaViewer from '@/components/MediaViewer.vue';
import { useMediaViewer } from '@/composables/useMediaViewer';
import BoardOverlay from './BoardOverlay.vue';
import BoardFormulae from './BoardFormulae.vue';
import BoardShortcuts from './BoardShortcuts.vue';
import BoardVideoControls from './BoardVideoControls.vue';
import ShapeLibrary from './ShapeLibrary.vue';
import {
    attachmentsOf,
    connectorPoints,
} from '../../composables/board/connectors.js';
import type { Guide } from '../../composables/board/connectors.js';
import {
    anchorsOf,
    boundsOf,
    boundsOfAll,
    fitOnBoard,
    overlaps,
} from '../../composables/board/geometry.js';
import { alignmentFor } from '../../composables/board/guides.js';
import {
    hasText,
    isConnectable,
    isConnector,
    isStroke,
} from '../../composables/board/items.js';
import type { Item, Side, Tool } from '../../composables/board/items.js';
import { svgSource } from '../../composables/board/pictures.js';
import {
    FRAME_TITLE,
    useLabelEditor,
} from '../../composables/board/useLabelEditor.js';
import { usePresenting } from '../../composables/board/usePresenting.js';
import { useConnectorEnds } from '../../composables/board/useConnectorEnds.js';
import { useDrawing } from '../../composables/board/useDrawing.js';
import { useShortcuts } from '../../composables/board/useShortcuts.js';
import { usePictures } from '../../composables/board/usePictures.js';
import { VIDEO_PLAY, useVideos } from '../../composables/board/useVideos.js';
import { useBoard } from '../../composables/board/useBoard.js';
import { useCamera } from '../../composables/board/useCamera.js';
import {
    useBoardPointers,
    useBoardSync,
} from '../../composables/board/useBoardSync.js';

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
    }>(),
    {
        items: null,
        title: '',
        status: '',
        shared: null,
        pictureOfFrame: null,
    },
);

const emit = defineEmits<{ change: [Item[]] }>();

const board = useBoard(props.items);

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
} = usePresenting({
    board,
    camera,
    width,
    height,
    screen: () => page.value,
    overview: (options) => fitAll(options),
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

// The handles follow their nodes as those move or resize by themselves; what
// they are bound to only changes with the selection or what is on the board
watch(
    [
        board.selection,
        () => board.items.value.map((item) => item.id).join(),
        presenting,
        editingId,
    ],
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
    { flush: 'post' },
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

// A drag that wanders over a panel laid on the canvas still belongs to the
// canvas: without this, letting go over one is never heard, and a marquee or a
// half-drawn shape is left hanging from the pointer
const onStagePointerDown = (event: Konva.KonvaEventObject<PointerEvent>) => {
    onPointerDown(event);

    try {
        stage()?.content.setPointerCapture(event.evt.pointerId);
    } catch {
        // a pointer already gone: nothing to hold on to
    }
};

// --------------------------------------------------- A frame, right-clicked
const frameMenu = ref<{ frame: Item; x: number; y: number } | null>(null);
const frameMenuOpen = computed({
    get: () => frameMenu.value !== null,
    set: (open) => {
        if (!open) frameMenu.value = null;
    },
});

// Copying a picture is a newer thing than downloading one
const canCopyPicture =
    typeof window !== 'undefined' &&
    'ClipboardItem' in window &&
    !!navigator.clipboard?.write;

/** The frame a board point is on -- the topmost, where frames overlap. */
const frameAt = (point: { x: number; y: number }): Item | null => {
    for (let index = board.items.value.length - 1; index >= 0; index--) {
        const item = board.items.value[index];

        if (
            item.kind === 'frame' &&
            !item.hidden &&
            overlaps(boundsOf(item), { ...point, width: 1, height: 1 })
        ) {
            return item;
        }
    }

    return null;
};

// Anywhere on a frame -- its title, its empty middle, or something sitting
// on it -- is the frame, for this menu; anywhere else, the browser's own
const onContextMenu = (event: Konva.KonvaEventObject<PointerEvent>) => {
    const point = stage()?.getPointerPosition();

    if (!props.pictureOfFrame || presenting.value || !point) {
        return;
    }

    const frame = frameAt(camera.toBoard(point));

    if (!frame) {
        return;
    }

    event.evt.preventDefault();
    frameMenu.value = { frame, x: point.x, y: point.y };
};

const frameName = (frame: Item) => frame.text?.trim() || 'Frame';

async function saveFrame(to: 'download' | 'drive' | 'copy'): Promise<void> {
    const frame = frameMenu.value?.frame;
    const draw = props.pictureOfFrame;

    if (!frame || !draw) {
        return;
    }

    const loading = toast.loading(`Drawing “${frameName(frame)}”…`);

    try {
        if (to === 'copy') {
            // Handed the drawing still to come, so the copy keeps the click
            // that asked for it while the server draws
            await navigator.clipboard.write([
                new ClipboardItem({ 'image/png': draw(frame) }),
            ]);
            toast.success(`Copied “${frameName(frame)}” as a picture`);
        } else {
            await deliver(await draw(frame), to);
        }
    } catch (error) {
        toast.error((error as Error).message);
    } finally {
        toast.dismiss(loading);
    }
}

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
// The panels on the right and on the left, open or put away; remembered across
// boards, since it is how a person likes to work rather than something about
// this board
const panelOpen = useLocalStorage('board.panel', true);
const layersOpen = useLocalStorage('board.layers', true);

// The part of the canvas the floating panels and the tool bar leave uncovered
const uncovered = () => ({
    left: layersOpen.value ? 256 : 0,
    right: panelOpen.value ? 280 : 0,
    bottom: 56,
});

const fitAll = ({ animate = false } = {}) =>
    camera.focus(boundsOfAll(board.items.value), {
        animate,
        padding: 48,
        inset: uncovered(),
    });

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

// -------------------------------------------------------------------- Videos
// A hidden video is let go of, so it does not play on where nobody sees it
const videos = useVideos(
    computed(() => board.items.value.filter((item) => !item.hidden)),
    () => contentLayer.value?.getNode(),
);

const viewer = useMediaViewer();

/** The video item a click landed on, if it landed on one. */
const videoAt = (event: Konva.KonvaEventObject<MouseEvent>) => {
    const id = event.target.id() || event.target.getParent()?.id();
    const item = id ? board.byId.value.get(id) : undefined;

    return item?.kind === 'video' ? item : undefined;
};

// A click on a video's play button plays it, and so does any click on one
// while presenting. Clicking the selected video pauses it; otherwise a click
// is the usual select.
const onClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
    const video = videoAt(event);

    if (video && tool.value === 'select') {
        const chosen =
            board.selection.value.length === 1 &&
            board.selection.value[0] === video.id;

        if (
            presenting.value ||
            event.target.name() === VIDEO_PLAY ||
            (chosen && videos.isPlaying(video.id))
        ) {
            videos.toggle(video.id);
        }
    }

    onItemClick(event);
};

/** Watch a video full size, from wherever it had got to. */
const expandVideo = (item: Item) => {
    const element = videos.elementOf(item.id);

    videos.pause(item.id);
    viewer.open([
        {
            type: 'video',
            src: item.src,
            start: element?.currentTime,
        },
    ]);
};

// Double-clicking a video watches it full size; anything else is labelled
const onDoubleClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
    const video = videoAt(event);

    if (video) {
        expandVideo(video);

        return;
    }

    onItemDoubleClick(event);
};

/** The one selected video, and where its controls go: just under it. */
const videoBar = computed(() => {
    const item = board.selected.value[0];

    if (
        presenting.value ||
        board.selected.value.length !== 1 ||
        item?.kind !== 'video'
    ) {
        return null;
    }

    const element = videos.elementOf(item.id);

    if (!element) {
        return null;
    }

    const scale = camera.scale.value;
    const { x, y } = camera.position.value;

    return {
        item,
        element,
        style: {
            left: `${item.x * scale + x}px`,
            top: `${(item.y + item.height) * scale + y + 10}px`,
            width: `${Math.max(300, item.width * scale)}px`,
        },
    };
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
    // Bound on the transformer itself: Konva announces a transform with
    // node._fire(), which does not bubble, so a layer-level handler never
    // hears it -- which is why a resize only landed when the handle was let go.
    const transformer = transformerRef.value?.getNode();

    // The undo step is taken before the first frame changes anything
    transformer?.on('transformstart', () => board.commit());
    transformer?.on('transform', applyTransform);
    transformer?.on('transformend', () => {
        applyTransform();
        // A frame stretched over things already there would hide them
        board.settle();
    });

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

// Which connectors hang off each shape: only connectors coming and going
// change it, so moving shapes about leaves it be
const attached = computed(() => attachmentsOf(board.items.value));
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
        <div v-show="!presenting" class="board-bar">
            <div class="flex min-w-0 items-center gap-2">
                <slot name="title">
                    <h1 class="truncate px-2 text-sm font-semibold">
                        {{ title || 'Board' }}
                    </h1>
                </slot>
                <span
                    v-if="status"
                    class="text-muted-foreground truncate text-xs"
                    data-test="board-status"
                    >{{ status }}</span
                >
            </div>

            <div class="ml-auto flex shrink-0 items-center gap-1">
                <slot name="actions" />

                <BoardShortcuts />

                <button
                    type="button"
                    class="board-present"
                    :disabled="!board.frames.value.length"
                    :title="
                        board.frames.value.length
                            ? `Present ${board.frames.value.length} frame${board.frames.value.length === 1 ? '' : 's'} as slides`
                            : 'Draw a frame (F) to present it as a slide'
                    "
                    data-test="present"
                    @click="startPresenting"
                >
                    <Play class="size-3.5" />
                    Present
                </button>
            </div>
        </div>

        <div
            ref="canvas"
            :data-zoom="Math.round(camera.scale.value * 100)"
            @dragover.prevent
            @drop="onDropFiles"
            @pointermove="onCanvasPointer"
            @pointerleave="pointers?.point(null)"
            :data-camera="`${camera.position.value.x},${camera.position.value.y},${camera.scale.value}`"
            class="relative min-h-[420px] flex-1 overflow-hidden bg-white"
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
                @pointerdown="onStagePointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @click="onClick"
                @dblclick="onDoubleClick"
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
                            !presenting && tool === 'select' && !spaceHeld
                        "
                        :image="imageFor(item)"
                        :video="videos.videoFor(item)"
                        :playing="videos.isPlaying(item.id)"
                        :path-of="connectorPath"
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

            <!-- Where the others' pointers are, in their colours -->
            <div
                v-for="pointer in pointers?.pointers.value ?? []"
                :key="pointer.id"
                class="pointer-events-none absolute top-0 left-0 z-10 flex items-start transition-transform duration-75"
                :style="{
                    transform: `translate(${pointer.x * camera.scale.value + camera.position.value.x}px, ${pointer.y * camera.scale.value + camera.position.value.y}px)`,
                }"
                data-test="board-pointer"
            >
                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 16 16"
                    aria-hidden="true"
                >
                    <path
                        d="M1 1l5.5 13 2-5.5L14 6.5z"
                        :fill="pointer.color"
                        stroke="white"
                        stroke-width="1.2"
                        stroke-linejoin="round"
                    />
                </svg>
                <span
                    class="mt-3 rounded px-1.5 py-0.5 text-[11px] font-semibold whitespace-nowrap text-white"
                    :style="{ backgroundColor: pointer.color }"
                    >{{ pointer.name }}</span
                >
            </div>

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
                :style="editorStyle"
                data-test="text-editor"
                @blur="stopEditing()"
                @keydown.enter.exact.prevent="stopEditing()"
            />

            <!-- What a right-click on a frame offers, where it was clicked -->
            <DropdownMenu v-model:open="frameMenuOpen" :modal="false">
                <DropdownMenuTrigger as-child>
                    <span
                        class="pointer-events-none absolute size-0"
                        :style="{
                            left: `${frameMenu?.x ?? 0}px`,
                            top: `${frameMenu?.y ?? 0}px`,
                        }"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    class="w-56"
                    data-test="frame-menu"
                >
                    <DropdownMenuLabel class="truncate">
                        {{ frameMenu ? frameName(frameMenu.frame) : '' }}
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        data-test="frame-download"
                        @select="saveFrame('download')"
                    >
                        <Download />
                        Download picture
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canCopyPicture"
                        data-test="frame-copy"
                        @select="saveFrame('copy')"
                    >
                        <ClipboardCopy />
                        Copy picture
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        data-test="frame-drive"
                        @select="saveFrame('drive')"
                    >
                        <HardDrive />
                        Save picture to Drive
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <!-- The tools, along the bottom middle so the sides keep the panels -->
            <div
                v-show="!presenting"
                class="board-float bottom-3 left-1/2 -translate-x-1/2"
            >
                <ShapeLibrary
                    :tool="tool"
                    :can-undo="board.canUndo.value"
                    :can-redo="board.canRedo.value"
                    @update:tool="tool = $event"
                    @add-picture="startImport('image')"
                    @add-video="startImport('video')"
                    @undo="board.undo"
                    @redo="board.redo"
                />
            </div>

            <!-- The stack, grouped by frame, down the left; put away, it
                 leaves an icon in the corner to bring it back -->
            <div
                v-show="!presenting && layersOpen"
                class="board-float top-3 left-3"
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
                class="board-float top-3 right-3 bottom-16"
            >
                <InspectorPanel
                    :selection="board.selected.value"
                    :fill="fillColour"
                    :stroke="strokeColour"
                    :item-count="board.items.value.length"
                    :frame-count="board.frames.value.length"
                    :link="connectorLink"
                    @paint="paintFill"
                    @paint-stroke="paintStroke"
                    @resize="resizeSelection"
                    @duplicate="board.duplicate"
                    @remove="removeSelection"
                    @reorder="board.reorder"
                    @update="board.updateSelected"
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
            <div v-show="!presenting" class="board-pill right-3 bottom-3">
                <button
                    type="button"
                    class="board-zoom"
                    title="Zoom out"
                    data-test="zoom-out"
                    @click="camera.zoomBy(0.8, { x: width / 2, y: height / 2 })"
                >
                    <Minus class="size-3.5" />
                </button>
                <button
                    type="button"
                    class="board-zoom w-12 text-xs tabular-nums"
                    data-test="zoom"
                    title="Fit everything"
                    @click="fitAll()"
                >
                    {{ Math.round(camera.scale.value * 100) }}%
                </button>
                <button
                    type="button"
                    class="board-zoom"
                    title="Zoom in"
                    data-test="zoom-in"
                    @click="
                        camera.zoomBy(1.25, { x: width / 2, y: height / 2 })
                    "
                >
                    <Plus class="size-3.5" />
                </button>
                <span class="bg-border mx-0.5 h-4 w-px" />
                <button
                    type="button"
                    class="board-zoom"
                    title="Fit everything"
                    data-test="fit"
                    @click="fitAll()"
                >
                    <Maximize class="size-3.5" />
                </button>
            </div>

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

        <MediaPickerDialog
            v-model:open="importing"
            :kind="importKind"
            destination="the board"
            allow-markup
            @insert="onMediaPicked"
            @markup="addSvg"
            @mermaid="addMermaid"
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
    color: #0f172a;
    background: transparent;
    border: none;
    outline: 2px solid var(--primary);
    outline-offset: 2px;
    resize: none;
    overflow: hidden;
}

.board-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    height: 3rem;
    flex-shrink: 0;
    padding: 0 0.75rem 0 0.5rem;
    border-bottom: 1px solid var(--border);
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

.board-present {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    height: 2rem;
    margin-left: 0.25rem;
    padding: 0 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.board-present:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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
