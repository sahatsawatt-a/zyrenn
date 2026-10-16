<script setup lang="ts">
import { PanelRightClose } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import { Switch } from '@/components/ui/switch';
import { isSymbol, symbolOf } from '../circuit/symbols';
import DeckSettings from '../deck/DeckSettings.vue';
import type { Deck } from '../deck/deck';
import type { BuildStep } from '../deck/build';
import ItemAnimation from '../deck/ItemAnimation.vue';
import SlideAnimations from '../deck/SlideAnimations.vue';
import SlideSettings from '../deck/SlideSettings.vue';
import type { Item } from '../model/items';
import { hasText } from '../model/items';
import { hasHeader } from '../model/rows';
import { designsOf, shapeOf } from '../model/shapes';
import BoardSettings from './BoardSettings.vue';
import ColourChips from './ColourChips.vue';
import ConnectorSettings from './ConnectorSettings.vue';
import DesignPicker from './DesignPicker.vue';
import GeometryFold from './GeometryFold.vue';
import ItemActions from './ItemActions.vue';
import LabelSettings from './LabelSettings.vue';
import LookSettings from './LookSettings.vue';
import PartSettings from './PartSettings.vue';
import PictureSettings from './PictureSettings.vue';
import StructureSettings from './StructureSettings.vue';

// The panel over the right of the canvas: what is selected, and every property
// of it in one place instead of hidden behind a toolbar popover -- colour
// first, as the thing most often changed. Each kind of setting is a component
// of its own; this says which apply to what is selected. The layers have a
// panel of their own on the left.
const props = defineProps<{
    selection: Item[];
    fill: string;
    stroke: string;
    itemCount: number;
    // The selection is one whole group, to be undone
    grouped?: boolean;
    // It can be kept as a shape of one's own (not on the demo board)
    savable?: boolean;
    frameCount: number;
    // What a selected connector joins, e.g. "rect → database"
    link?: string;
    // The deck the frames make, once it can be changed
    deck?: Deck | null;
    deckReady?: boolean;
    // The slide one selected item is on, and what each item is called: for
    // animating it, and for a slide's list of animations
    slide?: Item | null;
    nameOf?: (id: string) => string;
}>();

const emit = defineEmits<{
    paint: [string];
    'paint-stroke': [string];
    resize: [{ width?: number; height?: number; x?: number; y?: number }];
    duplicate: [];
    remove: [];
    group: [];
    ungroup: [];
    'save-shape': [];
    reorder: ['front' | 'back' | 'forward' | 'backward'];
    update: [Partial<Item>];
    turn: [];
    close: [];
    // Changes that differ item by item: a style put on, as each kind takes it
    restyle: [Map<string, Partial<Item>>];
    // The whole board put in one colour set and style
    'restyle-board': [{ set: string; style: string }];
    // The deck changed, and every slide put in its look
    deck: [Deck | null];
    'restyle-deck': [];
    // A slide's animations changed, and one played through for its author
    animate: [slide: string, build: BuildStep[]];
    preview: [slide: Item];
}>();

// A picker opens beside the panel; on a phone, where the panel is a sheet
// across the foot of the screen, it opens above it
const narrow = useMediaQuery('(max-width: 719px)');
const beside = computed(() => (narrow.value ? 'top' : 'left'));

// The ways the selected kind can be drawn, when every one chosen is that kind
const designed = computed(() => {
    const [first] = props.selection;

    return first &&
        designsOf(first.kind).length &&
        props.selection.every((item) => item.kind === first.kind)
        ? first
        : null;
});

const one = computed(() =>
    props.selection.length === 1 ? props.selection[0] : null,
);

// Nothing here but lines: no fill, and none of a shape's looks
const lineOnly = computed(() =>
    props.selection.every((item) => isSymbol(item) || item.kind === 'arrow'),
);

// Connector controls act on all of them at once; the buttons show the first
// one's setting, which is what every drawing tool does with a mixed selection.
const line = computed(
    () => props.selection.find((item) => item.kind === 'arrow') ?? null,
);

// A symbol's words are set beside it, the one way; they have a place of
// their own (PartSettings)
const label = computed(
    () =>
        props.selection.find((item) => hasText(item) && !isSymbol(item)) ??
        null,
);

const symbols = computed(() => props.selection.filter(isSymbol));

// One part chosen on its own, for its name and its value
const part = computed(() =>
    one.value && isSymbol(one.value) ? one.value : null,
);

// One table, class or lane chosen on its own, for what is inside it
const structured = computed(() =>
    one.value &&
    hasHeader(one.value) &&
    // (a lifeline has a title and nothing else to set)
    !shapeOf(one.value.kind)?.slide
        ? one.value
        : null,
);

// A picture chosen on its own, for how it fills its box
const picture = computed(() =>
    one.value?.kind === 'image' ? one.value : null,
);

// Frames, and only frames: each is a page of the PDF unless kept out of it
const frames = computed(() =>
    props.selection.every((item) => item.kind === 'frame')
        ? props.selection
        : [],
);

const summary = computed(() => {
    if (!props.selection.length) {
        return 'Nothing selected';
    }

    if (props.selection.length > 1) {
        return `${props.selection.length} items`;
    }

    const [item] = props.selection;

    return (
        (isSymbol(item) && symbolOf(item.symbol)?.label) ||
        shapeOf(item.kind)?.label ||
        item.kind
    );
});
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
            <ItemActions
                :turnable="symbols.length > 0"
                :groupable="selection.length > 1"
                :grouped="grouped"
                :savable="savable"
                @reorder="emit('reorder', $event)"
                @turn="emit('turn')"
                @group="emit('group')"
                @ungroup="emit('ungroup')"
                @save-shape="emit('save-shape')"
                @duplicate="emit('duplicate')"
                @remove="emit('remove')"
            />

            <!-- A part's name and its value, the two things said of it -->
            <PartSettings
                v-if="part"
                :item="part"
                @update="emit('update', $event)"
            />

            <!-- What is inside it, when it has a structure of its own: a
                 table's columns, a class's members, a lane's title -->
            <StructureSettings
                v-if="structured"
                :item="structured"
                @update="emit('update', $event)"
            />

            <!-- The ways it can be drawn: a database as a cylinder, a
                 stack of discs, a card with an icon -->
            <div v-if="designed" class="inspector-section">
                <p class="inspector-label">Design</p>
                <DesignPicker
                    :kind="designed.kind"
                    :variant="designed.variant"
                    @pick="emit('update', { variant: $event })"
                />
            </div>

            <!-- Its colours and a ready-made style, and how heavy its edge is -->
            <ColourChips
                :selection="selection"
                :fill="fill"
                :stroke="stroke"
                :beside="beside"
                @update="emit('update', $event)"
                @paint="emit('paint', $event)"
                @paint-stroke="emit('paint-stroke', $event)"
                @restyle="emit('restyle', $event)"
            />

            <!-- Corners, how see-through, a fading fill, a box as tall as its
                 words, a picture's point in view, a video's still -->
            <LookSettings
                v-if="!lineOnly"
                :selection="selection"
                :beside="beside"
                @update="emit('update', $event)"
            />

            <!-- Where the label sits, its type and size, the room round it -->
            <LabelSettings
                v-if="label"
                :label="label"
                @update="emit('update', $event)"
            />

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

            <!-- A slide's background picture, its section and its notes -->
            <SlideSettings
                v-if="frames.length === 1"
                :frame="frames[0]"
                @update="emit('update', $event)"
            />
            <SlideAnimations
                v-if="frames.length === 1 && nameOf"
                :frame="frames[0]"
                :name-of="nameOf"
                @update="emit('update', $event)"
                @preview="emit('preview', frames[0])"
            />
            <DeckSettings
                v-if="frames.length && deckReady"
                :deck="deck ?? null"
                :side="beside"
                @update="emit('deck', $event)"
                @restyle="emit('restyle-deck')"
            />

            <!-- How a picture fills its box, and its photo editor -->
            <PictureSettings
                v-if="picture"
                :picture="picture"
                @update="emit('update', $event)"
            />

            <!-- Connector-only controls, when a line is what is selected -->
            <ConnectorSettings
                v-if="line"
                :line="line"
                @update="emit('update', $event)"
            />

            <!-- How it comes in, draws the eye or goes, on its slide -->
            <ItemAnimation
                v-if="one && slide && one.kind !== 'frame'"
                :item="one"
                :slide="slide"
                @build="emit('animate', slide.id, $event)"
                @preview="emit('preview', slide)"
            />

            <!-- Where it is and how big, to the pixel: there when wanted -->
            <GeometryFold
                v-if="one"
                :item="one"
                @resize="emit('resize', $event)"
            />
        </div>

        <BoardSettings
            v-else
            :item-count="itemCount"
            :frame-count="frameCount"
            :deck="deck"
            :deck-ready="deckReady"
            :beside="beside"
            @restyle-board="emit('restyle-board', $event)"
            @deck="emit('deck', $event)"
            @restyle-deck="emit('restyle-deck')"
        />
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

.inspector-sub {
    font-size: 0.75rem;
    color: var(--muted-foreground);
}

.inspector-body {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.inspector-section {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}
</style>

<!-- The panel's controls, shared with the settings drawn inside it
     (LookSettings, LabelSettings, SlideSettings and the rest), which a scoped
     style would not reach -->
<style>
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

/* A word on how to use what is above it */
.inspector-hint {
    font-size: 0.75rem;
    color: var(--muted-foreground);
}

/* A button across the panel: "Restyle the board", the deck */
.inspector-wide {
    display: inline-flex;
    width: 100%;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    height: 2rem;
    font-size: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.inspector-wide:hover,
.inspector-wide[data-state='open'] {
    background-color: var(--muted);
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

.inspector-align {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}
.inspector-chip {
    width: 1.125rem;
    height: 1.125rem;
    flex-shrink: 0;
    border: 1px solid rgb(0 0 0 / 0.2);
    border-radius: 999px;
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
