<script setup lang="ts">
import { computed } from 'vue';
import { Image as KonvaImage, Rect, Text } from 'vue-konva';
import { FRAME_TITLE } from '../../canvas/useLabelEditor';
import type { SlideDecor } from '../../deck/deck';
import { useSlideDecor } from '../../deck/deck';
import type { Item } from '../../model/items';
import { FONT_FAMILIES } from '../../model/items';
import { isSketched } from '../../model/sketch';
import { fontReady } from '../fonts';
import { DIRECTION, SELECTED, pictured, useItemLook } from '../itemLook';
import { paint } from '../paint';
import { figureOf } from '../sketch';
import SketchShape from './SketchShape.vue';

// A frame is the slide: a plain board-coloured card, with its own picture
// behind everything on it, what the deck writes on every slide, and its
// title above it.
const props = defineProps<{
    item: Item;
    selected: boolean;
    editing: boolean;
    image?: HTMLImageElement;
}>();

const { shown } = useItemLook(props);

// A slide drawn as by hand keeps its card plain and has its edge sketched
const sketched = computed(() =>
    isSketched(props.item) ? figureOf(props.item) : null,
);

// The slide's header, footer and number, as the deck writes them on it
const slideDecor = useSlideDecor();
const decor = computed(() => slideDecor.value.get(props.item.id));

/** One line of them: at the top of the slide or its foot, either side. */
const decorLine = (
    on: SlideDecor,
    text: string,
    edge: 'top' | 'bottom',
    align: 'left' | 'right',
) => {
    const size = 12 * on.scale;
    const inset = 22 * on.scale;

    return {
        direction: DIRECTION,
        text,
        x: on.margin,
        y: edge === 'top' ? inset : props.item.height - inset - size,
        width: props.item.width - on.margin * 2,
        fontSize: size,
        fontFamily: FONT_FAMILIES[on.font],
        fill: on.color,
        align,
        wrap: 'none',
        ellipsis: true,
        listening: false,
    };
};
</script>

<template>
    <!-- Its shadow falls from the fill alone: one cast by the outline as
         well has Konva draw the card off-screen first, a canvas the size of
         the card every time the board is drawn -->
    <Rect
        :config="{
            width: item.width,
            height: item.height,
            ...paint(item, item.width, item.height),
            stroke: selected ? SELECTED : shown(item.stroke),
            strokeWidth: sketched ? 0 : 1,
            cornerRadius: item.radius ?? 0,
            shadowColor: 'black',
            shadowOpacity: 0.06,
            shadowBlur: 18,
            shadowForStrokeEnabled: false,
        }"
    />
    <SketchShape
        v-if="sketched"
        :item="item"
        :selected="selected"
        :figure="sketched"
        :fill="null"
        :stroke="selected ? SELECTED : shown(item.stroke)"
        :line-width="selected ? 2 : 1.5"
        :listening="false"
    />
    <!-- A slide's own background picture, filling it unless told to fit -->
    <KonvaImage
        v-if="image"
        :config="{
            image,
            ...pictured(
                { ...item, fit: item.fit === 'contain' ? 'contain' : 'cover' },
                image,
            ),
            cornerRadius: item.radius ?? 0,
            listening: false,
        }"
    />
    <!-- What the deck writes on every slide: under whatever is put on it -->
    <template v-if="decor">
        <Text
            v-if="decor.header"
            :config="decorLine(decor, decor.header, 'top', 'left')"
        />
        <Text
            v-if="decor.footer"
            :config="decorLine(decor, decor.footer, 'bottom', 'left')"
        />
        <Text
            v-if="decor.number"
            :config="decorLine(decor, decor.number, 'bottom', 'right')"
        />
    </template>
    <!-- The title sits above it and is edited on its own, the way a slide
         is named -->
    <Text
        :config="{
            name: FRAME_TITLE,
            direction: DIRECTION,
            text: item.text || 'Untitled frame',
            y: -28,
            width: item.width,
            fontSize: 18,
            // In its own font once it has come: Arial unless set otherwise
            fontFamily: fontReady(item.fontFamily)
                ? FONT_FAMILIES[item.fontFamily]
                : FONT_FAMILIES.sans,
            fontStyle: '600',
            fill: selected ? SELECTED : '#64748b',
            opacity: editing ? 0 : 1,
        }"
    />
</template>
