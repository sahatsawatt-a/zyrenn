<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useElementSize, useEventListener } from '@vueuse/core';
import type Konva from 'konva';
import { computed, ref, useTemplateRef, watch } from 'vue';
// Registered locally rather than through app.use(VueKonva): the plugin would put
// every Konva shape in the main bundle for the sake of this one page.
import {
    Circle,
    Group,
    Layer,
    Line,
    Rect,
    Stage,
    Text,
    Transformer,
} from 'vue-konva';

type NodeBox = {
    id: string;
    x: number;
    y: number;
    width: number;
    height: number;
    label: string;
    tone: string;
};

type Edge = { from: string; to: string };

// ------------------------------------------------------------------ The scene
const tones = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444'];

let nextId = 0;
const newId = () => `n${++nextId}`;

const boxes = ref<NodeBox[]>([]);
const edges = ref<Edge[]>([]);

const seed = () => {
    nextId = 0;
    boxes.value = [
        {
            id: newId(),
            x: 80,
            y: 120,
            width: 190,
            height: 90,
            label: 'Upload',
            tone: tones[0],
        },
        {
            id: newId(),
            x: 360,
            y: 60,
            width: 190,
            height: 90,
            label: 'Extract text',
            tone: tones[1],
        },
        {
            id: newId(),
            x: 360,
            y: 220,
            width: 190,
            height: 90,
            label: 'Make thumbnail',
            tone: tones[2],
        },
        {
            id: newId(),
            x: 650,
            y: 140,
            width: 190,
            height: 90,
            label: 'Attach to note',
            tone: tones[3],
        },
    ];
    edges.value = [
        { from: 'n1', to: 'n2' },
        { from: 'n1', to: 'n3' },
        { from: 'n2', to: 'n4' },
        { from: 'n3', to: 'n4' },
    ];
    selectedId.value = null;
};

seed();

const boxById = computed(
    () => new Map(boxes.value.map((box) => [box.id, box])),
);

// ------------------------------------------------------- Stage size and view
const canvas = useTemplateRef<HTMLDivElement>('canvas');
const { width, height } = useElementSize(canvas);

const stageRef = useTemplateRef<{ getNode: () => Konva.Stage }>('stageRef');
const stage = () => stageRef.value?.getNode();

const scale = ref(1);
const position = ref({ x: 0, y: 0 });

const stageConfig = computed(() => ({
    width: width.value || 1,
    height: height.value || 1,
    // Dragging the background pans; a draggable node under the pointer wins,
    // so this does not fight node dragging.
    draggable: true,
    scaleX: scale.value,
    scaleY: scale.value,
    x: position.value.x,
    y: position.value.y,
}));

const onWheel = (event: Konva.KonvaEventObject<WheelEvent>) => {
    event.evt.preventDefault();

    const target = stage();
    const pointer = target?.getPointerPosition();

    if (!target || !pointer) {
        return;
    }

    const next = Math.min(
        4,
        Math.max(0.2, scale.value * (event.evt.deltaY > 0 ? 0.9 : 1.1)),
    );

    // Keep the point under the cursor fixed while the scale changes
    const worldX = (pointer.x - position.value.x) / scale.value;
    const worldY = (pointer.y - position.value.y) / scale.value;

    scale.value = next;
    position.value = {
        x: pointer.x - worldX * next,
        y: pointer.y - worldY * next,
    };
};

const onStageDragEnd = (event: Konva.KonvaEventObject<DragEvent>) => {
    if (event.target === stage()) {
        position.value = { x: event.target.x(), y: event.target.y() };
    }
};

const resetView = () => {
    scale.value = 1;
    position.value = { x: 0, y: 0 };
};

const fitView = () => {
    if (!boxes.value.length || !width.value) {
        return;
    }

    const pad = 60;
    const left = Math.min(...boxes.value.map((box) => box.x));
    const top = Math.min(...boxes.value.map((box) => box.y));
    const right = Math.max(...boxes.value.map((box) => box.x + box.width));
    const bottom = Math.max(...boxes.value.map((box) => box.y + box.height));

    const next = Math.min(
        4,
        Math.max(
            0.2,
            Math.min(
                (width.value - pad * 2) / (right - left),
                (height.value - pad * 2) / (bottom - top),
            ),
        ),
    );

    scale.value = next;
    position.value = {
        x: (width.value - (right - left) * next) / 2 - left * next,
        y: (height.value - (bottom - top) * next) / 2 - top * next,
    };
};

// -------------------------------------------------------------- Selection
const selectedId = ref<string | null>(null);
const transformerRef = useTemplateRef<{ getNode: () => Konva.Transformer }>(
    'transformerRef',
);

// The Transformer takes Konva nodes, not state, so it is wired up by hand
// whenever the selection changes.
watch(
    [selectedId, boxes],
    () => {
        const transformer = transformerRef.value?.getNode();
        const target = selectedId.value
            ? stage()?.findOne(`#${selectedId.value}`)
            : null;

        transformer?.nodes(target ? [target] : []);
    },
    { flush: 'post' },
);

const onNodeClick = (id: string, event: Konva.KonvaEventObject<MouseEvent>) => {
    // Shift-click a second node to connect them, or to break an existing link
    if (event.evt.shiftKey && selectedId.value && selectedId.value !== id) {
        toggleEdge(selectedId.value, id);

        return;
    }

    selectedId.value = id;
};

const onBackgroundClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
    if (event.target === stage()) {
        selectedId.value = null;
    }
};

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        selectedId.value = null;
    }

    if (
        (event.key === 'Delete' || event.key === 'Backspace') &&
        selectedId.value
    ) {
        // Not while typing in the toolbar
        if (document.activeElement instanceof HTMLInputElement) {
            return;
        }

        event.preventDefault();
        removeSelected();
    }
});

// ------------------------------------------------------------------ Editing
const onNodeDragMove = (
    id: string,
    event: Konva.KonvaEventObject<DragEvent>,
) => {
    const box = boxById.value.get(id);

    if (box) {
        // Written back on every frame so the edges follow the node as it moves
        box.x = event.target.x();
        box.y = event.target.y();
    }
};

const onTransformEnd = (id: string, event: Konva.KonvaEventObject<Event>) => {
    const box = boxById.value.get(id);
    const group = event.target;

    if (!box) {
        return;
    }

    // Konva resizes by scaling; fold that back into width/height and keep the
    // group at scale 1 so the label and corner radius are not stretched.
    box.x = group.x();
    box.y = group.y();
    box.width = Math.max(90, group.width() * group.scaleX());
    box.height = Math.max(50, group.height() * group.scaleY());

    group.scaleX(1);
    group.scaleY(1);
};

const addNode = () => {
    const id = newId();

    boxes.value.push({
        id,
        // Dropped near the middle of whatever is currently on screen
        x: Math.round((-position.value.x + width.value / 2) / scale.value) - 95,
        y:
            Math.round((-position.value.y + height.value / 2) / scale.value) -
            45,
        width: 190,
        height: 90,
        label: `Step ${boxes.value.length + 1}`,
        tone: tones[boxes.value.length % tones.length],
    });

    selectedId.value = id;
};

const removeSelected = () => {
    const id = selectedId.value;

    if (!id) {
        return;
    }

    boxes.value = boxes.value.filter((box) => box.id !== id);
    edges.value = edges.value.filter(
        (edge) => edge.from !== id && edge.to !== id,
    );
    selectedId.value = null;
};

const toggleEdge = (from: string, to: string) => {
    const existing = edges.value.findIndex(
        (edge) =>
            (edge.from === from && edge.to === to) ||
            (edge.from === to && edge.to === from),
    );

    if (existing >= 0) {
        edges.value.splice(existing, 1);

        return;
    }

    edges.value.push({ from, to });
};

// A rough measure of how much this renderer can carry
const addMany = () => {
    const start = boxes.value.length;

    for (let index = 0; index < 250; index++) {
        const id = newId();

        boxes.value.push({
            id,
            x: 60 + (index % 25) * 130,
            y: 400 + Math.floor(index / 25) * 110,
            width: 110,
            height: 70,
            label: `#${start + index + 1}`,
            tone: tones[index % tones.length],
        });
    }
};

// ------------------------------------------------------------------- Edges
// Cubic curve out of the right edge of one node and into the left of the next,
// which reads better than a straight line when nodes sit side by side.
const edgePoints = (edge: Edge): number[] => {
    const from = boxById.value.get(edge.from);
    const to = boxById.value.get(edge.to);

    if (!from || !to) {
        return [];
    }

    const startX = from.x + from.width;
    const startY = from.y + from.height / 2;
    const endX = to.x;
    const endY = to.y + to.height / 2;
    const bend = Math.max(40, Math.abs(endX - startX) / 2);

    return [
        startX,
        startY,
        startX + bend,
        startY,
        endX - bend,
        endY,
        endX,
        endY,
    ];
};
</script>

<template>
    <Head title="Konva canvas" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Konva canvas</h1>
                <p class="text-muted-foreground text-sm">
                    Drag a node to move it, click to select and resize,
                    shift-click a second node to link or unlink. Scroll to zoom,
                    drag the background to pan, Delete to remove.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button class="demo-btn" @click="addNode">Add node</button>
                <button
                    class="demo-btn"
                    :disabled="!selectedId"
                    @click="removeSelected"
                >
                    Delete
                </button>
                <button class="demo-btn" @click="fitView">Fit</button>
                <button class="demo-btn" @click="resetView">100%</button>
                <button class="demo-btn" @click="addMany">+250 nodes</button>
                <button class="demo-btn" @click="seed">Reset</button>
            </div>
        </div>

        <div
            ref="canvas"
            class="border-sidebar-border/70 dark:border-sidebar-border bg-muted/30 relative min-h-[420px] flex-1 overflow-hidden rounded-xl border"
        >
            <Stage
                ref="stageRef"
                :config="stageConfig"
                @wheel="onWheel"
                @dragend="onStageDragEnd"
                @mousedown="onBackgroundClick"
                @touchstart="onBackgroundClick"
            >
                <Layer>
                    <Line
                        v-for="edge in edges"
                        :key="`${edge.from}-${edge.to}`"
                        :config="{
                            points: edgePoints(edge),
                            stroke: '#94a3b8',
                            strokeWidth: 2,
                            bezier: true,
                            listening: false,
                        }"
                    />
                </Layer>

                <Layer>
                    <Group
                        v-for="box in boxes"
                        :key="box.id"
                        :config="{
                            id: box.id,
                            x: box.x,
                            y: box.y,
                            width: box.width,
                            height: box.height,
                            draggable: true,
                        }"
                        @dragmove="onNodeDragMove(box.id, $event)"
                        @transformend="onTransformEnd(box.id, $event)"
                        @click="onNodeClick(box.id, $event)"
                        @tap="onNodeClick(box.id, $event)"
                    >
                        <Rect
                            :config="{
                                width: box.width,
                                height: box.height,
                                fill: 'white',
                                stroke:
                                    box.id === selectedId
                                        ? box.tone
                                        : '#cbd5e1',
                                strokeWidth: box.id === selectedId ? 2 : 1,
                                cornerRadius: 10,
                                shadowColor: 'black',
                                shadowOpacity: 0.08,
                                shadowBlur: 6,
                                shadowOffsetY: 2,
                            }"
                        />
                        <Rect
                            :config="{
                                width: box.width,
                                height: 6,
                                fill: box.tone,
                                cornerRadius: [10, 10, 0, 0],
                            }"
                        />
                        <Text
                            :config="{
                                text: box.label,
                                x: 14,
                                y: box.height / 2 - 9,
                                width: box.width - 28,
                                fontSize: 14,
                                fontStyle: '600',
                                fill: '#0f172a',
                                ellipsis: true,
                                wrap: 'none',
                                listening: false,
                            }"
                        />
                        <Circle
                            :config="{
                                x: box.width,
                                y: box.height / 2,
                                radius: 4,
                                fill: box.tone,
                                listening: false,
                            }"
                        />
                        <Circle
                            :config="{
                                x: 0,
                                y: box.height / 2,
                                radius: 4,
                                fill: '#cbd5e1',
                                listening: false,
                            }"
                        />
                    </Group>

                    <Transformer
                        ref="transformerRef"
                        :config="{
                            rotateEnabled: false,
                            keepRatio: false,
                            borderStroke: '#6366f1',
                            anchorStroke: '#6366f1',
                            anchorSize: 8,
                            enabledAnchors: [
                                'top-left',
                                'top-right',
                                'bottom-left',
                                'bottom-right',
                            ],
                        }"
                    />
                </Layer>
            </Stage>

            <p
                class="bg-background/80 text-muted-foreground absolute right-3 bottom-3 rounded-md px-2 py-1 text-xs"
            >
                {{ boxes.length }} nodes · {{ edges.length }} links ·
                {{ Math.round(scale * 100) }}%
            </p>
        </div>
    </div>
</template>

<style scoped>
.demo-btn {
    height: 2rem;
    padding: 0 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.demo-btn:hover:not(:disabled) {
    background-color: var(--muted);
}
.demo-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
