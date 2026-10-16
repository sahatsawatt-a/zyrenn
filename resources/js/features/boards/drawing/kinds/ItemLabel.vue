<script setup lang="ts">
import type Konva from 'konva';
import { Shape, Text } from 'vue-konva';
import type { Item } from '../../model/items';
import { DIRECTION, useItemLook } from '../itemLook';
import {
    drawRich,
    fontOf,
    labelBox,
    labelHeight,
    labelStyle,
    LINE_HEIGHT,
    shownText,
} from '../labels';

// The words written in a shape -- or, for a text item, the item itself:
// light Markdown laid out line by line, or plain words wrapped by Konva.
// Double-click any of them to type.
const props = defineProps<{
    item: Item;
    selected: boolean;
    editing: boolean;
}>();

const { ink, ruled } = useItemLook(props);

/** A rich label takes clicks anywhere in its box, not only on its letters. */
const labelHit = (context: Konva.Context, shape: Konva.Shape) => {
    context.beginPath();
    context.rect(0, 0, shape.width(), shape.height());
    context.closePath();
    context.fillStrokeShape(shape);
};

/**
 * The label's box, and how tall to draw it. A label with more words than
 * room is drawn at the height its words need, running out past the edge
 * where it can be seen, rather than losing its last lines without a sign.
 */
const label = (item: Item) => {
    const box = labelBox(item);

    return { ...box, height: Math.max(box.height, labelHeight(item)) };
};
</script>

<template>
    <!-- Rich: headings, bullets and bold words, laid out line by line -->
    <Shape
        v-if="item.rich"
        :config="{
            name: 'rich-label',
            ...label(item),
            sceneFunc: drawRich(item, ink, ruled),
            hitFunc: labelHit,
            fill: ink,
            listening: item.kind === 'text',
            opacity: editing ? 0 : 1,
        }"
    />
    <Text
        v-else
        :config="{
            direction: DIRECTION,
            text: shownText(item),
            ...label(item),
            fontSize: item.fontSize,
            fontFamily: fontOf(item),
            fontStyle: labelStyle(item),
            lineHeight: LINE_HEIGHT,
            letterSpacing: item.letterSpacing ?? 0,
            fill: ink,
            // Words that fade across the label to a second colour
            ...(item.colorTo
                ? {
                      fillPriority: 'linear-gradient',
                      fillLinearGradientStartPoint: { x: 0, y: 0 },
                      fillLinearGradientEndPoint: {
                          x: labelBox(item).width,
                          y: 0,
                      },
                      fillLinearGradientColorStops: [0, ink, 1, item.colorTo],
                  }
                : {}),
            align: item.align,
            verticalAlign: item.verticalAlign,
            // A text item has no shape behind it, so its label is the only
            // thing that can be clicked; on other shapes the box is the hit
            // area and the label stays out of it.
            listening: item.kind === 'text',
            opacity: editing ? 0 : 1,
        }"
    />
</template>
