<script setup lang="ts">
import {
    Contrast,
    Droplet,
    FlipHorizontal2,
    FlipVertical2,
    LoaderCircle,
    RotateCcw,
    RotateCw,
    Sun,
} from '@lucide/vue';
import { useElementSize } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, nextTick, ref, shallowRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { driveRefOf, photoOriginal, savePhotoEdit } from '@/lib/drive';
import type { Handle, PhotoEdit, Size } from '@/lib/photo';
import {
    cssFilter,
    dragCrop,
    drawPhoto,
    flip,
    fractionRatio,
    HANDLES,
    isUnedited,
    largestCrop,
    renderPhoto,
    turn,
    turnedSize,
    UNEDITED,
} from '@/lib/photo';

// A picture cropped, turned, flipped and lightened, wherever pictures are --
// a note, a board. Saving keeps a new picture in the Drive beside the
// original, which is where the next edit starts from: the crop is never lost.

const props = defineProps<{ src: string }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    saved: [picture: { src: string; width: number; height: number }];
}>();

const image = shallowRef<HTMLImageElement | null>(null);
const size = ref<Size>({ width: 1, height: 1 });
const mime = ref<string | null>(null);
/** The Drive picture being edited from, and its address. */
const source = ref<{ ref: string; url: string } | null>(null);
/** The picture opened was itself made in the editor. */
const wasEdited = ref(false);
const begun = ref<PhotoEdit>(UNEDITED);
const edit = ref<PhotoEdit>(UNEDITED);
const loading = ref(false);
const saving = ref(false);
const error = ref('');

// ---- opening a picture ----

const loadImage = (url: string, cors: boolean) =>
    new Promise<HTMLImageElement>((resolve, reject) => {
        const loaded = new Image();

        if (cors) {
            loaded.crossOrigin = 'anonymous';
        }

        loaded.onload = () => resolve(loaded);
        loaded.onerror = () =>
            reject(new Error('The picture couldn’t be loaded.'));
        loaded.src = url;
    });

const foreign = (url: string) =>
    !url.startsWith('data:') &&
    new URL(url, window.location.href).origin !== window.location.origin;

const begin = async () => {
    loading.value = true;
    error.value = '';
    image.value = null;
    source.value = null;
    wasEdited.value = false;
    ratio.value = 'free';
    let url = props.src;
    let start = UNEDITED;
    mime.value = props.src.match(/^data:([^;,]+)/)?.[1] ?? null;

    try {
        const ref = driveRefOf(props.src);

        if (ref) {
            const found = await photoOriginal(ref);
            const from = found.source ?? found.file;
            url = from.url;
            mime.value = from.mime;
            source.value = { ref: from.ref_id, url: from.url };
            wasEdited.value = found.source !== null;
            start = found.edit ?? UNEDITED;
        }

        // Another site's picture can only be saved if it lets us read it
        image.value = foreign(url)
            ? await loadImage(url, true).catch(() => loadImage(url, false))
            : await loadImage(url, false);
        size.value = {
            width: image.value.naturalWidth || 1000,
            height: image.value.naturalHeight || 1000,
        };
        begun.value = start;
        edit.value = start;
    } catch (thrown) {
        error.value = (thrown as Error).message;
    } finally {
        loading.value = false;
    }
};

// ---- the picture on the stage ----

const stage = ref<HTMLElement>();
const { width: stageWidth, height: stageHeight } = useElementSize(stage);
const preview = ref<HTMLCanvasElement>();

const turned = computed(() => turnedSize(size.value, edit.value.rotate));
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

// Drawn again only when it turns or flips; the crop and light are laid over
const redraw = async () => {
    await nextTick();

    if (!image.value || !preview.value || shown.value.width < 2) {
        return;
    }

    const scale = Math.min(
        1,
        (shown.value.width * window.devicePixelRatio) / turned.value.width,
    );
    const drawn = drawPhoto(image.value, size.value, edit.value, {
        scale,
        whole: true,
    });
    preview.value.width = drawn.width;
    preview.value.height = drawn.height;
    preview.value.getContext('2d')?.drawImage(drawn, 0, 0);
};

watch(
    () => [
        image.value,
        edit.value.rotate,
        edit.value.flipX,
        edit.value.flipY,
        Math.round(shown.value.width),
    ],
    redraw,
);

// ---- cropping ----

type Ratio = {
    id: string;
    label: string;
    value: number | null;
    inverse: string;
};

const ratios: Ratio[] = [
    { id: 'free', label: 'Free', value: null, inverse: 'free' },
    { id: 'original', label: 'Original', value: 0, inverse: 'original' },
    { id: '1:1', label: '1:1', value: 1, inverse: '1:1' },
    { id: '4:3', label: '4:3', value: 4 / 3, inverse: '3:4' },
    { id: '3:4', label: '3:4', value: 3 / 4, inverse: '4:3' },
    { id: '16:9', label: '16:9', value: 16 / 9, inverse: '9:16' },
    { id: '9:16', label: '9:16', value: 9 / 16, inverse: '16:9' },
];
const ratio = ref('free');

/** The ratio held to, in fractions of the picture, or none. */
const held = computed(() => {
    const chosen = ratios.find((each) => each.id === ratio.value);

    if (!chosen || chosen.value === null) {
        return null;
    }

    return chosen.value === 0 ? 1 : fractionRatio(chosen.value, turned.value);
});

const chooseRatio = (id: string) => {
    ratio.value = id;

    if (held.value) {
        edit.value = { ...edit.value, crop: largestCrop(held.value) };
    }
};

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
            held.value,
        ),
    };
};

const drop = () => (dragging = null);

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

// ---- turning, flipping, light ----

const turnBy = (clockwise: boolean) => {
    edit.value = turn(edit.value, clockwise);
    // A 16:9 crop turned is a 9:16 one
    ratio.value =
        ratios.find((each) => each.id === ratio.value)?.inverse ?? 'free';
};

const sliders: {
    key: 'brightness' | 'contrast' | 'saturation';
    label: string;
    icon: Component;
}[] = [
    { key: 'brightness', label: 'Brightness', icon: Sun },
    { key: 'contrast', label: 'Contrast', icon: Contrast },
    { key: 'saturation', label: 'Saturation', icon: Droplet },
];

const setLight = (
    key: 'brightness' | 'contrast' | 'saturation',
    event: Event,
) => {
    edit.value = {
        ...edit.value,
        [key]: Number((event.target as HTMLInputElement).value),
    };
};

const reset = () => {
    edit.value = UNEDITED;
    ratio.value = 'free';
};

// ---- keeping it ----

const changed = computed(
    () => JSON.stringify(edit.value) !== JSON.stringify(begun.value),
);

const save = async () => {
    if (!image.value) {
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        // Every edit undone: the original itself, with nothing new to keep
        if (isUnedited(edit.value) && wasEdited.value && source.value) {
            emit('saved', { src: source.value.url, ...size.value });
        } else {
            const made = await renderPhoto(
                image.value,
                size.value,
                edit.value,
                mime.value,
            ).catch((thrown) => {
                throw (thrown as Error).name === 'SecurityError'
                    ? new Error(
                          'This picture is from another site, so it can’t be edited here. Add it to your Drive first.',
                      )
                    : thrown;
            });
            const file = await savePhotoEdit(
                made.blob,
                source.value?.ref ?? null,
                edit.value,
            );
            emit('saved', {
                src: file.url,
                width: made.width,
                height: made.height,
            });
        }

        open.value = false;
    } catch (thrown) {
        error.value = (thrown as Error).message;
    } finally {
        saving.value = false;
    }
};

// Last, once everything it uses is set up
watch(open, (now) => now && begin(), { immediate: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-5xl" data-test="photo-editor">
            <DialogHeader>
                <DialogTitle>Edit photo</DialogTitle>
                <DialogDescription>
                    Crop, turn and adjust it. The original stays in your Drive,
                    so you can come back to it.
                </DialogDescription>
            </DialogHeader>

            <div class="flex min-w-0 flex-col gap-4 md:flex-row">
                <div
                    ref="stage"
                    class="photo-stage relative flex h-[min(60vh,560px)] min-w-0 flex-1 items-center justify-center overflow-hidden rounded-md"
                >
                    <LoaderCircle
                        v-if="loading"
                        class="text-muted-foreground size-6 animate-spin"
                    />
                    <div
                        v-else-if="image"
                        class="relative touch-none select-none"
                        :style="{
                            width: `${shown.width}px`,
                            height: `${shown.height}px`,
                        }"
                        @pointermove="drag"
                        @pointerup="drop"
                        @pointercancel="drop"
                    >
                        <canvas
                            ref="preview"
                            class="block size-full"
                            :style="{ filter: cssFilter(edit) }"
                        />
                        <div
                            class="photo-crop absolute"
                            data-test="photo-crop"
                            :style="{
                                left: `${edit.crop.x * 100}%`,
                                top: `${edit.crop.y * 100}%`,
                                width: `${edit.crop.width * 100}%`,
                                height: `${edit.crop.height * 100}%`,
                                cursor: 'move',
                            }"
                            @pointerdown="grab('move', $event)"
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
                </div>

                <div class="flex flex-col gap-5 md:w-60">
                    <section>
                        <h3 class="mb-2 text-xs font-medium">Crop</h3>
                        <div class="grid grid-cols-4 gap-1">
                            <button
                                v-for="each in ratios"
                                :key="each.id"
                                type="button"
                                class="rounded-md border px-1 py-1 text-xs"
                                :class="[
                                    ratio === each.id
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'hover:bg-accent',
                                    each.id === 'original' && 'col-span-2',
                                ]"
                                :data-test="`ratio-${each.id}`"
                                @click="chooseRatio(each.id)"
                            >
                                {{ each.label }}
                            </button>
                        </div>
                    </section>

                    <section>
                        <h3 class="mb-2 text-xs font-medium">Turn & flip</h3>
                        <div class="grid grid-cols-4 gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Turn left"
                                title="Turn left"
                                data-test="turn-left"
                                @click="turnBy(false)"
                            >
                                <RotateCcw class="size-4" />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Turn right"
                                title="Turn right"
                                data-test="turn-right"
                                @click="turnBy(true)"
                            >
                                <RotateCw class="size-4" />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Flip left to right"
                                title="Flip left to right"
                                data-test="flip-x"
                                @click="edit = flip(edit, 'x')"
                            >
                                <FlipHorizontal2 class="size-4" />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Flip upside down"
                                title="Flip upside down"
                                data-test="flip-y"
                                @click="edit = flip(edit, 'y')"
                            >
                                <FlipVertical2 class="size-4" />
                            </Button>
                        </div>
                    </section>

                    <section class="space-y-3">
                        <h3 class="text-xs font-medium">Light & colour</h3>
                        <label
                            v-for="slider in sliders"
                            :key="slider.key"
                            class="block"
                        >
                            <span
                                class="text-muted-foreground mb-1 flex items-center gap-1.5 text-xs"
                            >
                                <component :is="slider.icon" class="size-3.5" />
                                {{ slider.label }}
                                <span class="ml-auto tabular-nums">
                                    {{ edit[slider.key] - 100 > 0 ? '+' : ''
                                    }}{{ edit[slider.key] - 100 }}
                                </span>
                            </span>
                            <input
                                type="range"
                                min="0"
                                max="200"
                                class="accent-primary w-full"
                                :value="edit[slider.key]"
                                :data-test="`light-${slider.key}`"
                                @input="setLight(slider.key, $event)"
                                @dblclick="
                                    edit = { ...edit, [slider.key]: 100 }
                                "
                            />
                        </label>
                    </section>
                </div>
            </div>

            <p
                v-if="error"
                class="bg-destructive/10 text-destructive rounded-md px-3 py-2 text-sm"
                data-test="photo-error"
            >
                {{ error }}
            </p>

            <DialogFooter class="gap-2 sm:justify-between">
                <Button
                    variant="ghost"
                    :disabled="!image || isUnedited(edit)"
                    data-test="photo-reset"
                    @click="reset"
                >
                    <RotateCcw class="size-4" /> Reset to original
                </Button>
                <div class="flex gap-2">
                    <Button variant="outline" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="!image || !changed || saving"
                        data-test="photo-save"
                        @click="save"
                    >
                        <LoaderCircle
                            v-if="saving"
                            class="size-4 animate-spin"
                        />
                        Save
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
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
