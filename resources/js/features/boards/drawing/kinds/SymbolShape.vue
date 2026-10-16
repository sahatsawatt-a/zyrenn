<script setup lang="ts">
import type Konva from 'konva';
import { Path, Rect, Shape } from 'vue-konva';
import {
    LABEL_LINE,
    LABEL_SIZE,
    inItem,
    pinTexts,
    symbolLabels,
    symbolOf,
} from '../../circuit/symbols';
import { WARNED } from '../../circuit/values';
import type { Item } from '../../model/items';
import { LIVE, OVER, SELECTED, WORKED, useItemLook } from '../itemLook';
import { INK } from '../labels';

// A part of a circuit: the library's drawing of it, fitted to the box, lit
// where its logic is high, with its name and value beside it.
const props = defineProps<{
    item: Item;
    selected: boolean;
    editing: boolean;
    // In a logic circuit: whether this input or lamp is high just now
    level?: 0 | 1 | null;
    // On a display: which of its segments are lit
    lit?: string[];
    // What flows through it and is across it, once worked out
    worked?: string;
}>();

const { shown } = useItemLook(props);

/** How much a symbol is drawn larger than the library has it. */
const symbolScale = (item: Item) => {
    const symbol = symbolOf(item.symbol);

    return symbol
        ? (item.width / symbol.width + item.height / symbol.height) / 2
        : 1;
};

/**
 * Draws a symbol's words -- its name and value beside it, a module's pin
 * names inside it. They are placed on the board, the right way up whichever
 * way the part is turned, so each is put back into the part's own turned
 * space and turned the other way to match.
 *
 * One shape of no size draws them all: words that were items of their own
 * were measured into the part's box, and stretched the frame drawn round a
 * selected part out to wherever they were.
 */
const drawWords =
    (item: Item, ink: string, worked = '') =>
    (context: Konva.Context) => {
        const upright = (-item.rotation * Math.PI) / 180;

        context.setAttr('textBaseline', 'middle');

        for (const label of [
            ...symbolLabels(item, worked ? [worked] : []),
            ...pinTexts(item),
        ]) {
            const at = inItem(item, label);

            context.setAttr(
                'fillStyle',
                label.note
                    ? label.text.startsWith(WARNED)
                        ? OVER
                        : WORKED
                    : ink,
            );
            context.save();
            context.translate(at.x, at.y);
            context.rotate(upright);
            context.setAttr(
                'font',
                `${label.name ? '600 ' : ''}${label.size ?? LABEL_SIZE}px Arial`,
            );
            context.setAttr('textAlign', label.align);
            context.fillText(
                label.text,
                0,
                label.baseline === 'top'
                    ? LABEL_LINE / 2
                    : label.baseline === 'bottom'
                      ? -LABEL_LINE / 2
                      : 0,
            );
            context.restore();
        }
    };
</script>

<template>
    <!-- The whole box takes the pointer: the drawing is thin lines -->
    <Rect
        :config="{
            width: item.width,
            height: item.height,
            fill: 'rgba(0,0,0,0)',
        }"
    />
    <!-- A logic input that is set, a lamp that is fed: filled in -->
    <Path
        v-if="symbolOf(item.symbol)?.lit && level === 1"
        :config="{
            data: symbolOf(item.symbol)!.lit,
            scaleX: item.width / symbolOf(item.symbol)!.width,
            scaleY: item.height / symbolOf(item.symbol)!.height,
            fill: LIVE,
            opacity: 0.55,
            listening: false,
        }"
    />
    <Path
        v-if="symbolOf(item.symbol)?.path"
        :config="{
            data: symbolOf(item.symbol)!.path,
            scaleX: item.width / symbolOf(item.symbol)!.width,
            scaleY: item.height / symbolOf(item.symbol)!.height,
            stroke: selected ? SELECTED : shown(item.stroke),
            // As heavy as a wire, at any zoom: the line is drawn in board
            // units, made up for whatever the part is scaled by
            strokeWidth: 2 / symbolScale(item),
            lineCap: 'round',
            lineJoin: 'round',
            listening: false,
        }"
    />
    <!-- A display's segments: each faint until its pin is high -->
    <Path
        v-for="(segment, pin) in symbolOf(item.symbol)?.segments ?? {}"
        :key="pin"
        :config="{
            data: segment,
            scaleX: item.width / symbolOf(item.symbol)!.width,
            scaleY: item.height / symbolOf(item.symbol)!.height,
            fill: lit?.includes(String(pin)) ? LIVE : shown(item.stroke),
            opacity: lit?.includes(String(pin)) ? 1 : 0.1,
            listening: false,
        }"
    />
    <!-- A switch's knob: at one end while off, the other while on -->
    <Path
        v-if="symbolOf(item.symbol)?.states"
        :config="{
            data: symbolOf(item.symbol)!.states![level === 1 ? 'on' : 'off'],
            scaleX: item.width / symbolOf(item.symbol)!.width,
            scaleY: item.height / symbolOf(item.symbol)!.height,
            fill: shown('#ffffff'),
            stroke: selected ? SELECTED : shown(item.stroke),
            strokeWidth: 2 / symbolScale(item),
            listening: false,
        }"
    />
    <Path
        v-if="symbolOf(item.symbol)?.fill"
        :config="{
            data: symbolOf(item.symbol)!.fill,
            scaleX: item.width / symbolOf(item.symbol)!.width,
            scaleY: item.height / symbolOf(item.symbol)!.height,
            fill: selected ? SELECTED : shown(item.stroke),
            listening: false,
        }"
    />
    <Shape
        :config="{
            sceneFunc: drawWords(item, shown(INK), worked),
            listening: false,
            opacity: editing ? 0 : 1,
        }"
    />
</template>
