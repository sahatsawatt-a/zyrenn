<script setup lang="ts">
import { Paintbrush } from '@lucide/vue';
import { computed } from 'vue';
import RangeField from '@/components/common/RangeField.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { isSymbol } from '../circuit/symbols';
import type { Item } from '../model/items';
import { hasOutline } from '../model/items';
import { styled } from '../model/styles';
import ColourPicker from './ColourPicker.vue';
import StylePicker from './StylePicker.vue';

// What is selected, coloured: a ready-made style, its fill and its line --
// a chip each, opened for the swatches and any other colour, rather than
// every palette always out -- and how heavy a shape's edge is.
const props = defineProps<{
    selection: Item[];
    fill: string;
    stroke: string;
    // Which way a picker opens: beside the panel, or above it on a phone
    beside: 'top' | 'left';
}>();
const emit = defineEmits<{
    update: [patch: Partial<Item>];
    paint: [colour: string];
    'paint-stroke': [colour: string];
    // A style put on, as each kind takes it
    restyle: [changes: Map<string, Partial<Item>>];
}>();

const shadowed = computed(() => props.selection.some((item) => item.shadow));

// Nothing here but lines: no fill to choose
const lineOnly = computed(() =>
    props.selection.every((item) => isSymbol(item) || item.kind === 'arrow'),
);

// The first shape with an edge of its own, for how heavy that edge is; a
// connector has its own (ConnectorSettings)
const outlined = computed(() => props.selection.find(hasOutline) ?? null);
const line = computed(() =>
    props.selection.some((item) => item.kind === 'arrow'),
);

// A ready-made style put on everything chosen, as each kind takes it
const onStyle = ({ style, colour }: { style: string; colour: string }) =>
    emit(
        'restyle',
        new Map(
            props.selection.map((item) => [
                item.id,
                styled(item, style, colour),
            ]),
        ),
    );
</script>

<template>
    <!-- Its colours: a chip each, opened for the swatches and for
         any other colour, rather than both palettes always out --
         and a ready-made style, opened for the sets to choose from -->
    <div class="inspector-colours">
        <Popover>
            <PopoverTrigger as-child>
                <button
                    type="button"
                    title="A ready-made style: colours that go together"
                    data-test="style-chip"
                >
                    <Paintbrush class="size-3.5" />
                    Style
                </button>
            </PopoverTrigger>
            <PopoverContent
                :side="beside"
                align="start"
                :collision-padding="8"
                class="w-72 p-3"
            >
                <StylePicker
                    :shadow="shadowed"
                    @pick="onStyle"
                    @shadow="emit('update', { shadow: $event })"
                />
            </PopoverContent>
        </Popover>
        <Popover v-if="!lineOnly">
            <PopoverTrigger as-child>
                <button type="button" title="Fill colour" data-test="fill-chip">
                    <span
                        class="inspector-chip"
                        :class="{ 'is-none': fill === 'transparent' }"
                        :style="
                            fill === 'transparent'
                                ? {}
                                : { backgroundColor: fill }
                        "
                    />
                    Fill
                </button>
            </PopoverTrigger>
            <PopoverContent
                :side="beside"
                align="start"
                :collision-padding="8"
                class="w-64 p-3"
            >
                <ColourPicker
                    :model-value="fill"
                    label="Fill"
                    @update:model-value="emit('paint', $event)"
                />
            </PopoverContent>
        </Popover>
        <Popover>
            <PopoverTrigger as-child>
                <button
                    type="button"
                    title="Line colour"
                    data-test="stroke-chip"
                >
                    <span
                        class="inspector-chip is-line"
                        :class="{ 'is-none': stroke === 'transparent' }"
                        :style="
                            stroke === 'transparent'
                                ? {}
                                : { borderColor: stroke }
                        "
                    />
                    Line
                </button>
            </PopoverTrigger>
            <PopoverContent
                :side="beside"
                align="start"
                :collision-padding="8"
                class="w-64 p-3"
            >
                <ColourPicker
                    :model-value="stroke"
                    label="Line"
                    data-test="stroke-picker"
                    @update:model-value="emit('paint-stroke', $event)"
                />
            </PopoverContent>
        </Popover>
    </div>
    <!-- A shape's edge: none at 0. A connector has its own below -->
    <label v-if="outlined && !line" class="inspector-slider">
        Line width
        <RangeField
            :model-value="outlined.lineWidth"
            label="Line width"
            :min="0"
            :max="12"
            class="flex-1"
            data-test="outline-width"
            @update:model-value="emit('update', { lineWidth: $event })"
        />
    </label>
</template>

<style scoped>
/* Fill and line, side by side: each a chip of its colour and its name */
.inspector-colours {
    display: flex;
    gap: 0.375rem;
}
.inspector-colours button {
    display: inline-flex;
    flex: 1;
    align-items: center;
    gap: 0.5rem;
    height: 2rem;
    padding: 0 0.5rem;
    font-size: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.inspector-colours button:hover,
.inspector-colours button[data-state='open'] {
    background-color: var(--muted);
}
/* A line's colour is a ring, as a line is */
.inspector-chip.is-line {
    background-color: transparent;
    border-width: 3px;
}
</style>
