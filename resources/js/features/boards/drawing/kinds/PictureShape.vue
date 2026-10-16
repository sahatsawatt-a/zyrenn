<script setup lang="ts">
import { Image as KonvaImage, Rect } from 'vue-konva';
import type { Item } from '../../model/items';
import { pictured, useItemLook } from '../itemLook';

// A picture in its box, fitted the way the item says, with a border in its
// line colour; a dashed box until the bitmap has decoded.
const props = defineProps<{
    item: Item;
    selected: boolean;
    image?: HTMLImageElement;
}>();

const { shown } = useItemLook(props);
</script>

<template>
    <template v-if="image">
        <!-- The whole box takes the pointer, room either side included -->
        <Rect
            :config="{
                width: item.width,
                height: item.height,
                fill: 'rgba(0,0,0,0)',
            }"
        />
        <KonvaImage
            :config="{
                image: image,
                ...pictured(item, image),
                cornerRadius: item.radius ?? 0,
            }"
        />
        <!-- A border, in the item's line colour -->
        <Rect
            v-if="item.stroke && item.stroke !== 'transparent'"
            :config="{
                width: item.width,
                height: item.height,
                stroke: shown(item.stroke),
                strokeWidth: item.lineWidth,
                listening: false,
            }"
        />
    </template>
    <Rect
        v-else
        :config="{
            width: item.width,
            height: item.height,
            stroke: '#cbd5e1',
            dash: [6, 6],
        }"
    />
</template>
