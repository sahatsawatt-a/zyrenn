<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Circle, Square, Squircle, X } from '@lucide/vue';
import type { Component } from 'vue';
import type {
    HideStyle,
    Handle,
    Hidden,
    PhotoEdit,
    Rect,
    Shape,
    Size,
} from '@/lib/photo';
import {
    adjustPixels,
    dragCrop,
    drawPhoto,
    HANDLES,
    HIDDEN_ROUNDING,
    hidePixels,
    ROUNDING,
    turnedSize,
    UNEDITED,
} from '@/lib/photo';

// The picture being edited, drawn by the same arithmetic that makes the saved
// one -- so what is seen is what is kept -- with the crop box over it and a
// box for each area hidden, each moved by dragging or the arrow keys. The
// chosen hidden area can be taken away with its button or Delete. Comparing
// shows the original, untouched.

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
            hidePixels(pixels, edit.value.hidden);
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
        view.value.angle,
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
        JSON.stringify(edit.value.hidden),
    ],
    paint,
);

onBeforeUnmount(() => cancelAnimationFrame(frame));

// ---- the crop box, and the hidden areas ----

/** What is being moved: the crop box, or a hidden area by its place. */
type Target = 'crop' | number;

/** The hidden area chosen, to move with the keys or take away. */
const chosen = ref<number | null>(null);

// One just added is the one chosen; one gone is chosen no longer
watch(
    () => edit.value.hidden.length,
    (now, before) => {
        if (now > before) {
            chosen.value = now - 1;
        } else if (chosen.value !== null && chosen.value >= now) {
            chosen.value = null;
        }
    },
);

const rectOf = (target: Target): Rect =>
    target === 'crop' ? edit.value.crop : edit.value.hidden[target];

const place = (target: Target, rect: Rect) => {
    edit.value =
        target === 'crop'
            ? { ...edit.value, crop: rect }
            : {
                  ...edit.value,
                  hidden: edit.value.hidden.map((area, index) =>
                      index === target ? { ...area, ...rect } : area,
                  ),
              };
};

let dragging: {
    target: Target;
    handle: Handle;
    x: number;
    y: number;
    rect: Rect;
} | null = null;

const grab = (target: Target, handle: Handle, event: PointerEvent) => {
    (event.currentTarget as Element).setPointerCapture(event.pointerId);
    chosen.value = target === 'crop' ? null : target;
    dragging = {
        target,
        handle,
        x: event.clientX,
        y: event.clientY,
        rect: rectOf(target),
    };
};

const drag = (event: PointerEvent) => {
    if (!dragging) {
        return;
    }

    place(
        dragging.target,
        dragCrop(
            dragging.rect,
            dragging.handle,
            (event.clientX - dragging.x) / shown.value.width,
            (event.clientY - dragging.y) / shown.value.height,
            dragging.target === 'crop' ? props.ratio : null,
        ),
    );
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

const remove = (index: number) => {
    edit.value = {
        ...edit.value,
        hidden: edit.value.hidden.filter((_, each) => each !== index),
    };
    chosen.value = null;
    emit('settle');
};

/**
 * An arrow key moves the box a pixel as shown; with Shift, ten. Delete takes
 * a hidden area away.
 */
const nudge = (target: Target, event: KeyboardEvent) => {
    if (
        target !== 'crop' &&
        (event.key === 'Delete' || event.key === 'Backspace')
    ) {
        event.preventDefault();
        remove(target);

        return;
    }

    const arrow = ARROWS[event.key];

    if (!arrow) {
        return;
    }

    event.preventDefault();
    const step = event.shiftKey ? 10 : 1;
    place(
        target,
        dragCrop(
            rectOf(target),
            'move',
            (arrow[0] * step) / shown.value.width,
            (arrow[1] * step) / shown.value.height,
        ),
    );
};

const rest = (event: KeyboardEvent) => {
    if (ARROWS[event.key]) {
        emit('settle');
    }
};

const reshape = (index: number, shape: Shape) => {
    edit.value = {
        ...edit.value,
        hidden: edit.value.hidden.map((area, each) =>
            each === index ? { ...area, shape } : area,
        ),
    };
    emit('settle');
};

const AREA_SHAPES: { shape: Shape; label: string; icon: Component }[] = [
    { shape: 'rect', label: 'Rectangle', icon: Square },
    { shape: 'rounded', label: 'Rounded', icon: Squircle },
    { shape: 'circle', label: 'Oval', icon: Circle },
];

/** A hidden area's box drawn in its shape. */
const areaRounding = (area: Hidden) =>
    area.shape === 'circle'
        ? '50%'
        : area.shape === 'rounded'
          ? `${
                Math.min(
                    area.width * shown.value.width,
                    area.height * shown.value.height,
                ) * HIDDEN_ROUNDING
            }px`
          : '0';

const STYLES: Record<HideStyle, string> = {
    blur: 'Blur',
    pixelate: 'Pixelate',
    fill: 'Black box',
};

/** The crop box drawn in the shape it will be cut to. */
const cropRounding = computed(() =>
    edit.value.shape === 'circle'
        ? '50%'
        : edit.value.shape === 'rounded'
          ? `${
                Math.min(
                    edit.value.crop.width * shown.value.width,
                    edit.value.crop.height * shown.value.height,
                ) * ROUNDING
            }px`
          : '0',
);

/** A box's place on the picture, as percentages. */
const boxAt = (rect: Rect) => ({
    left: `${rect.x * 100}%`,
    top: `${rect.y * 100}%`,
    width: `${rect.width * 100}%`,
    height: `${rect.height * 100}%`,
});

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
                    ...boxAt(edit.crop),
                    borderRadius: cropRounding,
                    cursor: 'move',
                }"
                @pointerdown="grab('crop', 'move', $event)"
                @keydown="nudge('crop', $event)"
                @keyup="rest"
            >
                <span class="photo-thirds" />
                <span
                    v-for="handle in HANDLES"
                    :key="handle"
                    class="photo-handle"
                    :data-test="`crop-${handle}`"
                    :style="handleAt(handle)"
                    @pointerdown.stop="grab('crop', handle, $event)"
                />
            </div>
            <template v-if="!comparing">
                <div
                    v-for="(area, index) in edit.hidden"
                    :key="index"
                    class="photo-hidden absolute"
                    :class="chosen === index && 'is-chosen'"
                    tabindex="0"
                    role="group"
                    :aria-label="`${STYLES[area.style]}. Drag it or its handles; arrow keys move it, Delete takes it away.`"
                    :data-test="`hidden-${index}`"
                    :style="{
                        ...boxAt(area),
                        borderRadius: areaRounding(area),
                        cursor: 'move',
                    }"
                    @pointerdown.stop="grab(index, 'move', $event)"
                    @keydown="nudge(index, $event)"
                    @keyup="rest"
                >
                    <span class="photo-hidden-label">{{
                        STYLES[area.style]
                    }}</span>
                    <template v-if="chosen === index">
                        <span
                            v-for="handle in HANDLES"
                            :key="handle"
                            class="photo-handle is-small"
                            :style="handleAt(handle)"
                            @pointerdown.stop="grab(index, handle, $event)"
                        />
                        <div class="photo-hidden-tools" @pointerdown.stop>
                            <button
                                v-for="each in AREA_SHAPES"
                                :key="each.shape"
                                type="button"
                                :class="area.shape === each.shape && 'is-on'"
                                :title="each.label"
                                :aria-label="each.label"
                                :aria-pressed="area.shape === each.shape"
                                :data-test="`hidden-shape-${each.shape}`"
                                @click.stop="reshape(index, each.shape)"
                            >
                                <component :is="each.icon" class="size-3" />
                            </button>
                            <button
                                type="button"
                                :aria-label="`Take the ${STYLES[area.style].toLowerCase()} away`"
                                title="Take it away"
                                data-test="hidden-remove"
                                @click.stop="remove(index)"
                            >
                                <X class="size-3" />
                            </button>
                        </div>
                    </template>
                </div>
            </template>
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

.photo-hidden {
    outline: 1.5px dashed rgb(255 255 255 / 0.85);
    box-shadow: 0 0 0 1px rgb(0 0 0 / 0.35);
}
.photo-hidden.is-chosen,
.photo-hidden:focus-visible {
    outline: 2px solid var(--primary);
}
.photo-hidden-label {
    position: absolute;
    top: 2px;
    left: 4px;
    font-size: 10px;
    line-height: 1.2;
    color: white;
    text-shadow: 0 0 3px rgb(0 0 0 / 0.8);
    pointer-events: none;
    white-space: nowrap;
}
.photo-hidden-tools {
    /* Above the box, clear of its handles */
    position: absolute;
    bottom: calc(100% + 10px);
    left: 50%;
    display: flex;
    gap: 1px;
    padding: 2px;
    transform: translateX(-50%);
    border-radius: 6px;
    background: var(--background);
    color: var(--foreground);
    box-shadow: 0 0 0 1px rgb(0 0 0 / 0.3);
    cursor: default;
}
.photo-hidden-tools button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 4px;
    cursor: pointer;
}
.photo-hidden-tools button:hover,
.photo-hidden-tools button.is-on {
    background: var(--accent);
}
.photo-handle.is-small {
    width: 10px;
    height: 10px;
    margin: -5px 0 0 -5px;
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
