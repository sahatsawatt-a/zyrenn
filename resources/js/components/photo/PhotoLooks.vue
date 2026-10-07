<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import type { Light, LightKey, PhotoEdit, Size } from '@/lib/photo';
import {
    adjustPixels,
    drawPhoto,
    hidePixels,
    LOOKS,
    lookLight,
    outputSize,
    pictureFrame,
} from '@/lib/photo';

// Looks to start from, each shown on the picture as it is cropped: picking
// one sets the light and colour, which the sliders can then take further.

const props = defineProps<{
    image: HTMLImageElement;
    size: Size;
    edit: PhotoEdit;
}>();
const emit = defineEmits<{ pick: [light: Light] }>();

const KEYS: LightKey[] = [
    'brightness',
    'contrast',
    'saturation',
    'warmth',
    'vignette',
];

const thumbnails = ref<Record<string, string>>({});

const draw = () => {
    const out = outputSize(props.size, props.edit);
    const scale = 96 / Math.max(out.width, out.height);
    const small = drawPhoto(props.image, props.size, props.edit, { scale });
    const context = small.getContext('2d')!;
    const base = hidePixels(
        context.getImageData(0, 0, small.width, small.height),
        props.edit.hidden,
        pictureFrame(props.size, props.edit, scale),
    );

    thumbnails.value = Object.fromEntries(
        LOOKS.map((look) => {
            context.putImageData(
                adjustPixels(
                    new ImageData(
                        new Uint8ClampedArray(base.data),
                        base.width,
                        base.height,
                    ),
                    lookLight(look.light),
                ),
                0,
                0,
            );

            return [look.id, small.toDataURL('image/jpeg', 0.85)];
        }),
    );
};

watch(
    () => [
        props.image,
        props.edit.rotate,
        props.edit.flipX,
        props.edit.flipY,
        JSON.stringify(props.edit.crop),
        JSON.stringify(props.edit.hidden),
    ],
    useDebounceFn(draw, 150),
    { immediate: true },
);

/** The look the light is set to now, if it is one of them. */
const current = computed(
    () =>
        LOOKS.find((look) => {
            const light = lookLight(look.light);

            return KEYS.every((key) => light[key] === props.edit[key]);
        })?.id,
);
</script>

<template>
    <div class="grid grid-cols-4 gap-1.5">
        <button
            v-for="look in LOOKS"
            :key="look.id"
            type="button"
            class="group flex flex-col items-center gap-1 text-[11px]"
            :aria-pressed="current === look.id"
            :data-test="`look-${look.id}`"
            @click="emit('pick', lookLight(look.light))"
        >
            <span
                class="bg-muted block aspect-square w-full overflow-hidden rounded-md ring-offset-1"
                :class="
                    current === look.id
                        ? 'ring-primary ring-2'
                        : 'group-hover:ring-border ring-1 ring-transparent'
                "
            >
                <img
                    v-if="thumbnails[look.id]"
                    :src="thumbnails[look.id]"
                    alt=""
                    class="size-full object-cover"
                />
            </span>
            <span
                :class="
                    current === look.id
                        ? 'text-foreground font-medium'
                        : 'text-muted-foreground'
                "
                >{{ look.label }}</span
            >
        </button>
    </div>
</template>
