<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import type Konva from 'konva';
import { computed, ref, useTemplateRef, watch } from 'vue';
import { Group, Layer, Stage } from 'vue-konva';
import { connectorPoints } from '../../composables/board/connectors';
import { boundsOf, boundsOfAll } from '../../composables/board/geometry';
import type { Item } from '../../composables/board/items';
import { hydrate, isConnector } from '../../composables/board/items';
import { groupKeys } from '../../composables/board/layers';
import { useImageCache } from '../../composables/board/useImageCache';
import { useVideos } from '../../composables/board/useVideos';
import BoardFormulae from './BoardFormulae.vue';
import BoardItem from './BoardItem.vue';

/** How much room a frame's title needs above it, in board units. */
const TITLE_ROOM = 34;

// A board shown somewhere that is not its own page: in a note, say. It draws
// with the same component the canvas does, so a board looks the same wherever
// it is read, and nothing here can be moved or edited -- though a video on it
// still plays when clicked.
const props = withDefaults(
    defineProps<{
        items: Item[];
        /** A frame's id to show on its own; everything, when left out. */
        frame?: string | null;
        padding?: number;
        /** The frame alone, edge to edge, with no room for its title: a slide. */
        bare?: boolean;
    }>(),
    { frame: null, padding: 16, bare: false },
);

const wrapper = useTemplateRef<HTMLDivElement>('wrapper');
const { width, height } = useElementSize(wrapper);

// Items saved by an older board, or written by a client, may be missing fields
const drawn = computed(() => props.items.map((item) => hydrate(item)));

const byId = computed(
    () => new Map(drawn.value.map((item) => [item.id, item])),
);

const shown = computed(() => {
    const visible = drawn.value.filter((item) => !item.hidden);

    if (!props.frame) {
        return visible;
    }

    // A connector has no box of its own, so what belongs to a frame is worked
    // out the same way the layers list works it out: by what each line joins.
    const homes = groupKeys(drawn.value);

    return visible.filter(
        (item) => item.id === props.frame || homes.get(item.id) === props.frame,
    );
});

/** The box to fit: one frame, or everything on the board. */
const extent = computed(() => {
    const frame = props.frame
        ? drawn.value.find((item) => item.id === props.frame)
        : null;

    if (!frame) {
        // A connector sits at the origin until its ends are read, and a board
        // fitted around that would be squeezed into a corner
        const box = boundsOfAll(
            shown.value.filter((item) => !isConnector(item)),
        );

        // Frames have their titles written above them: leave room for those
        return box && shown.value.some((item) => item.kind === 'frame')
            ? { ...box, y: box.y - TITLE_ROOM, height: box.height + TITLE_ROOM }
            : box;
    }

    // A frame's title is written above it, so leave room for it
    const box = boundsOf(frame);

    if (props.bare) {
        return box;
    }

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

// The view as a camera, for the formulae laid over it
const camera = {
    scale: computed(() => view.value.scale),
    position: computed(() => ({ x: view.value.x, y: view.value.y })),
};

const layer = useTemplateRef<{ getNode: () => Konva.Layer }>('layer');
const hovering = ref(false);
const videos = useVideos(shown, () => layer.value?.getNode());

// Only a video hears the pointer, so whatever the layer hears is one: a click
// plays it. Listened for on the layer, as the canvas does -- vue-konva can't
// take listeners on a group.
watch(
    () => layer.value?.getNode(),
    (node) => {
        node?.on('click tap', (event) => {
            const id = event.target.getParent()?.id();

            if (id) {
                videos.toggle(id);
            }
        });
        node?.on('mouseover', () => (hovering.value = true));
        node?.on('mouseout', () => (hovering.value = false));
    },
);
const empty = computed(() => shown.value.length === 0);
const path = (item: Item) => connectorPoints(item, byId.value);
</script>

<template>
    <div
        ref="wrapper"
        class="board-view"
        :class="{ 'cursor-pointer': hovering }"
        data-test="board-view"
    >
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
            }"
        >
            <Layer ref="layer">
                <Group
                    v-for="item in shown"
                    :key="item.id"
                    :config="{
                        id: item.id,
                        x: item.x,
                        y: item.y,
                        rotation: item.rotation,
                        width: item.width,
                        height: item.height,
                        listening: item.kind === 'video',
                    }"
                >
                    <BoardItem
                        :item="item"
                        :selected="false"
                        :editing="false"
                        :image="imageFor(item)"
                        :video="videos.videoFor(item)"
                        :playing="videos.isPlaying(item.id)"
                        :path="path(item)"
                    />
                </Group>
            </Layer>
        </Stage>

        <BoardFormulae v-if="!empty" :items="shown" :camera="camera" />
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
