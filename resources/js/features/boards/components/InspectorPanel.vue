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
    Minus,
    PanelRightClose,
    Plus,
    RotateCw,
    SendToBack,
    SlidersHorizontal,
    Trash2,
} from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import {
    chipFrom,
    isSymbol,
    pinList,
    symbolOf,
    withWords,
    wordsOf,
} from '@/features/boards/circuit/symbols';
import { HEAD_TYPES } from '@/features/boards/composables/connectors';
import { SIDES } from '@/features/boards/composables/geometry';
import {
    FONT_FAMILIES,
    hasOutline,
    hasText,
} from '@/features/boards/composables/items';
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
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
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
    turn: [];
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

const families: { value: FontFamily; label: string }[] = [
    { value: 'sans', label: 'Sans' },
    { value: 'serif', label: 'Serif' },
    { value: 'mono', label: 'Mono' },
];

// Where it is and how big, in numbers: seldom wanted, so folded away, and
// left however the person last had it
const geometryOpen = useLocalStorage('board.geometry', false);

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

// A symbol's words are set beside it, the one way; they have a place of
// their own below
const labelled = computed(() =>
    props.selection.filter((item) => hasText(item) && !isSymbol(item)),
);

// ------------------------------------------------------------------ Symbols
const symbols = computed(() => props.selection.filter(isSymbol));

// Nothing here but lines: no fill to choose
const lineOnly = computed(() =>
    props.selection.every((item) => isSymbol(item) || item.kind === 'arrow'),
);

// The first shape with an edge of its own, for how heavy that edge is
const outlined = computed(() => props.selection.find(hasOutline) ?? null);

// One part chosen on its own, for its name and its value
const part = computed(() => {
    const item = props.selection.length === 1 ? symbols.value[0] : undefined;
    const symbol = item ? symbolOf(item.symbol) : undefined;

    return item && symbol && symbol.labels !== 'none'
        ? { item, symbol, ...wordsOf(item) }
        : null;
});

/**
 * A module's pins typed anew: it becomes a chip with those pins, the size
 * they need. A wire on a pin that is still there stays on it.
 */
const onPins = (event: Event) => {
    const symbol = symbolOf(chipFrom((event.target as HTMLInputElement).value));

    if (symbol && symbol.pins.length) {
        emit('update', {
            symbol: symbol.id,
            width: symbol.width,
            height: symbol.height,
        });
    }
};

const onWord = (field: 'name' | 'value', event: Event) => {
    const chosen = part.value;

    if (!chosen) {
        return;
    }

    const typed = (event.target as HTMLInputElement).value;

    emit('update', {
        text: chosen.symbol.prefix
            ? withWords(
                  field === 'name' ? typed : chosen.name,
                  field === 'value' ? typed : chosen.value,
              )
            : typed.trim(),
    });
};
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

    if (props.selection.length > 1) {
        return `${props.selection.length} items`;
    }

    const [item] = props.selection;

    return (isSymbol(item) && symbolOf(item.symbol)?.label) || item.kind;
});

const round = (value: number) => Math.round(value);

/** The label's size, a step up or down. */
const stepFontSize = (by: number) => {
    if (label.value) {
        emit('update', {
            fontSize: Math.min(
                400,
                Math.max(6, round(label.value.fontSize) + by),
            ),
        });
    }
};

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
                    v-if="symbols.length"
                    type="button"
                    title="Turn a quarter turn (R)"
                    data-test="symbol-turn"
                    @click="emit('turn')"
                >
                    <RotateCw class="size-4" />
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

            <!-- A part's name and its value, the two things said of it -->
            <div v-if="part" class="inspector-part" data-test="symbol-config">
                <label v-if="part.symbol.prefix">
                    Name
                    <input
                        type="text"
                        :value="part.name"
                        :placeholder="`${part.symbol.prefix}1`"
                        data-test="symbol-name"
                        @change="onWord('name', $event)"
                    />
                </label>
                <label>
                    {{ part.symbol.prefix ? 'Value' : 'Name' }}
                    <input
                        type="text"
                        :value="part.symbol.prefix ? part.value : part.name"
                        :placeholder="part.symbol.hint"
                        data-test="symbol-value"
                        @change="onWord('value', $event)"
                    />
                </label>
                <label
                    v-if="
                        part.symbol.logic === 'input' ||
                        part.symbol.logic === 'clock'
                    "
                    class="inspector-switch"
                    title="Click it on the board to flip it. A switch that is on feeds the circuit a 1; a clock that is on ticks."
                >
                    On
                    <Switch
                        :model-value="part.item.on"
                        data-test="symbol-on"
                        @update:model-value="emit('update', { on: $event })"
                    />
                </label>
                <label
                    v-if="part.symbol.module"
                    title="The pins down its left, then after a | those down its right"
                >
                    Pins
                    <input
                        type="text"
                        :value="pinList(part.symbol)"
                        placeholder="VCC, GND | OUT"
                        data-test="symbol-pins"
                        @change="onPins"
                    />
                </label>
            </div>

            <!-- Its colours: a chip each, opened for the swatches and for
                 any other colour, rather than both palettes always out -->
            <div class="inspector-colours">
                <Popover v-if="!lineOnly">
                    <PopoverTrigger as-child>
                        <button
                            type="button"
                            title="Fill colour"
                            data-test="fill-chip"
                        >
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
                    <PopoverContent side="left" align="start" class="w-64 p-3">
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
                    <PopoverContent side="left" align="start" class="w-64 p-3">
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
                <input
                    type="range"
                    min="0"
                    max="12"
                    :value="outlined.lineWidth"
                    data-test="outline-width"
                    @input="
                        emit('update', {
                            lineWidth: Number(
                                ($event.target as HTMLInputElement).value,
                            ),
                        })
                    "
                />
                <span>{{ outlined.lineWidth }}</span>
            </label>

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

                <!-- Its type: face and size, room round it, light Markdown -->
                <div class="inspector-row" data-test="font-config">
                    <select
                        class="inspector-select"
                        aria-label="Font"
                        :value="label.fontFamily"
                        :style="{ fontFamily: FONT_FAMILIES[label.fontFamily] }"
                        data-test="prop-font"
                        @change="
                            emit('update', {
                                fontFamily: ($event.target as HTMLSelectElement)
                                    .value as FontFamily,
                            })
                        "
                    >
                        <option
                            v-for="option in families"
                            :key="option.value"
                            :value="option.value"
                            :style="{ fontFamily: FONT_FAMILIES[option.value] }"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <div class="inspector-stepper" title="Text size">
                        <button
                            type="button"
                            title="Smaller"
                            data-test="font-smaller"
                            @click="stepFontSize(-2)"
                        >
                            <Minus class="size-3.5" />
                        </button>
                        <input
                            type="number"
                            min="6"
                            max="400"
                            aria-label="Text size"
                            :value="round(label.fontSize)"
                            data-test="prop-font-size"
                            @change="onLabelNumber('fontSize', 6, 400, $event)"
                        />
                        <button
                            type="button"
                            title="Larger"
                            data-test="font-larger"
                            @click="stepFontSize(2)"
                        >
                            <Plus class="size-3.5" />
                        </button>
                    </div>
                </div>
                <label
                    class="inspector-slider"
                    title="Room between the edge and the words"
                >
                    Padding
                    <input
                        type="range"
                        min="0"
                        :max="Math.max(64, round(label.padding))"
                        :value="round(label.padding)"
                        data-test="prop-padding"
                        @input="onLabelNumber('padding', 0, 200, $event)"
                    />
                    <span>{{ round(label.padding) }}</span>
                </label>
                <label
                    class="inspector-switch"
                    title="# heading, - bullet, **bold**, *italic*"
                >
                    Markdown
                    <Switch
                        :model-value="label.rich"
                        data-test="prop-rich"
                        @update:model-value="emit('update', { rich: $event })"
                    />
                </label>
            </div>

            <!-- Whether the frame is a page of the PDF -->
            <div v-if="frames.length" class="inspector-align">
                <label class="inspector-switch">
                    Hide in PDF
                    <Switch
                        :model-value="frames.every((frame) => frame.pdfHidden)"
                        data-test="prop-pdf-hidden"
                        @update:model-value="
                            emit('update', { pdfHidden: $event })
                        "
                    />
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

            <!-- Where it is and how big, to the pixel: there when wanted -->
            <details
                v-if="one"
                class="inspector-fold"
                :open="geometryOpen"
                data-test="geometry"
                @toggle="
                    geometryOpen = ($event.target as HTMLDetailsElement).open
                "
            >
                <summary>
                    Position and size
                    <span
                        >{{ round(one.width) }} × {{ round(one.height) }}</span
                    >
                </summary>
                <div class="inspector-grid">
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
                            data-test="prop-y"
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
                            data-test="prop-height"
                            @change="onNumber('height', $event)"
                        />
                    </label>
                </div>
            </details>
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

/* A part's name and value: a short word, then the room to type in */
.inspector-part {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}
.inspector-part label:not(.inspector-switch) {
    display: grid;
    grid-template-columns: 3rem minmax(0, 1fr);
    align-items: center;
    gap: 0.375rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}
.inspector-part input {
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

/* Something on or off: its name, and a switch at the far end */
.inspector-switch {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    min-height: 1.75rem;
    font-size: 0.75rem;
    color: var(--foreground);
    cursor: pointer;
}

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
.inspector-chip {
    width: 1.125rem;
    height: 1.125rem;
    flex-shrink: 0;
    border: 1px solid rgb(0 0 0 / 0.2);
    border-radius: 999px;
}
/* A line's colour is a ring, as a line is */
.inspector-chip.is-line {
    background-color: transparent;
    border-width: 3px;
}
.inspector-chip.is-none {
    border: 1px solid var(--border);
    background: linear-gradient(
        to top left,
        transparent calc(50% - 1px),
        #ef4444 calc(50% - 1px),
        #ef4444 calc(50% + 1px),
        transparent calc(50% + 1px)
    );
}

.inspector-row {
    display: flex;
    gap: 0.375rem;
}
.inspector-select {
    flex: 1;
    min-width: 0;
    height: 1.75rem;
    padding: 0 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

/* A number with a step either side of it */
.inspector-stepper {
    display: flex;
    align-items: stretch;
    height: 1.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.inspector-stepper button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.625rem;
    color: var(--muted-foreground);
    cursor: pointer;
}
.inspector-stepper button:hover {
    color: var(--foreground);
    background-color: var(--muted);
}
.inspector-stepper input {
    width: 2.5rem;
    padding: 0;
    font-size: 0.75rem;
    text-align: center;
    color: var(--foreground);
    background-color: var(--background);
    border: 0;
    border-inline: 1px solid var(--border);
    appearance: textfield;
    -moz-appearance: textfield;
}
.inspector-stepper input::-webkit-outer-spin-button,
.inspector-stepper input::-webkit-inner-spin-button {
    appearance: none;
    margin: 0;
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
.inspector-slider input {
    accent-color: var(--primary);
}
.inspector-slider span {
    width: 1.25rem;
    text-align: right;
    color: var(--foreground);
}
</style>
