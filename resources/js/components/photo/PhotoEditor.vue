<script setup lang="ts">
import {
    Aperture,
    Blend,
    Circle,
    Contrast,
    Droplet,
    Eye,
    FlipHorizontal2,
    FlipVertical2,
    Grid3x3,
    LoaderCircle,
    RectangleHorizontal,
    Redo2,
    RotateCcw,
    RotateCw,
    Square,
    Squircle,
    Sun,
    Thermometer,
    Undo2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, shallowRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { driveRefOf, photoOriginal, savePhotoEdit } from '@/lib/drive';
import type {
    HideStyle,
    Light,
    LightKey,
    PhotoEdit,
    Shape,
    Size,
} from '@/lib/photo';
import {
    flip,
    fractionRatio,
    fullEdit,
    isUnedited,
    largestCrop,
    newHidden,
    outputSize,
    renderPhoto,
    SAVE_SIZES,
    saveScale,
    turn,
    turnedSize,
    UNEDITED,
} from '@/lib/photo';
import PhotoLooks from './PhotoLooks.vue';
import PhotoStage from './PhotoStage.vue';

// A picture cropped, turned, flipped and lightened, wherever pictures are --
// a note, a board, the Drive. Saving keeps a new picture in the Drive beside
// the original, which is where the next edit starts from: the crop is never
// lost. Every change can be undone, and the original held up to compare.

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
const comparing = ref(false);

// ---- cropping to a ratio ----

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

    return chosen.value === 0
        ? 1
        : fractionRatio(
              chosen.value,
              turnedSize(size.value, edit.value.rotate),
          );
});

// ---- undo and redo ----

type Step = { edit: PhotoEdit; ratio: string };

const history = ref<Step[]>([]);
const at = ref(0);

/** Keep where the edit has got to, as one step to go back over. */
const record = () => {
    const last = history.value[at.value];

    if (
        last &&
        last.ratio === ratio.value &&
        JSON.stringify(last.edit) === JSON.stringify(edit.value)
    ) {
        return;
    }

    history.value = [
        ...history.value.slice(0, at.value + 1),
        { edit: edit.value, ratio: ratio.value },
    ];
    at.value = history.value.length - 1;
};

const goTo = (step: number) => {
    at.value = step;
    edit.value = history.value[step].edit;
    ratio.value = history.value[step].ratio;
};

const canUndo = computed(() => at.value > 0);
const canRedo = computed(() => at.value < history.value.length - 1);
const undo = () => canUndo.value && goTo(at.value - 1);
const redo = () => canRedo.value && goTo(at.value + 1);

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
    comparing.value = false;
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
            start = fullEdit(found.edit);
        }

        // Another site's picture can only be saved if it lets us read it
        const loaded = foreign(url)
            ? await loadImage(url, true).catch(() => loadImage(url, false))
            : await loadImage(url, false);
        size.value = {
            width: loaded.naturalWidth || 1000,
            height: loaded.naturalHeight || 1000,
        };
        begun.value = start;
        edit.value = start;
        history.value = [{ edit: start, ratio: 'free' }];
        at.value = 0;
        image.value = loaded;
    } catch (thrown) {
        error.value = (thrown as Error).message;
    } finally {
        loading.value = false;
    }
};

// ---- the tools ----

const chooseRatio = (id: string) => {
    ratio.value = id;

    if (held.value) {
        edit.value = { ...edit.value, crop: largestCrop(held.value) };
    }

    record();
};

const shapes: { shape: Shape; label: string; icon: Component }[] = [
    { shape: 'rect', label: 'Rectangle', icon: RectangleHorizontal },
    { shape: 'rounded', label: 'Rounded', icon: Squircle },
    { shape: 'circle', label: 'Circle', icon: Circle },
];

/** A circle starts square; any other ratio can be chosen after. */
const chooseShape = (shape: Shape) => {
    edit.value = { ...edit.value, shape };

    if (shape === 'circle' && ratio.value !== '1:1') {
        chooseRatio('1:1');
    } else {
        record();
    }
};

const setAngle = (angle: number) => {
    edit.value = { ...edit.value, angle };
};

/** The size to save at, as the select holds it. */
const saveSize = computed({
    get: () => String(edit.value.maxSide),
    set: (value: string) => {
        edit.value = { ...edit.value, maxSide: Number(value) };
        record();
    },
});

const sizeName = computed(
    () =>
        SAVE_SIZES.find((option) => option.value === edit.value.maxSide)
            ?.label ?? 'Full size',
);

/** How large each size comes out, in pixels, for this picture as edited. */
const sizeLabel = (maxSide: number) => {
    const sized = { ...edit.value, maxSide };
    const out = outputSize(
        size.value,
        sized,
        saveScale(size.value, sized, mime.value),
    );

    return `${out.width} × ${out.height}`;
};

const turnBy = (clockwise: boolean) => {
    edit.value = turn(edit.value, clockwise);
    // A 16:9 crop turned is a 9:16 one
    ratio.value =
        ratios.find((each) => each.id === ratio.value)?.inverse ?? 'free';
    record();
};

const flipAcross = (across: 'x' | 'y') => {
    edit.value = flip(edit.value, across);
    record();
};

const hideStyles: { style: HideStyle; label: string; icon: Component }[] = [
    { style: 'blur', label: 'Blur', icon: Blend },
    { style: 'pixelate', label: 'Pixelate', icon: Grid3x3 },
    { style: 'fill', label: 'Black box', icon: Square },
];

/** A box to hide something under, in the middle of what the crop keeps. */
const hideArea = (style: HideStyle) => {
    edit.value = {
        ...edit.value,
        hidden: [...edit.value.hidden, newHidden(edit.value.crop, style)],
    };
    record();
};

const sliders: {
    key: LightKey;
    label: string;
    icon: Component;
    min: number;
    max: number;
}[] = [
    { key: 'brightness', label: 'Brightness', icon: Sun, min: 0, max: 200 },
    { key: 'contrast', label: 'Contrast', icon: Contrast, min: 0, max: 200 },
    { key: 'saturation', label: 'Saturation', icon: Droplet, min: 0, max: 200 },
    { key: 'warmth', label: 'Warmth', icon: Thermometer, min: -100, max: 100 },
    { key: 'vignette', label: 'Vignette', icon: Aperture, min: 0, max: 100 },
];

/** A slider's setting as it reads: 0 for as the picture was taken. */
const reading = (key: LightKey) => {
    const away = edit.value[key] - UNEDITED[key];

    return `${away > 0 ? '+' : ''}${away}`;
};

const setLight = (key: LightKey, value: number) => {
    edit.value = { ...edit.value, [key]: value };
};

const pickLook = (light: Light) => {
    edit.value = { ...edit.value, ...light };
    record();
};

const reset = () => {
    edit.value = UNEDITED;
    ratio.value = 'free';
    record();
};

/**
 * Ctrl+Z and Ctrl+Shift+Z (or Ctrl+Y) undo and redo; holding \ shows the
 * original. Arrow keys belong to the crop box and the sliders.
 */
const onKey = (event: KeyboardEvent) => {
    const command = event.ctrlKey || event.metaKey;
    const key = event.key.toLowerCase();

    // The keys are the editor's while it is open: a board behind it would
    // otherwise undo, or move what is chosen on it, as well. Escape and Tab
    // still reach the dialog, to close it and keep the focus inside it.
    if (key !== 'escape' && key !== 'tab') {
        event.stopPropagation();
    }

    const down = event.type === 'keydown';

    if (command && key === 'z') {
        event.preventDefault();

        if (down) {
            (event.shiftKey ? redo : undo)();
        }
    } else if (command && key === 'y') {
        event.preventDefault();

        if (down) {
            redo();
        }
    } else if (key === '\\') {
        comparing.value = down;
    }
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
        <DialogContent
            class="sm:max-w-5xl"
            data-test="photo-editor"
            @keydown="onKey"
            @keyup="onKey"
        >
            <DialogHeader>
                <DialogTitle>Edit photo</DialogTitle>
                <DialogDescription>
                    Crop, turn and adjust it. The original stays in your Drive,
                    so you can come back to it.
                </DialogDescription>
            </DialogHeader>

            <div class="flex min-w-0 flex-col gap-4 md:flex-row">
                <div class="relative h-[min(60vh,560px)] min-w-0 flex-1">
                    <div
                        v-if="loading"
                        class="bg-muted flex size-full items-center justify-center rounded-md"
                    >
                        <LoaderCircle
                            class="text-muted-foreground size-6 animate-spin"
                        />
                    </div>
                    <PhotoStage
                        v-else-if="image"
                        v-model:edit="edit"
                        :image="image"
                        :size="size"
                        :ratio="held"
                        :comparing="comparing"
                        @settle="record"
                    >
                        <button
                            type="button"
                            class="bg-background/90 hover:bg-background absolute top-2 right-2 flex touch-none items-center gap-1.5 rounded-md border px-2 py-1 text-xs shadow-sm select-none"
                            :class="comparing && 'ring-primary ring-2'"
                            :aria-pressed="comparing"
                            title="Hold to see the original (or hold \)"
                            data-test="photo-compare"
                            @pointerdown="
                                (
                                    $event.currentTarget as Element
                                ).setPointerCapture($event.pointerId);
                                comparing = true;
                            "
                            @pointerup="comparing = false"
                            @pointercancel="comparing = false"
                        >
                            <Eye class="size-3.5" />
                            Hold to compare
                        </button>
                    </PhotoStage>
                </div>

                <div
                    class="flex flex-col gap-5 md:max-h-[min(60vh,560px)] md:w-64 md:overflow-y-auto md:pr-1"
                >
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
                        <div class="mt-1.5 grid grid-cols-3 gap-1">
                            <button
                                v-for="each in shapes"
                                :key="each.shape"
                                type="button"
                                class="flex items-center justify-center gap-1 rounded-md border px-1 py-1 text-xs"
                                :class="
                                    edit.shape === each.shape
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'hover:bg-accent'
                                "
                                :aria-pressed="edit.shape === each.shape"
                                :data-test="`shape-${each.shape}`"
                                @click="chooseShape(each.shape)"
                            >
                                <component :is="each.icon" class="size-3.5" />
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
                                @click="flipAcross('x')"
                            >
                                <FlipHorizontal2 class="size-4" />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Flip upside down"
                                title="Flip upside down"
                                data-test="flip-y"
                                @click="flipAcross('y')"
                            >
                                <FlipVertical2 class="size-4" />
                            </Button>
                        </div>
                        <label class="mt-3 block">
                            <span
                                class="text-muted-foreground mb-1 flex items-center text-xs"
                            >
                                Straighten
                                <span class="ml-auto tabular-nums"
                                    >{{ edit.angle > 0 ? '+' : ''
                                    }}{{ edit.angle }}°</span
                                >
                            </span>
                            <input
                                type="range"
                                min="-45"
                                max="45"
                                step="0.5"
                                class="accent-primary w-full"
                                :value="edit.angle"
                                data-test="straighten"
                                @input="
                                    setAngle(
                                        Number(
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        ),
                                    )
                                "
                                @change="record"
                                @dblclick="
                                    setAngle(0);
                                    record();
                                "
                            />
                        </label>
                    </section>

                    <section>
                        <h3 class="mb-1 text-xs font-medium">Hide</h3>
                        <p class="text-muted-foreground mb-2 text-xs">
                            Cover a face, a number plate or a password. A black
                            box hides text for certain.
                        </p>
                        <div class="grid grid-cols-3 gap-1">
                            <button
                                v-for="each in hideStyles"
                                :key="each.style"
                                type="button"
                                class="hover:bg-accent flex flex-col items-center gap-1 rounded-md border px-1 py-1.5 text-xs"
                                :data-test="`hide-${each.style}`"
                                @click="hideArea(each.style)"
                            >
                                <component :is="each.icon" class="size-4" />
                                {{ each.label }}
                            </button>
                        </div>
                    </section>

                    <section v-if="image">
                        <h3 class="mb-2 text-xs font-medium">Looks</h3>
                        <PhotoLooks
                            :image="image"
                            :size="size"
                            :edit="edit"
                            @pick="pickLook"
                        />
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
                                    {{ reading(slider.key) }}
                                </span>
                            </span>
                            <input
                                type="range"
                                :min="slider.min"
                                :max="slider.max"
                                class="accent-primary w-full"
                                :value="edit[slider.key]"
                                :data-test="`light-${slider.key}`"
                                @input="
                                    setLight(
                                        slider.key,
                                        Number(
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        ),
                                    )
                                "
                                @change="record"
                                @dblclick="
                                    setLight(slider.key, UNEDITED[slider.key]);
                                    record();
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
                <div class="flex gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Undo"
                        title="Undo (Ctrl+Z)"
                        :disabled="!canUndo"
                        data-test="photo-undo"
                        @click="undo"
                    >
                        <Undo2 class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Redo"
                        title="Redo (Ctrl+Shift+Z)"
                        :disabled="!canRedo"
                        data-test="photo-redo"
                        @click="redo"
                    >
                        <Redo2 class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        :disabled="!image || isUnedited(edit)"
                        data-test="photo-reset"
                        @click="reset"
                    >
                        <RotateCcw class="size-4" /> Reset to original
                    </Button>
                </div>
                <div class="flex gap-2">
                    <Select v-model="saveSize">
                        <SelectTrigger
                            class="w-44"
                            aria-label="Size to save at"
                            data-test="save-size"
                        >
                            <!-- Its own words: the size follows the picture as it changes -->
                            <span class="truncate">
                                {{ sizeName }}
                                <span class="text-muted-foreground">{{
                                    sizeLabel(edit.maxSide)
                                }}</span>
                            </span>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in SAVE_SIZES"
                                :key="option.value"
                                :value="String(option.value)"
                                :data-test="`save-size-${option.value}`"
                            >
                                {{ option.label }}
                                <span class="text-muted-foreground text-xs">
                                    {{ sizeLabel(option.value) }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
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
