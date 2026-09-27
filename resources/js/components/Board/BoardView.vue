<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import { Group, Layer, Stage } from 'vue-konva';
import { connectorPoints } from './connectors';
import { boundsOf, boundsOfAll } from './geometry';
import type { Item } from './items';
import { hydrate } from './items';
import { useImageCache } from './useImageCache';
import BoardItem from './BoardItem.vue';

/** How much room a frame's title needs above it, in board units. */
const TITLE_ROOM = 34;

// A board shown somewhere that is not its own page: in a note, say. It draws
// with the same component the canvas does, so a board looks the same wherever
// it is read, and nothing here can be moved or edited.
const props = withDefaults(
    defineProps<{
        items: Item[];
        /** A frame's id to show on its own; everything, when left out. */
        frame?: string | null;
        padding?: number;
    }>(),
    { frame: null, padding: 16 },
);

const wrapper = useTemplateRef<HTMLDivElement>('wrapper');
const { width, height } = useElementSize(wrapper);

// Items saved by an older board, or written by a client, may be missing fields
const drawn = computed(() => props.items.map((item) => hydrate(item)));

const byId = computed(
    () => new Map(drawn.value.map((item) => [item.id, item])),
);

const shown = computed(() => {
    const frame = props.frame
        ? drawn.value.find((item) => item.id === props.frame)
        : null;

    if (!frame) {
        return drawn.value.filter((item) => !item.hidden);
    }

    const slide = boundsOf(frame);
    const middle = (item: Item) => {
        const box = boundsOf(item);

        return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    };

    // What sits on that frame, and the frame itself behind it
    return drawn.value.filter((item) => {
        if (item.hidden) {
            return false;
        }

        if (item.id === frame.id) {
            return true;
        }

        const point = middle(item);

        return (
            point.x >= slide.x &&
            point.x <= slide.x + slide.width &&
            point.y >= slide.y &&
            point.y <= slide.y + slide.height
        );
    });
});

/** The box to fit: one frame, or everything on the board. */
const extent = computed(() => {
    const frame = props.frame
        ? drawn.value.find((item) => item.id === props.frame)
        : null;

    if (!frame) {
        return boundsOfAll(shown.value);
    }

    // A frame's title is written above it, so leave room for it
    const box = boundsOf(frame);

    return { ...box, y: box.y - TITLE_ROOM, height: box.height + TITLE_ROOM };
});

const view = computed(() => {
    const box = extent.value;

    if (!box || !width.value || !height.value) {
        return { scale: 1, x: 0, y: 0 };
    }

    const room = props.padding * 2;
    const scale = Math.min(
        (width.value - room) / Math.max(box.width, 1),
        (height.value - room) / Math.max(box.height, 1),
    );

    return {
        scale,
        x: (width.value - box.width * scale) / 2 - box.x * scale,
        y: (height.value - box.height * scale) / 2 - box.y * scale,
    };
});

const { imageFor } = useImageCache();
const empty = computed(() => shown.value.length === 0);
const path = (item: Item) => connectorPoints(item, byId.value);
</script>

<template>
    <div ref="wrapper" class="board-view" data-test="board-view">
        <p v-if="empty" class="board-view-empty">Nothing on it yet</p>

        <Stage
            v-else
            :config="{
                width: width || 1,
                height: height || 1,
                scaleX: view.scale,
                scaleY: view.scale,
                x: view.x,
                y: view.y,
                listening: false,
            }"
        >
            <Layer>
                <Group
                    v-for="item in shown"
                    :key="item.id"
                    :config="{
                        x: item.x,
                        y: item.y,
                        rotation: item.rotation,
                        width: item.width,
                        height: item.height,
                    }"
                >
                    <BoardItem
                        :item="item"
                        :selected="false"
                        :editing="false"
                        :image="imageFor(item)"
                        :path="path(item)"
                    />
                </Group>
            </Layer>
        </Stage>
    </div>
</template>

<style scoped>
.board-view {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
    background-color: var(--background);
}

.board-view-empty {
    display: flex;
    height: 100%;
    align-items: center;
    justify-content: center;
    font-size: 0.8125rem;
    color: var(--muted-foreground);
}
</style>
