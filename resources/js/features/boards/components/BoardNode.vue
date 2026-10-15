<script setup lang="ts">
import type Konva from 'konva';
import { computed, nextTick, ref, watch } from 'vue';
import { Group } from 'vue-konva';
import { blending, cacheBlur } from '@/features/boards/composables/blur';
import type { Item } from '@/features/boards/composables/items';
import { isConnector } from '@/features/boards/composables/items';
import type { Hops } from '@/lib/lineHops';
import BoardItem from './BoardItem.vue';

// One thing on the canvas, in a component of its own so that it is drawn again
// only when it changes: moving the camera, or dragging something else, leaves
// it be. Everything handed in is either the item itself or something that
// stays the same from one draw of the board to the next -- a connector's path
// above all is worked out here, where what it hangs off is followed.
const props = defineProps<{
    item: Item;
    selected: boolean;
    editing: boolean;
    // Whether anything can be picked up just now; a connector never is
    canDrag: boolean;
    image?: HTMLImageElement;
    video?: HTMLVideoElement;
    playing?: boolean;
    // In a logic circuit: whether this wire, input or lamp is high just now
    level?: 0 | 1 | null;
    // On a display: which of its segments are lit
    lit?: string[];
    // What was worked out for it, when that is asked to be shown
    worked?: string;
    pathOf: (item: Item) => number[];
    // Where it hops over the lines it crosses
    hops?: Hops;
    snap: (item: Item) => (position: { x: number; y: number }) => {
        x: number;
        y: number;
    };
}>();

const path = computed(() => props.pathOf(props.item));
const dragBound = computed(() => props.snap(props.item));

const config = computed(() => ({
    id: props.item.id,
    x: props.item.x,
    y: props.item.y,
    rotation: props.item.rotation,
    opacity: props.item.opacity ?? 1,
    ...blending(props.item),
    width: props.item.width,
    height: props.item.height,
    // Locked: still drawn, but the pointer goes straight through it
    listening: !props.item.locked,
    // A connector is wherever its ends are: dragging its body would slide the
    // line off the shapes it is pinned to
    draggable: props.canDrag && !isConnector(props.item),
    dragBoundFunc: dragBound.value,
}));

// A softened item is drawn again whenever anything about it changes
const group = ref<{ getNode: () => Konva.Node } | null>(null);

watch(
    () => [props.item, props.selected, props.image, props.video, props.playing],
    async () => {
        await nextTick();
        cacheBlur(group.value?.getNode(), props.item);
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <Group ref="group" :config="config">
        <!-- Drawn afresh in another design: the canvas puts a part that
             comes and goes on top of the rest, wherever it is written here,
             so a badge's disc would cover its icon -->
        <BoardItem
            :key="item.variant ?? ''"
            :item="item"
            :selected="selected"
            :editing="editing"
            :image="image"
            :video="video"
            :playing="playing"
            :level="level"
            :lit="lit"
            :worked="worked"
            :path="path"
            :hops="hops"
        />
    </Group>
</template>
