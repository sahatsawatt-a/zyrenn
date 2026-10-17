<script setup lang="ts">
import { Minus, Plus, Target, Unlink } from '@lucide/vue';
import { Background } from '@vue-flow/background';
import { ControlButton, Controls } from '@vue-flow/controls';
import { MarkerType, VueFlow, useVueFlow } from '@vue-flow/core';
import type { Connection, Edge, Node, NodeChange } from '@vue-flow/core';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import { ref } from 'vue';
import Tip from '@/components/common/Tip.vue';
import { Button } from '@/components/ui/button';
import type { StepData } from '@/features/workflow/canvas';
import type { NodeType, StepStatus } from '@/features/workflow/types';
import HopEdge from './HopEdge.vue';
import StepNode from './StepNode.vue';

// A workflow's steps on a canvas, joined by dragging from a dot on one to
// the next -- or added already joined, from a step's "+". It is the page's
// Vue Flow: the page holds what it draws and what is done to it.

const nodes = defineModel<Node<StepData>[]>('nodes', { required: true });
const edges = defineModel<Edge[]>('edges', { required: true });

defineProps<{
    types: Record<string, NodeType>;
    editable: boolean;
    compact: boolean;
    // The run panel is open under it, so the canvas is short
    panelOpen: boolean;
    // The palette is on show, so it needs no "Add step"
    paletteShown: boolean;
    selectedId: string | null;
    canConnect: (connection: Connection) => boolean;
    problemsOf: (id: string) => string[];
    joinedOf: (id: string) => string[];
    statusOf: (id: string) => StepStatus | undefined;
}>();

const emit = defineEmits<{
    select: [id: string | null];
    nodesChange: [changes: NodeChange[]];
    // A step's "+": the next step goes after that way out of it
    next: [id: string, way: string];
    addStep: [];
    drop: [event: DragEvent];
    unlink: [];
}>();

const { fitView, getSelectedEdges, zoomIn, zoomOut } = useVueFlow();

// What holds it, to find what is in view
const el = ref<HTMLElement | null>(null);

defineExpose({ el });
</script>

<template>
    <div
        ref="el"
        class="relative min-h-0 min-w-0 flex-1"
        data-canvas
        @drop.prevent="emit('drop', $event)"
        @dragover.prevent
    >
        <VueFlow
            v-model:nodes="nodes"
            v-model:edges="edges"
            :default-edge-options="{
                type: 'hop',
                markerEnd: MarkerType.ArrowClosed,
            }"
            :is-valid-connection="canConnect"
            :nodes-draggable="editable"
            :nodes-connectable="editable"
            :edges-updatable="false"
            :delete-key-code="editable ? ['Backspace', 'Delete'] : null"
            :min-zoom="0.2"
            :max-zoom="2"
            @pane-ready="
                fitView({
                    padding: compact ? 0.15 : 0.3,
                    // Small enough to see whole is too small to read
                    minZoom: compact ? 0.5 : undefined,
                    maxZoom: 1,
                })
            "
            class="workflow-canvas"
            @node-click="emit('select', $event.node.id)"
            @pane-click="emit('select', null)"
            @nodes-change="emit('nodesChange', $event)"
        >
            <Background :gap="20" :size="1.2" />
            <!-- Fingers zoom by pinching -->
            <!-- The app's own zoom buttons, each with its word on it -->
            <Controls
                v-if="!compact"
                :show-zoom="false"
                :show-fit-view="false"
                :show-interactive="false"
            >
                <Tip text="Zoom in" side="right">
                    <ControlButton aria-label="Zoom in" @click="zoomIn()">
                        <Plus />
                    </ControlButton>
                </Tip>
                <Tip text="Zoom out" side="right">
                    <ControlButton aria-label="Zoom out" @click="zoomOut()">
                        <Minus />
                    </ControlButton>
                </Tip>
            </Controls>
            <!-- Over the run panel the canvas is short: it would cover the steps -->
            <MiniMap v-if="!panelOpen && !compact" pannable zoomable />

            <template #edge-hop="edge">
                <HopEdge v-bind="edge" />
            </template>

            <template #node-step="step">
                <StepNode
                    :id="step.id"
                    :data="{
                        step: step.data.step,
                        type: types[step.data.step.type],
                        problems: problemsOf(step.id),
                        joined: joinedOf(step.id),
                        editable,
                    }"
                    :selected="step.id === selectedId"
                    :status="statusOf(step.id)"
                    @next="emit('next', step.id, $event)"
                />
            </template>
        </VueFlow>

        <Button
            v-if="editable && !paletteShown"
            size="sm"
            variant="outline"
            class="bg-background/90 absolute top-3 left-3 z-10"
            data-add-step
            @click="emit('addStep')"
        >
            <Plus /> Add step
        </Button>

        <Button
            v-if="editable && getSelectedEdges.length"
            size="sm"
            variant="outline"
            class="bg-background/90 text-destructive hover:text-destructive absolute top-3 left-1/2 z-10 -translate-x-1/2"
            data-unlink
            @click="emit('unlink')"
        >
            <Unlink /> Remove connection
        </Button>

        <Tip text="Fit the workflow in view" side="left">
            <Button
                size="icon-sm"
                variant="outline"
                class="bg-background/90 absolute top-3 right-3 z-10"
                aria-label="Fit the workflow in view"
                @click="fitView({ padding: 0.2, duration: 400 })"
            >
                <Target />
            </Button>
        </Tip>
    </div>
</template>

<style>
.workflow-canvas {
    background: var(--muted);
}

.workflow-canvas .vue-flow__controls,
.workflow-canvas .vue-flow__minimap {
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    overflow: hidden;
    background: var(--card);
}

.workflow-canvas .vue-flow__controls-button {
    background: var(--card);
    border-bottom: 1px solid var(--border);
    fill: var(--foreground);
}

/* The app's own icons: drawn with a line, not filled */
.workflow-canvas .vue-flow__controls-button svg {
    width: 14px;
    height: 14px;
    max-width: none;
    max-height: none;
    fill: none;
    stroke: var(--foreground);
}

.workflow-canvas .vue-flow__edge-path {
    stroke: var(--muted-foreground);
    stroke-width: 1.5;
}

.workflow-canvas .vue-flow__edge.edge-true .vue-flow__edge-path,
.workflow-canvas .vue-flow__edge.edge-approved .vue-flow__edge-path {
    stroke: var(--color-emerald-500);
}

.workflow-canvas .vue-flow__edge.edge-false .vue-flow__edge-path,
.workflow-canvas .vue-flow__edge.edge-rejected .vue-flow__edge-path {
    stroke: var(--color-rose-500);
}

.workflow-canvas .vue-flow__edge.selected .vue-flow__edge-path {
    stroke: var(--primary);
}
</style>
