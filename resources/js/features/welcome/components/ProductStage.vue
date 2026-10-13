<script setup lang="ts">
// The app at work, shown rather than told: a window that turns from a note
// to a board, a table, a workflow and a trip by itself, and to whichever
// one is picked. It stops turning while it is pointed at, or kept on one.
import { usePreferredReducedMotion } from '@vueuse/core';
import {
    MapPinned,
    NotebookPen,
    Presentation,
    Table2,
    Workflow,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import SceneBoard from './SceneBoard.vue';
import SceneNote from './SceneNote.vue';
import SceneTable from './SceneTable.vue';
import SceneTrip from './SceneTrip.vue';
import SceneWorkflow from './SceneWorkflow.vue';

const SCENES: {
    key: string;
    label: string;
    icon: Component;
    scene: Component;
    says: string;
}[] = [
    {
        key: 'notes',
        label: 'Notes',
        icon: NotebookPen,
        scene: SceneNote,
        says: 'A note with a task list, being ticked off by someone else as you watch.',
    },
    {
        key: 'boards',
        label: 'Boards',
        icon: Presentation,
        scene: SceneBoard,
        says: 'A whiteboard of sticky notes joined by connectors, inside a frame.',
    },
    {
        key: 'tables',
        label: 'Tables',
        icon: Table2,
        scene: SceneTable,
        says: 'A table of tasks with an owner, a status, a date and a priority.',
    },
    {
        key: 'workflows',
        label: 'Workflows',
        icon: Workflow,
        scene: SceneWorkflow,
        says: 'A workflow that answers an Instagram message with AI and notes it in a table.',
    },
    {
        key: 'trips',
        label: 'Trips',
        icon: MapPinned,
        scene: SceneTrip,
        says: 'A day of a trip: its stops in order, and the route between them on a map.',
    },
];

/** How long each is shown before the next. */
const SECONDS = 6;

const at = ref(0);
// Each showing starts its scene afresh, so what moves in it moves again
const showing = ref(0);
const held = ref(false);
const still = usePreferredReducedMotion();
const turning = computed(() => !held.value && still.value !== 'reduce');
const current = computed(() => SCENES[at.value]);

let timer: number | undefined;

function show(index: number): void {
    at.value = (index + SCENES.length) % SCENES.length;
    showing.value++;
}

function pick(index: number): void {
    show(index);
    held.value = true;
}

onMounted(() => {
    timer = window.setInterval(() => {
        if (turning.value && !document.hidden) {
            show(at.value + 1);
        }
    }, SECONDS * 1000);
});

onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div
        class="bg-card border-border/80 overflow-hidden rounded-2xl border text-left shadow-2xl shadow-black/10"
        data-welcome-stage
        @mouseenter="held = true"
        @mouseleave="held = false"
    >
        <!-- the window's bar -->
        <div
            class="border-border/70 bg-muted/40 flex items-center gap-3 border-b px-2 py-2 sm:px-4 sm:py-2.5"
        >
            <span class="hidden gap-1.5 sm:flex" aria-hidden="true">
                <span class="bg-chart-5/70 size-2.5 rounded-full" />
                <span class="bg-chart-3/70 size-2.5 rounded-full" />
                <span class="bg-chart-4/70 size-2.5 rounded-full" />
            </span>
            <div
                class="flex min-w-0 flex-1 gap-1 overflow-x-auto"
                role="tablist"
                aria-label="What the app does"
            >
                <button
                    v-for="(scene, index) in SCENES"
                    :key="scene.key"
                    type="button"
                    role="tab"
                    class="relative flex min-h-10 min-w-10 shrink-0 items-center justify-center gap-1.5 overflow-hidden rounded-md px-2.5 text-sm transition-colors sm:min-h-9"
                    :class="
                        index === at
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-selected="index === at"
                    :data-welcome-tab="scene.key"
                    @click="pick(index)"
                >
                    <component
                        :is="scene.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <!-- on a phone only the one that is open says its name: all five stay in reach -->
                    <span :class="{ 'sr-only sm:not-sr-only': index !== at }">{{
                        scene.label
                    }}</span>
                    <!-- how long until the next -->
                    <span
                        v-if="index === at && turning"
                        :key="showing"
                        class="welcome-count bg-primary absolute inset-x-0 bottom-0 h-0.5 origin-left"
                        :style="{ animationDuration: `${SECONDS}s` }"
                        aria-hidden="true"
                    />
                </button>
            </div>
        </div>

        <div class="relative h-92 sm:h-80" role="tabpanel">
            <p class="sr-only">{{ current.says }}</p>
            <Transition name="welcome-scene" mode="out-in">
                <component
                    :is="current.scene"
                    :key="showing"
                    aria-hidden="true"
                />
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.welcome-count {
    animation: stage-count linear both;
}

@keyframes stage-count {
    from {
        transform: scaleX(0);
    }

    to {
        transform: scaleX(1);
    }
}

.welcome-scene-enter-active,
.welcome-scene-leave-active {
    transition:
        opacity 0.18s ease,
        transform 0.18s ease;
}

.welcome-scene-enter-from {
    opacity: 0;
    transform: translateY(6px);
}

.welcome-scene-leave-to {
    opacity: 0;
}
</style>
