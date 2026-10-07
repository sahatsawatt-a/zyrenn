<script setup lang="ts">
import {
    AlignCenterHorizontal,
    AlignCenterVertical,
    AlignEndHorizontal,
    AlignEndVertical,
    AlignStartHorizontal,
    AlignStartVertical,
    BringToFront,
    ChevronDown,
    ChevronUp,
    Copy,
    PanelRightClose,
    SendToBack,
    SlidersHorizontal,
    Trash2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { HEAD_TYPES } from '@/features/boards/composables/connectors';
import { SIDES } from '@/features/boards/composables/geometry';
import { FONT_FAMILIES, hasText } from '@/features/boards/composables/items';
import type {
    Align,
    Fit,
    FontFamily,
    Item,
    LineStyle,
    Routing,
    Side,
    VerticalAlign,
} from '@/features/boards/composables/items';
import PhotoEditor from '@/components/photo/PhotoEditor.vue';
import ColourPicker from './ColourPicker.vue';
import ConnectorSettings from './ConnectorSettings.vue';

// The panel over the right of the canvas: what is selected, and every property
// of it in one place instead of hidden behind a toolbar popover -- colour
// first, as the thing most often changed. The layers have a panel of their own
// on the left.
const props = defineProps<{
    selection: Item[];
    fill: string;
    stroke: string;
    itemCount: number;
    frameCount: number;
    // What a selected connector joins, e.g. "rect → database"
    link?: string;
}>();

const emit = defineEmits<{
    paint: [string];
    'paint-stroke': [string];
    resize: [{ width?: number; height?: number; x?: number; y?: number }];
    duplicate: [];
    remove: [];
    reorder: ['front' | 'back' | 'forward' | 'backward'];
    update: [Partial<Item>];
    close: [];
}>();

const connectors = computed(() =>
    props.selection.filter((item) => item.kind === 'arrow'),
);

// Connector controls act on all of them at once; the buttons show the first
// one's setting, which is what every drawing tool does with a mixed selection.
const line = computed(() => connectors.value[0] ?? null);

const routings: Routing[] = ['elbow', 'straight', 'curved'];
const styles: LineStyle[] = ['solid', 'dashed', 'dotted'];

// Everything but ink and connectors carries a label that can be lined up
// A picture chosen on its own, for how it fills its box
const picture = computed(() =>
    props.selection.length === 1 && props.selection[0].kind === 'image'
        ? props.selection[0]
        : null,
);

const editing = ref(false);

const fits: { value: Fit; label: string; title: string }[] = [
    { value: 'fill', label: 'Stretch', title: 'Stretched to the box' },
    { value: 'contain', label: 'Fit', title: 'Whole, inside the box' },
    { value: 'cover', label: 'Fill', title: 'Covering the box, edges cut off' },
];

const families: { value: FontFamily; label: string; title: string }[] = [
    { value: 'sans', label: 'Sans', title: 'Arial' },
    { value: 'serif', label: 'Serif', title: 'Times New Roman' },
    { value: 'mono', label: 'Mono', title: 'Courier New' },
];

/** A number typed for every selected label, held to what makes sense. */
const onLabelNumber = (
    field: 'fontSize' | 'padding',
    least: number,
    most: number,
    event: Event,
) => {
    const value = Number((event.target as HTMLInputElement).value);

    if (Number.isFinite(value)) {
        emit('update', { [field]: Math.min(most, Math.max(least, value)) });
    }
};

const labelled = computed(() => props.selection.filter(hasText));
const label = computed(() => labelled.value[0] ?? null);

const aligns: { value: Align; icon: Component; title: string }[] = [
    {
        value: 'left',
        icon: AlignStartVertical,
        title: 'Line the label up left',
    },
    {
        value: 'center',
        icon: AlignCenterVertical,
        title: 'Centre the label',
    },
    {
        value: 'right',
        icon: AlignEndVertical,
        title: 'Line the label up right',
    },
];

const verticalAligns: {
    value: VerticalAlign;
    icon: Component;
    title: string;
}[] = [
    {
        value: 'top',
        icon: AlignStartHorizontal,
        title: 'Put the label at the top',
    },
    {
        value: 'middle',
        icon: AlignCenterHorizontal,
        title: 'Put the label in the middle',
    },
    {
        value: 'bottom',
        icon: AlignEndHorizontal,
        title: 'Put the label at the bottom',
    },
];

const pinnedSide = (end: 'from' | 'to'): Side | null =>
    line.value?.[end]?.side ?? null;

// The end keeps whatever it is pinned to; only its side changes
const pinSide = (end: 'from' | 'to', side: Side | null) => {
    const current = line.value?.[end];

    if (!current) {
        return;
    }

    emit('update', { [end]: { ...current, side } });
};

// Frames, and only frames: each is a page of the PDF unless kept out of it
const frames = computed(() =>
    props.selection.every((item) => item.kind === 'frame')
        ? props.selection
        : [],
);

const one = computed(() =>
    props.selection.length === 1 ? props.selection[0] : null,
);

const summary = computed(() => {
    if (!props.selection.length) {
        return 'Nothing selected';
    }

    return props.selection.length === 1
        ? props.selection[0].kind
        : `${props.selection.length} items`;
});

const round = (value: number) => Math.round(value);

const onNumber = (field: 'x' | 'y' | 'width' | 'height', event: Event) => {
    const value = Number((event.target as HTMLInputElement).value);

    if (Number.isFinite(value)) {
        emit('resize', { [field]: value });
    }
};
</script>

<template>
    <aside class="inspector" data-test="inspector">
        <div class="inspector-head">
            <div class="min-w-0 flex-1">
                <p class="inspector-title" data-test="inspector-title">
                    {{ summary }}
                </p>
                <p class="inspector-sub">
                    {{ itemCount }} items · {{ frameCount }} frames
                </p>
                <p v-if="link" class="inspector-sub" data-test="connector-link">
                    {{ link }}
                </p>
            </div>
            <button
                type="button"
                class="inspector-close"
                title="Hide this panel"
                data-test="inspector-close"
                @click="emit('close')"
            >
                <PanelRightClose class="size-4" />
            </button>
        </div>

        <div v-if="selection.length" class="inspector-body">
            <div class="inspector-actions">
                <button
                    type="button"
                    title="Bring to front (Ctrl+Shift+])"
                    data-test="to-front"
                    @click="emit('reorder', 'front')"
                >
                    <BringToFront class="size-4" />
                </button>
                <button
                    type="button"
                    title="Bring forward (Ctrl+])"
                    data-test="forward"
                    @click="emit('reorder', 'forward')"
                >
                    <ChevronUp class="size-4" />
                </button>
                <button
                    type="button"
                    title="Send backward (Ctrl+[)"
                    data-test="backward"
                    @click="emit('reorder', 'backward')"
                >
                    <ChevronDown class="size-4" />
                </button>
                <button
                    type="button"
                    title="Send to back (Ctrl+Shift+[)"
                    data-test="to-back"
                    @click="emit('reorder', 'back')"
                >
                    <SendToBack class="size-4" />
                </button>
                <button
                    type="button"
                    title="Duplicate (Ctrl+D)"
                    @click="emit('duplicate')"
                >
                    <Copy class="size-4" />
                </button>
                <button
                    type="button"
                    title="Delete"
                    data-test="inspector-delete"
                    @click="emit('remove')"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>

            <ColourPicker
                v-if="!line"
                :model-value="fill"
                label="Fill"
                @update:model-value="emit('paint', $event)"
            />
            <ColourPicker
                :model-value="stroke"
                label="Line"
                data-test="stroke-picker"
                @update:model-value="emit('paint-stroke', $event)"
            />

            <p v-if="one" class="inspector-label">Position and size</p>
            <div v-if="one" class="inspector-grid">
                <label>
                    X
                    <input
                        type="number"
                        :value="round(one.x)"
                        data-test="prop-x"
                        @change="onNumber('x', $event)"
                    />
                </label>
                <label>
                    Y
                    <input
                        type="number"
                        :value="round(one.y)"
                        @change="onNumber('y', $event)"
                    />
                </label>
                <label>
                    W
                    <input
                        type="number"
                        :value="round(one.width)"
                        data-test="prop-width"
                        @change="onNumber('width', $event)"
                    />
                </label>
                <label>
                    H
                    <input
                        type="number"
                        :value="round(one.height)"
                        @change="onNumber('height', $event)"
                    />
                </label>
            </div>

            <!-- Where the label sits in whatever it is written on -->
            <div v-if="label" class="inspector-align" data-test="align-config">
                <p class="inspector-label">Label</p>
                <div class="inspector-segments">
                    <button
                        v-for="option in aligns"
                        :key="option.value"
                        type="button"
                        :title="option.title"
                        :class="{ 'is-on': label.align === option.value }"
                        :data-test="`align-${option.value}`"
                        @click="emit('update', { align: option.value })"
                    >
                        <component :is="option.icon" class="size-4" />
                    </button>
                    <span class="inspector-divider" />
                    <button
                        v-for="option in verticalAligns"
                        :key="option.value"
                        type="button"
                        :title="option.title"
                        :class="{
                            'is-on': label.verticalAlign === option.value,
                        }"
                        :data-test="`valign-${option.value}`"
                        @click="emit('update', { verticalAlign: option.value })"
                    >
                        <component :is="option.icon" class="size-4" />
                    </button>
                </div>

                <!-- Its type: size, face, room round it, and light Markdown -->
                <div class="inspector-segments" data-test="font-config">
                    <button
                        v-for="option in families"
                        :key="option.value"
                        type="button"
                        :title="option.title"
                        :class="{ 'is-on': label.fontFamily === option.value }"
                        :style="{ fontFamily: FONT_FAMILIES[option.value] }"
                        :data-test="`font-${option.value}`"
                        @click="emit('update', { fontFamily: option.value })"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <div class="inspector-grid">
                    <label>
                        Size
                        <input
                            type="number"
                            min="6"
                            max="400"
                            :value="round(label.fontSize)"
                            data-test="prop-font-size"
                            @change="onLabelNumber('fontSize', 6, 400, $event)"
                        />
                    </label>
                    <label title="Room between the edge and the words">
                        Pad
                        <input
                            type="number"
                            min="0"
                            max="200"
                            :value="round(label.padding)"
                            data-test="prop-padding"
                            @change="onLabelNumber('padding', 0, 200, $event)"
                        />
                    </label>
                </div>
                <label
                    class="inspector-check"
                    title="# heading, - bullet, **bold**, *italic*"
                >
                    <input
                        type="checkbox"
                        :checked="label.rich"
                        data-test="prop-rich"
                        @change="
                            emit('update', {
                                rich: ($event.target as HTMLInputElement)
                                    .checked,
                            })
                        "
                    />
                    Markdown: # heading, - bullet, **bold**
                </label>
            </div>

            <!-- Whether the frame is a page of the PDF -->
            <div v-if="frames.length" class="inspector-align">
                <p class="inspector-label">PDF</p>
                <label class="inspector-check">
                    <input
                        type="checkbox"
                        :checked="frames.every((frame) => frame.pdfHidden)"
                        data-test="prop-pdf-hidden"
                        @change="
                            emit('update', {
                                pdfHidden: ($event.target as HTMLInputElement)
                                    .checked,
                            })
                        "
                    />
                    Hide in PDF
                </label>
            </div>

            <!-- How a picture fills its box -->
            <div v-if="picture" class="inspector-align" data-test="fit-config">
                <p class="inspector-label">Picture</p>
                <div class="inspector-segments">
                    <button
                        v-for="option in fits"
                        :key="option.value"
                        type="button"
                        :title="option.title"
                        :class="{ 'is-on': picture.fit === option.value }"
                        :data-test="`fit-${option.value}`"
                        @click="emit('update', { fit: option.value })"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <div class="inspector-segments">
                    <button
                        type="button"
                        class="gap-1.5"
                        data-test="edit-photo"
                        @click="editing = true"
                    >
                        <SlidersHorizontal :size="13" /> Edit photo
                    </button>
                </div>
                <PhotoEditor
                    v-if="editing"
                    v-model:open="editing"
                    :src="picture.src"
                    @saved="
                        emit('update', {
                            src: $event.src,
                            height: Math.round(
                                (picture.width * $event.height) / $event.width,
                            ),
                        })
                    "
                />
            </div>

            <!-- Connector-only controls, when a line is what is selected -->
            <ConnectorSettings
                v-if="line"
                :line="line"
                @update="emit('update', $event)"
            />
        </div>

        <p v-else class="inspector-hint">
            Pick a tool from the bar below and drag it out on the canvas, or
            click something to change its colour, size and stacking.
        </p>
    </aside>
</template>

<style scoped>
.inspector {
    display: flex;
    flex-direction: column;
    width: 16rem;
    flex-shrink: 0;
    min-height: 0;
    max-height: 100%;
    gap: 0.75rem;
    padding: 0.75rem;
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    box-shadow: 0 4px 16px -4px rgb(15 23 42 / 0.14);
    overflow-y: auto;
    overscroll-behavior: contain;
}
.inspector > * {
    flex-shrink: 0;
}

.inspector-head {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.inspector-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    margin: -0.25rem -0.25rem 0 0;
    color: var(--muted-foreground);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.inspector-close:hover {
    color: var(--foreground);
    background-color: var(--muted);
}

.inspector-title {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: capitalize;
}

.inspector-sub,
.inspector-hint {
    font-size: 0.75rem;
    color: var(--muted-foreground);
}

.inspector-body {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.inspector-actions {
    display: flex;
    gap: 2px;
}
.inspector-actions button {
    display: inline-flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    height: 2rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.inspector-actions button:hover {
    background-color: var(--muted);
}

.inspector-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.375rem;
}
.inspector-grid label {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}
.inspector-grid input {
    width: 100%;
    min-width: 0;
    height: 1.75rem;
    padding: 0 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}

.inspector-align {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.inspector-check {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
    cursor: pointer;
}

.inspector-connector {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.inspector-label {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.inspector-segments {
    display: flex;
    gap: 2px;
}
.inspector-segments button {
    display: inline-flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    height: 1.75rem;
    font-size: 0.6875rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.inspector-segments button:hover {
    background-color: var(--muted);
}
.inspector-segments button.is-on {
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-color: var(--primary);
}

.inspector-heads {
    display: flex;
    align-items: center;
    gap: 2px;
}
.inspector-heads span {
    width: 2.25rem;
    font-size: 0.6875rem;
    text-transform: capitalize;
    color: var(--muted-foreground);
}
.inspector-heads button {
    display: inline-flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    height: 1.75rem;
    color: var(--foreground);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.inspector-heads button:hover {
    background-color: var(--muted);
}
.inspector-heads button.is-on {
    color: var(--primary);
    border-color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
}

/* A fold keeps the fiddly settings out of the way until they are wanted */
.inspector-fold > summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.25rem 0;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
    cursor: pointer;
}
.inspector-fold > summary span {
    font-weight: 400;
    letter-spacing: 0;
    text-transform: none;
}
.inspector-fold[open] > summary {
    margin-bottom: 0.25rem;
}

/* Two short pickers, where ten buttons used to be */
.inspector-fields {
    display: flex;
    gap: 0.375rem;
}
.inspector-fields label {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.125rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}
.inspector-fields select {
    height: 1.75rem;
    padding: 0 0.25rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    text-transform: capitalize;
    cursor: pointer;
}

/* The sides read as words rather than pictures: "auto" has no icon to draw */
.inspector-sides {
    display: flex;
    align-items: center;
    gap: 2px;
}
.inspector-sides span {
    width: 2.25rem;
    font-size: 0.6875rem;
    text-transform: capitalize;
    color: var(--muted-foreground);
}
.inspector-sides button {
    flex: 1;
    padding: 0.25rem 0;
    font-size: 0.6875rem;
    color: var(--foreground);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.inspector-sides button:hover {
    background-color: var(--muted);
}
.inspector-sides button.is-on {
    color: var(--primary);
    border-color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
}

/* Keeps the two halves of the label controls apart */
.inspector-divider {
    width: 1px;
    height: 1.25rem;
    margin: 0 0.25rem;
    background-color: var(--border);
}

.inspector-slider {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}
.inspector-slider input {
    flex: 1;
    min-width: 0;
}
.inspector-slider span {
    width: 1.25rem;
    text-align: right;
    color: var(--foreground);
}
</style>
