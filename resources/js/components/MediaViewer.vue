<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    HardDrive,
    Maximize,
    Minus,
    Plus,
    X,
} from '@lucide/vue';
import { useElementSize, useEventListener, useScrollLock } from '@vueuse/core';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useMediaViewer } from '@/composables/useMediaViewer';

const { items, index, isOpen, current, close, step } = useMediaViewer();

// Zoom resizes the element rather than CSS-scaling it: a transform: scale()
// stretches a bitmap rasterised at the fitted size, which blurs photos and
// turns vector diagrams into pixels. Real dimensions make the browser redraw
// at every zoom level, from the full-resolution source.
const MIN_SCALE = 0.1;
// Photos can go to 8x their own pixels; diagrams are vectors and stay sharp
const MAX_ACTUAL = 8;

const view = reactive({ scale: 1, x: 0, y: 0 });
const stage = ref<HTMLElement | null>(null);
const content = ref<HTMLElement | null>(null);
const stageSize = useElementSize(stage);

// The item's own size: image pixels, or a diagram's viewBox
const natural = reactive({ width: 0, height: 0 });

// Padding around a diagram's card (p-6 on each side)
const CARD_PADDING = 48;

// Scale at which the whole item fits the stage. Photos are never enlarged to
// fit; diagrams are, since they have no pixels to lose.
const fit = computed(() => {
    if (!natural.width || !natural.height || !stageSize.width.value) {
        return 1;
    }

    const padding = current.value?.type === 'svg' ? CARD_PADDING : 0;
    const scale = Math.min(
        (stageSize.width.value * 0.94 - padding) / natural.width,
        (stageSize.height.value * 0.96 - padding) / natural.height,
    );

    return current.value?.type === 'image' ? Math.min(1, scale) : scale;
});

// Size relative to the item's own pixels (1 = actual size)
const actual = computed(() => fit.value * view.scale);

const box = computed(() =>
    natural.width && stageSize.width.value
        ? {
              width: `${natural.width * actual.value}px`,
              height: `${natural.height * actual.value}px`,
          }
        : undefined,
);

const maxScale = computed(() => Math.max(MAX_ACTUAL / fit.value, 2));

const reset = () => Object.assign(view, { scale: 1, x: 0, y: 0 });

const onImageLoad = (event: Event) => {
    const image = event.target as HTMLImageElement;
    Object.assign(natural, {
        width: image.naturalWidth,
        height: image.naturalHeight,
    });
};

const measureSvg = () => {
    const svg = stage.value?.querySelector<SVGSVGElement>('.viewer-svg svg');
    const viewBox = svg?.viewBox.baseVal;

    if (svg && viewBox?.width) {
        Object.assign(natural, {
            width: viewBox.width,
            height: viewBox.height,
        });
    } else if (svg) {
        const rect = svg.getBoundingClientRect();
        Object.assign(natural, { width: rect.width, height: rect.height });
    }
};

watch(current, async (item) => {
    reset();
    Object.assign(natural, { width: 0, height: 0 });

    if (item?.type === 'svg') {
        await nextTick();
        measureSvg();
    }
});

const bodyLock = useScrollLock(document.body);
watch(isOpen, (open) => (bodyLock.value = open));

const title = computed(() => {
    const item = current.value;

    if (!item) {
        return '';
    }

    return item.type === 'image' ? (item.alt ?? '') : (item.title ?? '');
});

// A diagram kept as a picture, by whoever opened it
const save = computed(() =>
    current.value?.type === 'svg' ? current.value.save : undefined,
);
const saveMenuOpen = ref(false);
const saving = ref(false);

type ImageType = 'png' | 'svg';

const imageTypes: { id: ImageType; label: string; hint: string }[] = [
    { id: 'png', label: 'PNG', hint: 'A picture, for slides and chats' },
    { id: 'svg', label: 'SVG', hint: 'Sharp at any size, and editable' },
];

const IMAGE_TYPE_KEY = 'zyrenn.diagramImageType';
const imageType = ref<ImageType>('png');

try {
    const saved = localStorage.getItem(IMAGE_TYPE_KEY);

    if (imageTypes.some((type) => type.id === saved)) {
        imageType.value = saved as ImageType;
    }
} catch {
    // Storage blocked: every save starts from PNG
}

function chooseImageType(type: ImageType): void {
    imageType.value = type;

    try {
        localStorage.setItem(IMAGE_TYPE_KEY, type);
    } catch {
        // Storage blocked: remembered for this visit only
    }
}

const saveAs = async (to: 'download' | 'drive') => {
    if (!save.value || saving.value) {
        return;
    }

    saving.value = true;

    try {
        await save.value.run(imageType.value, to);
    } finally {
        saving.value = false;
    }
};

// A video is played rather than looked over: no zoom, no panning, and the
// keys and clicks on it are its own player's
const playing = computed(() => current.value?.type === 'video');

const onVideoReady = (event: Event) => {
    const item = current.value;

    if (item?.type === 'video' && item.start) {
        (event.target as HTMLVideoElement).currentTime = item.start;
    }
};

// Zoom keeping the point under (clientX, clientY) fixed; the stage centre by default
const zoomTo = (scale: number, clientX?: number, clientY?: number) => {
    const next = Math.min(maxScale.value, Math.max(MIN_SCALE, scale));
    const rect = stage.value?.getBoundingClientRect();

    if (rect) {
        const cx =
            (clientX ?? rect.left + rect.width / 2) -
            rect.left -
            rect.width / 2;
        const cy =
            (clientY ?? rect.top + rect.height / 2) -
            rect.top -
            rect.height / 2;
        const ratio = next / view.scale;

        view.x = cx - (cx - view.x) * ratio;
        view.y = cy - (cy - view.y) * ratio;
    }

    view.scale = next;
};

const onWheel = (event: WheelEvent) => {
    if (playing.value) {
        return;
    }

    zoomTo(
        view.scale * Math.exp(-event.deltaY * 0.0015),
        event.clientX,
        event.clientY,
    );
};

// Drag to pan
let drag: { x: number; y: number; startX: number; startY: number } | null =
    null;

const onPointerDown = (event: PointerEvent) => {
    // Capturing the pointer would take it away from the video's own controls
    if (event.button !== 0 || playing.value) {
        return;
    }

    drag = {
        x: view.x,
        y: view.y,
        startX: event.clientX,
        startY: event.clientY,
    };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
};

const onPointerMove = (event: PointerEvent) => {
    if (drag) {
        view.x = drag.x + event.clientX - drag.startX;
        view.y = drag.y + event.clientY - drag.startY;
    }
};

const onPointerUp = (event: PointerEvent) => {
    // A click (not a drag) outside the image or diagram closes the viewer.
    // The content ignores pointer events so a drag can start anywhere, so
    // hit-test against its box instead of the event target.
    const moved =
        drag &&
        Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) >
            4;
    drag = null;

    if (moved || event.target !== stage.value) {
        return;
    }

    const rect = content.value?.getBoundingClientRect();
    const inside =
        rect &&
        event.clientX >= rect.left &&
        event.clientX <= rect.right &&
        event.clientY >= rect.top &&
        event.clientY <= rect.bottom;

    if (!inside) {
        close();
    }
};

// Fitted → actual pixels (or 2x when the item already shows at full size) → fitted
const onDoubleClick = (event: MouseEvent) => {
    if (playing.value) {
        return;
    }

    if (view.scale === 1) {
        zoomTo(Math.max(2, 1 / fit.value), event.clientX, event.clientY);
    } else {
        reset();
    }
};

useEventListener(
    window,
    'keydown',
    (event: KeyboardEvent) => {
        // An open menu has the keys (Escape shuts the menu, not the viewer)
        if (!isOpen.value || saveMenuOpen.value) {
            return;
        }

        // A lone video keeps the arrow keys, which seek it
        const stepping = !playing.value || items.value.length > 1;
        const actions: Record<string, () => void> = {
            Escape: close,
            ...(stepping
                ? { ArrowLeft: () => step(-1), ArrowRight: () => step(1) }
                : {}),
            ...(playing.value
                ? {}
                : {
                      '+': () => zoomTo(view.scale * 1.25),
                      '=': () => zoomTo(view.scale * 1.25),
                      '-': () => zoomTo(view.scale / 1.25),
                      '0': reset,
                      '1': () => zoomTo(1 / fit.value),
                  }),
        };

        const action = actions[event.key];

        if (action) {
            event.preventDefault();
            event.stopPropagation();
            action();
        }
    },
    { capture: true },
);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="isOpen && current"
            class="fixed inset-0 z-[100] flex flex-col bg-black/90 text-white backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            :aria-label="title || 'Preview'"
        >
            <!-- Toolbar -->
            <div class="flex items-center gap-2 px-4 py-3 text-sm">
                <p class="min-w-0 flex-1 truncate text-white/80">
                    {{ title }}
                    <span v-if="items.length > 1" class="ml-2 text-white/50">
                        {{ index + 1 }} / {{ items.length }}
                    </span>
                </p>

                <template v-if="!playing">
                    <button
                        type="button"
                        class="viewer-btn"
                        title="Zoom out (−)"
                        @click="zoomTo(view.scale / 1.25)"
                    >
                        <Minus class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="viewer-btn w-16 tabular-nums"
                        title="Actual size (1)"
                        @click="zoomTo(1 / fit)"
                    >
                        {{ Math.round(actual * 100) }}%
                    </button>
                    <button
                        type="button"
                        class="viewer-btn"
                        title="Zoom in (+)"
                        @click="zoomTo(view.scale * 1.25)"
                    >
                        <Plus class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="viewer-btn"
                        title="Fit to screen (0)"
                        @click="reset"
                    >
                        <Maximize class="size-4" />
                    </button>
                </template>
                <DropdownMenu v-if="save" v-model:open="saveMenuOpen">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="viewer-btn gap-1.5"
                            title="Save as a picture"
                            :disabled="saving"
                            data-test="viewer-save"
                        >
                            <Download class="size-4" />
                            Save
                        </button>
                    </DropdownMenuTrigger>
                    <!-- Above the viewer, which sits over everything else -->
                    <DropdownMenuContent align="end" class="z-[110] w-60">
                        <DropdownMenuLabel>File type</DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            :model-value="imageType"
                            @update:model-value="
                                chooseImageType($event as ImageType)
                            "
                        >
                            <!-- Picking a type keeps the menu open for the save -->
                            <DropdownMenuRadioItem
                                v-for="type in imageTypes"
                                :key="type.id"
                                :value="type.id"
                                :data-test="`viewer-save-type-${type.id}`"
                                @select.prevent
                            >
                                <span class="flex flex-col">
                                    <span>{{ type.label }}</span>
                                    <span class="text-muted-foreground text-xs">
                                        {{ type.hint }}
                                    </span>
                                </span>
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            data-test="viewer-save-download"
                            @select="saveAs('download')"
                        >
                            <Download />
                            Download {{ imageType.toUpperCase() }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="save.drive"
                            data-test="viewer-save-drive"
                            @select="saveAs('drive')"
                        >
                            <HardDrive />
                            Save {{ imageType.toUpperCase() }} to Drive
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <a
                    v-if="current.type !== 'svg'"
                    :href="current.src"
                    target="_blank"
                    rel="noopener"
                    class="viewer-btn"
                    title="Open original"
                >
                    <ExternalLink class="size-4" />
                </a>
                <button
                    type="button"
                    class="viewer-btn"
                    title="Close (Esc)"
                    @click="close"
                >
                    <X class="size-4" />
                </button>
            </div>

            <!-- Stage: wheel to zoom, drag to pan, double-click to toggle 2× -->
            <div
                ref="stage"
                class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden select-none"
                :class="
                    playing
                        ? ''
                        : 'cursor-grab touch-none active:cursor-grabbing'
                "
                @wheel.prevent="onWheel"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @dblclick="onDoubleClick"
            >
                <!-- Pan only moves (translate never resamples); zoom sets the real size -->
                <div
                    ref="content"
                    class="shrink-0"
                    :class="{ 'pointer-events-none': !playing }"
                    :style="{
                        transform: `translate(${view.x}px, ${view.y}px)`,
                    }"
                >
                    <video
                        v-if="current.type === 'video'"
                        :key="current.src"
                        :src="current.src"
                        :title="current.title"
                        class="block max-h-[calc(100vh-7rem)] max-w-[92vw] bg-black shadow-2xl"
                        data-test="viewer-video"
                        controls
                        autoplay
                        playsinline
                        @loadedmetadata="onVideoReady"
                    />
                    <img
                        v-else-if="current.type === 'image'"
                        :key="current.src"
                        :src="current.src"
                        :alt="current.alt ?? ''"
                        :style="box"
                        :class="
                            box
                                ? 'max-w-none'
                                : 'max-h-[calc(100vh-7rem)] max-w-[92vw] opacity-0'
                        "
                        class="block shadow-2xl"
                        decoding="async"
                        draggable="false"
                        @load="onImageLoad"
                    />
                    <div
                        v-else
                        class="bg-background text-foreground rounded-lg p-6 shadow-2xl"
                    >
                        <!-- eslint-disable-next-line vue/no-v-html -- Mermaid output, sanitised by its strict securityLevel -->
                        <div
                            class="viewer-svg"
                            :style="box"
                            v-html="current.svg"
                        ></div>
                    </div>
                </div>

                <template v-if="items.length > 1">
                    <button
                        type="button"
                        class="viewer-btn absolute left-4 size-10!"
                        title="Previous (←)"
                        @pointerdown.stop
                        @click="step(-1)"
                    >
                        <ChevronLeft class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="viewer-btn absolute right-4 size-10!"
                        title="Next (→)"
                        @pointerdown.stop
                        @click="step(1)"
                    >
                        <ChevronRight class="size-5" />
                    </button>
                </template>
            </div>

            <p v-if="!playing" class="pb-3 text-center text-xs text-white/40">
                Scroll to zoom · drag to move · double-click for actual size
            </p>
        </div>
    </Teleport>
</template>

<style scoped>
@reference '../../css/app.css';

.viewer-btn {
    @apply inline-flex h-8 min-w-8 items-center justify-center rounded-md bg-white/10 px-2 text-white/90 transition-colors hover:bg-white/20;
}

.viewer-svg {
    /* Until the diagram is measured */
    width: min(90vw, 1400px);
}

.viewer-svg :deep(svg) {
    display: block;
    width: 100% !important;
    max-width: none !important;
    height: 100% !important;
}
</style>
