<script setup lang="ts">
import { computed } from 'vue';
import { Group } from 'vue-konva';
import type { Item } from '@/features/boards/composables/items';
import { isConnector } from '@/features/boards/composables/items';
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
    pathOf: (item: Item) => number[];
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
    width: props.item.width,
    height: props.item.height,
    // Locked: still drawn, but the pointer goes straight through it
    listening: !props.item.locked,
    // A connector is wherever its ends are: dragging its body would slide the
    // line off the shapes it is pinned to
    draggable: props.canDrag && !isConnector(props.item),
    dragBoundFunc: dragBound.value,
}));
</script>

<template>
    <Group :config="config">
        <BoardItem
            :item="item"
            :selected="selected"
            :editing="editing"
            :image="image"
            :video="video"
            :playing="playing"
            :path="path"
        />
    </Group>
</template>
