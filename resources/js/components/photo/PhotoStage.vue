<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { Handle, PhotoEdit, Size } from '@/lib/photo';
import {
    adjustPixels,
    dragCrop,
    drawPhoto,
    HANDLES,
    turnedSize,
    UNEDITED,
} from '@/lib/photo';

// The picture being edited, drawn by the same arithmetic that makes the saved
// one -- so what is seen is what is kept -- with the crop box over it, moved
// by dragging or the arrow keys. Comparing shows the original, untouched.

const props = defineProps<{
    image: HTMLImageElement;
    size: Size;
    /** The ratio the crop is held to, in fractions of the picture. */
    ratio: number | null;
    comparing: boolean;
}>();
const edit = defineModel<PhotoEdit>('edit', { required: true });
/** A drag, or a run of arrow keys, has come to rest. */
const emit = defineEmits<{ settle: [] }>();

const view = computed(() => (props.comparing ? UNEDITED : edit.value));

const stage = ref<HTMLElement>();
const { width: stageWidth, height: stageHeight } = useElementSize(stage);
const preview = ref<HTMLCanvasElement>();

const turned = computed(() => turnedSize(props.size, view.value.rotate));
const shown = computed(() => {
    const scale = Math.min(
        (stageWidth.value - 32) / turned.value.width,
        (stageHeight.value - 32) / turned.value.height,
    );

    return {
        width: Math.max(1, turned.value.width * scale),
        height: Math.max(1, turned.value.height * scale),
    };
});

// ---- drawing ----

/** The picture turned and flipped at the size shown, before light. */
let base: ImageData | null = null;
let frame = 0;

const paint = () => {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
        if (!base || !preview.value) {
            return;
        }

        const pixels = new ImageData(
            new Uint8ClampedArray(base.data),
            base.width,
            base.height,
        );

        if (!props.comparing) {
            const { crop } = edit.value;
            adjustPixels(pixels, edit.value, {
                x: crop.x * base.width,
                y: crop.y * base.height,
                width: crop.width * base.width,
                height: crop.height * base.height,
            });
        }

        preview.value.width = base.width;
        preview.value.height = base.height;
        preview.value.getContext('2d')?.putImageData(pixels, 0, 0);
    });
};

// Drawn afresh only when it turns, flips or is shown at another size
const rebuild = async () => {
    await nextTick();

    if (shown.value.width < 2) {
        return;
    }

    const scale = Math.min(
        1,
        (shown.value.width * window.devicePixelRatio) / turned.value.width,
    );
    const drawn = drawPhoto(props.image, props.size, view.value, {
        scale,
        whole: true,
    });
    base = drawn
        .getContext('2d')!
        .getImageData(0, 0, drawn.width, drawn.height);
    paint();
};

watch(
    () => [
        props.image,
        view.value.rotate,
        view.value.flipX,
        view.value.flipY,
        Math.round(shown.value.width),
    ],
    rebuild,
    { immediate: true },
);

// Light is put into the pixels again as it changes; the vignette follows the crop
watch(
    () => [
        props.comparing,
        edit.value.brightness,
        edit.value.contrast,
        edit.value.saturation,
        edit.value.warmth,
        edit.value.vignette,
        edit.value.vignette ? JSON.stringify(edit.value.crop) : '',
    ],
    paint,
);

onBeforeUnmount(() => cancelAnimationFrame(frame));

// ---- the crop box ----

let dragging: {
    handle: Handle;
    x: number;
    y: number;
    crop: PhotoEdit['crop'];
} | null = null;

const grab = (handle: Handle, event: PointerEvent) => {
    (event.currentTarget as Element).setPointerCapture(event.pointerId);
    dragging = {
        handle,
        x: event.clientX,
        y: event.clientY,
        crop: edit.value.crop,
    };
};

const drag = (event: PointerEvent) => {
    if (!dragging) {
        return;
    }

    edit.value = {
        ...edit.value,
        crop: dragCrop(
            dragging.crop,
            dragging.handle,
            (event.clientX - dragging.x) / shown.value.width,
            (event.clientY - dragging.y) / shown.value.height,
            props.ratio,
        ),
    };
};

const drop = () => {
    if (dragging) {
        dragging = null;
        emit('settle');
    }
};

const ARROWS: Record<string, [number, number]> = {
    ArrowLeft: [-1, 0],
    ArrowRight: [1, 0],
    ArrowUp: [0, -1],
    ArrowDown: [0, 1],
};

/** An arrow key moves the box a pixel as shown; with Shift, ten. */
const nudge = (event: KeyboardEvent) => {
    const arrow = ARROWS[event.key];

    if (!arrow) {
        return;
    }

    event.preventDefault();
    const step = event.shiftKey ? 10 : 1;
    edit.value = {
        ...edit.value,
        crop: dragCrop(
            edit.value.crop,
            'move',
            (arrow[0] * step) / shown.value.width,
            (arrow[1] * step) / shown.value.height,
        ),
    };
};

const rest = (event: KeyboardEvent) => {
    if (ARROWS[event.key]) {
        emit('settle');
    }
};

const cursors: Record<Handle, string> = {
    move: 'move',
    n: 'ns-resize',
    s: 'ns-resize',
    e: 'ew-resize',
    w: 'ew-resize',
    ne: 'nesw-resize',
    sw: 'nesw-resize',
    nw: 'nwse-resize',
    se: 'nwse-resize',
};

/** Where a handle sits on the crop box, as percentages. */
const handleAt = (handle: Handle) => ({
    left: handle.includes('w') ? '0%' : handle.includes('e') ? '100%' : '50%',
    top: handle.includes('n') ? '0%' : handle.includes('s') ? '100%' : '50%',
    cursor: cursors[handle],
});
</script>

<template>
    <div
        ref="stage"
        class="photo-stage relative flex size-full items-center justify-center overflow-hidden rounded-md"
    >
        <div
            class="relative touch-none select-none"
            data-test="photo-picture"
            :style="{
                width: `${shown.width}px`,
                height: `${shown.height}px`,
            }"
            @pointermove="drag"
            @pointerup="drop"
            @pointercancel="drop"
        >
            <canvas ref="preview" class="block size-full" />
            <div
                v-if="!comparing"
                class="photo-crop absolute"
                tabindex="0"
                role="group"
                aria-label="Crop box. Drag it or its handles; arrow keys move it."
                data-test="photo-crop"
                :style="{
                    left: `${edit.crop.x * 100}%`,
                    top: `${edit.crop.y * 100}%`,
                    width: `${edit.crop.width * 100}%`,
                    height: `${edit.crop.height * 100}%`,
                    cursor: 'move',
                }"
                @pointerdown="grab('move', $event)"
                @keydown="nudge"
                @keyup="rest"
            >
                <span class="photo-thirds" />
                <span
                    v-for="handle in HANDLES"
                    :key="handle"
                    class="photo-handle"
                    :data-test="`crop-${handle}`"
                    :style="handleAt(handle)"
                    @pointerdown.stop="grab(handle, $event)"
                />
            </div>
        </div>
        <slot />
    </div>
</template>

<style scoped>
/* A see-through picture shows as see-through */
.photo-stage {
    background-color: var(--muted);
    background-image:
        linear-gradient(45deg, var(--border) 25%, transparent 25%),
        linear-gradient(-45deg, var(--border) 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, var(--border) 75%),
        linear-gradient(-45deg, transparent 75%, var(--border) 75%);
    background-size: 16px 16px;
    background-position:
        0 0,
        0 8px,
        8px -8px,
        -8px 0;
}

/* What is cut off is dimmed, all round the box */
.photo-crop {
    outline: 1px solid rgb(255 255 255 / 0.9);
    box-shadow: 0 0 0 9999px rgb(0 0 0 / 0.55);
}
.photo-crop:focus-visible {
    outline: 2px solid var(--ring);
}

.photo-thirds {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        linear-gradient(
            to right,
            transparent calc(33.33% - 0.5px),
            rgb(255 255 255 / 0.45) calc(33.33% - 0.5px),
            rgb(255 255 255 / 0.45) calc(33.33% + 0.5px),
            transparent calc(33.33% + 0.5px),
            transparent calc(66.66% - 0.5px),
            rgb(255 255 255 / 0.45) calc(66.66% - 0.5px),
            rgb(255 255 255 / 0.45) calc(66.66% + 0.5px),
            transparent calc(66.66% + 0.5px)
        ),
        linear-gradient(
            to bottom,
            transparent calc(33.33% - 0.5px),
            rgb(255 255 255 / 0.45) calc(33.33% - 0.5px),
            rgb(255 255 255 / 0.45) calc(33.33% + 0.5px),
            transparent calc(33.33% + 0.5px),
            transparent calc(66.66% - 0.5px),
            rgb(255 255 255 / 0.45) calc(66.66% - 0.5px),
            rgb(255 255 255 / 0.45) calc(66.66% + 0.5px),
            transparent calc(66.66% + 0.5px)
        );
}

.photo-handle {
    position: absolute;
    width: 14px;
    height: 14px;
    margin: -7px 0 0 -7px;
    border-radius: 3px;
    background: white;
    box-shadow: 0 0 0 1px rgb(0 0 0 / 0.4);
    touch-action: none;
}
</style>
