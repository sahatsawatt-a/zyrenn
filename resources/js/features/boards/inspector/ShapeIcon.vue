<script setup lang="ts">
import { computed } from 'vue';
import type { ShapeDef } from '@/features/boards/model/shapes';
import { kitDrawing } from '@/features/boards/model/uikit';

// A shape from the library small, as its own button: the library's drawing of
// it at the proportions it lands on the board with, so what is picked is what
// is drawn.
const props = defineProps<{ shape: ShapeDef }>();

// A part of the UI kit is drawn for the size it is shown at: no wider than a
// small screen's part, nor so thin its knobs and chevrons are lost
const kitBox = computed(() => {
    if (!props.shape.kit) {
        return null;
    }

    const wide = Number(props.shape.defaults?.width ?? 160);
    const tall = Number(props.shape.defaults?.height ?? 60);
    const width = Math.min(wide, 160);

    return {
        width,
        height: Math.min(width, Math.max(tall, width * 0.35)),
    };
});

const drawing = computed(() =>
    kitBox.value
        ? (kitDrawing(
              props.shape.kit!,
              kitBox.value.width,
              kitBox.value.height,
          ) ?? props.shape)
        : props.shape,
);

// As wide and tall as a new one is, held to a square it is centred in
const size = computed(() => {
    const width =
        kitBox.value?.width ?? Number(props.shape.defaults?.width ?? 200);
    const height =
        kitBox.value?.height ?? Number(props.shape.defaults?.height ?? 140);
    const side = Math.max(width, height);

    // Nothing so thin that it is only a line: a brace, a lifeline
    return {
        width: Math.max(0.35, width / side) * 100,
        height: Math.max(0.35, height / side) * 100,
    };
});

const fit = computed(
    () =>
        `translate(${(100 - size.value.width) / 2} ${(100 - size.value.height) / 2}) scale(${size.value.width / 100} ${size.value.height / 100})`,
);
</script>

<template>
    <svg viewBox="-4 -4 108 108" aria-hidden="true">
        <g :transform="fit">
            <path
                :d="drawing.path"
                fill="none"
                stroke="currentColor"
                stroke-width="1.4"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
            <path v-if="drawing.solid" :d="drawing.solid" fill="currentColor" />
        </g>
    </svg>
</template>
