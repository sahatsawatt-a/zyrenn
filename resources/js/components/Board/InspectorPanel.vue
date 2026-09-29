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
    Play,
    SendToBack,
    Trash2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { HEAD_TYPES } from '../../composables/board/connectors';
import { SIDES } from '../../composables/board/geometry';
import { hasText } from '../../composables/board/items';
import type {
    Align,
    Item,
    LineStyle,
    Routing,
    Side,
    VerticalAlign,
} from '../../composables/board/items';
import ColourPicker from './ColourPicker.vue';
import LayersPanel from './LayersPanel.vue';
import ConnectorSettings from './ConnectorSettings.vue';

// Lucidchart's right-hand panel: what is selected, and every property of it in
// one place instead of hidden behind a toolbar popover.
const props = defineProps<{
    selection: Item[];
    fill: string;
    stroke: string;
    items: Item[];
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
    select: [{ id: string; add: boolean }];
    move: [{ id: string; index: number }];
    'toggle-layer': [{ id: string; field: 'hidden' | 'locked' }];
    update: [Partial<Item>];
    present: [];
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
            </div>

            <!-- Connector-only controls, when a line is what is selected -->
            <ConnectorSettings
                v-if="line"
                :line="line"
                @update="emit('update', $event)"
            />

            <ColourPicker
                v-if="!line"
                :model-value="fill"
                label="Fill"
                @update:model-value="emit('paint', $event)"
            />

            <div class="inspector-line">
                <span class="inspector-line-label">Line</span>
                <span
                    class="inspector-chip"
                    :style="{ backgroundColor: stroke }"
                />
                <input
                    type="color"
                    :value="stroke"
                    data-test="stroke-input"
                    @input="
                        emit(
                            'paint-stroke',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </div>
        </div>

        <p v-else class="inspector-hint">
            Pick a shape from the left and drag it out on the canvas. Select
            something to change its colour, size and stacking.
        </p>

        <LayersPanel
            :items="items"
            :selection="selection.map((item) => item.id)"
            @select="emit('select', $event)"
            @move="emit('move', $event)"
            @toggle="emit('toggle-layer', $event)"
        />

        <button
            type="button"
            class="inspector-present"
            :disabled="!frameCount"
            data-test="present"
            @click="emit('present')"
        >
            <Play class="size-4" />
            Present {{ frameCount }} frame{{ frameCount === 1 ? '' : 's' }}
        </button>
    </aside>
</template>

<style scoped>
.inspector {
    display: flex;
    flex-direction: column;
    width: 16rem;
    flex-shrink: 0;
    min-height: 0;
    gap: 0.75rem;
    padding: 0.75rem;
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    overflow-y: auto;
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

.inspector-line {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
}
.inspector-line-label {
    color: var(--muted-foreground);
}
.inspector-chip {
    width: 1.25rem;
    height: 1.25rem;
    border: 1px solid var(--border);
    border-radius: 999px;
}
.inspector-line input {
    width: 2rem;
    height: 1.75rem;
    padding: 0;
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.inspector-present {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    height: 2.25rem;
    margin-top: auto;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-radius: var(--radius-lg);
    cursor: pointer;
}
.inspector-present:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
