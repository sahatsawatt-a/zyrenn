<script setup lang="ts">
import { PenTool } from '@lucide/vue';
import { computed } from 'vue';
import type { Hatch, Item, Sketch } from '../model/items';
import { HAND_FONTS, hasText } from '../model/items';
import { isSketchable, isSketched } from '../model/sketch';

// Drawn as by hand: how rough the lines of what is selected are, what fills
// a sketched shape, and the whole look -- lines and a handwritten font -- in
// one click, for the selection and, on a frame, everything on it.
const props = defineProps<{ selection: Item[] }>();
const emit = defineEmits<{
    update: [patch: Partial<Item>];
    sketch: [on: boolean];
}>();

const LEVELS: { value: Sketch; label: string }[] = [
    { value: 0, label: 'Off' },
    { value: 1, label: 'A little' },
    { value: 2, label: 'A lot' },
];

const HATCHES: { value: Hatch; label: string; title: string }[] = [
    { value: 'solid', label: 'Solid', title: 'Fill it with its colour' },
    { value: 'hachure', label: 'Lines', title: 'Lines across, as if shaded' },
    { value: 'cross', label: 'Crossed', title: 'Lines both ways' },
];

const sketchable = computed(() => props.selection.filter(isSketchable));
const first = computed(() => sketchable.value[0]);
const level = computed<Sketch>(() =>
    first.value && isSketched(first.value) ? (first.value.sketch ?? 0) : 0,
);

// A fill to hatch: a shape with an inside, not a line or a slide
const hatchable = computed(() =>
    sketchable.value.some(
        (item) =>
            isSketched(item) &&
            item.kind !== 'arrow' &&
            item.kind !== 'frame' &&
            item.fill &&
            item.fill !== 'transparent',
    ),
);

// The whole look is on when every line is sketched and every word written
// by hand; the button then takes it off
const looked = computed(
    () =>
        props.selection.every(
            (item) => !isSketchable(item) || isSketched(item),
        ) &&
        props.selection.every(
            (item) => !hasText(item) || HAND_FONTS.includes(item.fontFamily),
        ),
);
</script>

<template>
    <div class="inspector-align" data-test="sketch-config">
        <p v-if="sketchable.length" class="inspector-label">Hand-drawn</p>
        <div v-if="sketchable.length" class="inspector-segments">
            <button
                v-for="option in LEVELS"
                :key="option.value"
                type="button"
                :class="{ 'is-on': level === option.value }"
                :data-test="`sketch-${option.value}`"
                @click="emit('update', { sketch: option.value })"
            >
                {{ option.label }}
            </button>
        </div>
        <div v-if="hatchable" class="inspector-segments">
            <button
                v-for="option in HATCHES"
                :key="option.value"
                type="button"
                :title="option.title"
                :class="{
                    'is-on': (first?.hatch ?? 'solid') === option.value,
                }"
                :data-test="`hatch-${option.value}`"
                @click="emit('update', { hatch: option.value })"
            >
                {{ option.label }}
            </button>
        </div>
        <button
            type="button"
            class="inspector-wide"
            :title="
                looked
                    ? 'Crisp lines and plain type again'
                    : 'Sketchy lines and handwriting, on this and anything on its frame'
            "
            data-test="sketch-look"
            @click="emit('sketch', !looked)"
        >
            <PenTool class="size-3.5" />
            {{ looked ? 'Crisp look' : 'Sketch look' }}
        </button>
    </div>
</template>
