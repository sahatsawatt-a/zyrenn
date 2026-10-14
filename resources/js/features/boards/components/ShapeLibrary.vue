<script setup lang="ts">
import {
    ArrowUpRight,
    ChevronRight,
    Circle,
    Clapperboard,
    Cloud,
    Columns3,
    Database,
    Ellipsis,
    LayoutTemplate,
    Diamond,
    FileText,
    Frame,
    Hexagon,
    ImagePlus,
    MousePointer2,
    Pencil,
    RectangleHorizontal,
    Redo2,
    Search,
    Shapes,
    Sigma,
    Square,
    Star,
    StickyNote,
    Triangle,
    Type,
    Undo2,
} from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import type { Component, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { SYMBOL_GROUPS } from '@/features/boards/circuit/symbols';
import type { SymbolDef } from '@/features/boards/circuit/symbols';
import type { Tool } from '@/features/boards/composables/items';
import { SHAPE_GROUPS } from '@/features/boards/composables/shapes';
import type { ShapeDef } from '@/features/boards/composables/shapes';
import ShapeIcon from './ShapeIcon.vue';
import SymbolIcon from './SymbolIcon.vue';

// The tools along the bottom middle of the canvas: the everyday ones a click
// away, and the many shapes in a flyout of their own, so the bar stays one
// icon high and the canvas keeps the room.
const props = defineProps<{
    tool: Tool;
    canUndo: boolean;
    canRedo: boolean;
    // On a phone the bar holds only what is drawn with most; the rest, and
    // what the bar above has no room for, are under "More"
    compact?: boolean;
    // Whether there is a table on the board to write out as SQL
    hasSchema?: boolean;
}>();

const emit = defineEmits<{
    'update:tool': [Tool];
    'add-picture': [];
    'add-video': [];
    'circuit-text': [];
    templates: [];
    schema: [];
    undo: [];
    redo: [];
}>();

// A shape has an icon and a key; one from the shape library, or an
// electrical symbol, is drawn as itself
type Entry = {
    tool: Tool;
    icon?: Component;
    symbol?: SymbolDef;
    shape?: ShapeDef;
    label: string;
    key?: string;
};

// A set of shapes for one kind of diagram, kept to a single row until wanted
type Fold = 'Software' | 'Architecture' | 'Electrical';

type Group = { name: string; fold?: Fold; entries: Entry[] };

/** The library's shapes filed under a heading, as entries of the flyout. */
const fromLibrary = (name: string): Entry[] =>
    (SHAPE_GROUPS.find((group) => group.name === name)?.shapes ?? []).map(
        (shape) => ({ tool: shape.id, shape, label: shape.label }),
    );

const titleOf = (entry: Entry) =>
    entry.key ? `${entry.label} (${entry.key})` : entry.label;

const before: Entry[] = [
    { tool: 'select', icon: MousePointer2, label: 'Select', key: 'V' },
    { tool: 'sticky', icon: StickyNote, label: 'Sticky note', key: 'S' },
    { tool: 'text', icon: Type, label: 'Text', key: 'T' },
];

const after: Entry[] = [
    { tool: 'arrow', icon: ArrowUpRight, label: 'Connector', key: 'A' },
    { tool: 'draw', icon: Pencil, label: 'Pen', key: 'D' },
    { tool: 'frame', icon: Frame, label: 'Frame', key: 'F' },
    { tool: 'math', icon: Sigma, label: 'Formula', key: 'E' },
];

// Of those, the ones a phone's bar still has room for
const afterShown = computed(() =>
    props.compact
        ? after.filter((entry) => ['arrow', 'draw'].includes(entry.tool))
        : after,
);
const afterFolded = computed(() =>
    after.filter((entry) => !afterShown.value.includes(entry)),
);

const more = ref(false);

/** Something chosen from under "More", which then shuts. */
const fromMore = (act: () => void) => {
    act();
    more.value = false;
};

// The electrical symbols, a group for each kind of part
const electrical = SYMBOL_GROUPS.map((group): Group => ({
    name: group.name,
    fold: 'Electrical',
    entries: group.symbols.map((symbol): Entry => ({
        tool: `symbol:${symbol.id}`,
        symbol,
        label: symbol.label,
    })),
}));

const groups: Group[] = [
    {
        name: 'Shapes',
        entries: [
            { tool: 'rect', icon: Square, label: 'Rectangle', key: 'R' },
            {
                tool: 'pill',
                icon: RectangleHorizontal,
                label: 'Pill',
                key: 'P',
            },
            { tool: 'ellipse', icon: Circle, label: 'Ellipse', key: 'O' },
            { tool: 'triangle', icon: Triangle, label: 'Triangle', key: 'G' },
            { tool: 'diamond', icon: Diamond, label: 'Decision', key: 'M' },
            { tool: 'hexagon', icon: Hexagon, label: 'Hexagon', key: 'H' },
            { tool: 'star', icon: Star, label: 'Star', key: 'K' },
            ...fromLibrary('Shapes'),
        ],
    },
    {
        name: 'Flowchart',
        entries: [
            { tool: 'cylinder', icon: Database, label: 'Database', key: 'B' },
            { tool: 'parallelogram', icon: Shapes, label: 'Data', key: 'I' },
            { tool: 'document', icon: FileText, label: 'Document', key: 'U' },
            { tool: 'process', icon: Columns3, label: 'Process', key: 'N' },
            { tool: 'cloud', icon: Cloud, label: 'Cloud', key: 'C' },
            ...fromLibrary('Flowchart'),
        ],
    },
    { name: 'Software', fold: 'Software', entries: fromLibrary('Software') },
    {
        name: 'Architecture',
        fold: 'Architecture',
        entries: fromLibrary('Architecture'),
    },
    ...electrical,
];

const shapes = groups.flatMap((group) => group.entries);

// The shapes button wears the last shape picked, by click or by key, so the
// one being drawn over and over is where the eye already is
const lastShape = ref<Entry>(shapes[0]);

watch(
    () => props.tool,
    (tool) => {
        const shape = shapes.find((entry) => entry.tool === tool);

        if (shape) {
            lastShape.value = shape;
        }
    },
);

const shapeActive = computed(() =>
    shapes.some((entry) => entry.tool === props.tool),
);

const open = ref(false);
const search = ref('');

const shown = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return groups;
    }

    return groups
        .map((group) => ({
            ...group,
            entries: group.entries.filter(
                (entry) =>
                    entry.label.toLowerCase().includes(term) ||
                    entry.symbol?.id.includes(term) ||
                    entry.symbol?.aliases.some((alias) =>
                        alias.includes(term),
                    ) ||
                    entry.shape?.id.includes(term) ||
                    entry.shape?.aliases?.some((alias) => alias.includes(term)),
            ),
        }))
        .filter((group) => group.entries.length);
});

// Where each folded set begins, to say so once above it
const firstOf = computed(() => {
    const first = new Map<string, Fold>();

    for (const group of shown.value) {
        if (group.fold && ![...first.values()].includes(group.fold)) {
            first.set(group.name, group.fold);
        }
    }

    return first;
});

// A board is for any kind of diagram: the shapes of one kind of diagram are
// a row until somebody wants them, and then stay open for whoever does. A
// search looks through them either way.
const folds: Record<Fold, { open: Ref<boolean>; title: string }> = {
    Software: {
        open: useLocalStorage('board.fold.software', false),
        title: 'Tables, classes, actors, packages, notes and lifelines',
    },
    Architecture: {
        open: useLocalStorage('board.fold.architecture', false),
        title: 'Servers, users, screens, queues and storage',
    },
    Electrical: {
        open: useLocalStorage('board.electrical', false),
        title: 'Symbols for circuits: parts with pins, joined by wires',
    },
};

const unfolded = (fold: Fold) =>
    folds[fold].open.value || search.value.trim() !== '';

const circuitFromText = () => {
    emit('circuit-text');
    open.value = false;
};

const pickShape = (entry: Entry) => {
    emit('update:tool', entry.tool);
    open.value = false;
    search.value = '';
};
</script>

<template>
    <aside class="rail" data-test="shape-library">
        <button
            v-for="entry in before"
            :key="entry.tool"
            type="button"
            class="rail-button"
            :class="{ 'is-active': tool === entry.tool }"
            :title="`${entry.label} (${entry.key})`"
            :aria-label="entry.label"
            :data-test="`tool-${entry.tool}`"
            @click="emit('update:tool', entry.tool)"
        >
            <component :is="entry.icon" class="size-[18px]" />
        </button>

        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="rail-button has-more"
                    :class="{ 'is-active': shapeActive || open }"
                    title="Shapes and flowchart"
                    aria-label="Shapes"
                    data-test="tool-shapes"
                >
                    <SymbolIcon
                        v-if="lastShape.symbol"
                        :symbol="lastShape.symbol"
                        class="size-[22px]"
                    />
                    <ShapeIcon
                        v-else-if="lastShape.shape"
                        :shape="lastShape.shape"
                        class="size-[20px]"
                    />
                    <component
                        :is="lastShape.icon"
                        v-else
                        class="size-[18px]"
                    />
                </button>
            </PopoverTrigger>
            <PopoverContent
                side="top"
                align="center"
                :side-offset="12"
                class="max-h-[min(30rem,70svh)] w-64 overflow-y-auto p-2.5 data-[state=closed]:animate-none"
                data-test="shape-flyout"
            >
                <label class="library-search">
                    <Search class="text-muted-foreground size-3.5 shrink-0" />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search shapes"
                        data-test="shape-search"
                    />
                </label>

                <template v-for="group in shown" :key="group.name">
                    <!-- Above the first of a folded set: the row that unfolds
                         it -- and, for circuits, the other way to put one on -->
                    <div
                        v-if="firstOf.get(group.name)"
                        class="library-electrical"
                    >
                        <button
                            type="button"
                            class="library-fold"
                            :aria-expanded="unfolded(group.fold!)"
                            :title="folds[group.fold!].title"
                            :data-test="`${group.fold!.toLowerCase()}-toggle`"
                            @click="
                                folds[group.fold!].open.value =
                                    !folds[group.fold!].open.value
                            "
                        >
                            <ChevronRight
                                class="size-3.5 transition-transform"
                                :class="{
                                    'rotate-90': unfolded(group.fold!),
                                }"
                            />
                            {{ group.fold }}
                        </button>
                        <button
                            v-if="
                                group.fold === 'Electrical' &&
                                unfolded(group.fold)
                            "
                            type="button"
                            class="library-link"
                            title="Write a circuit as text and have it laid out"
                            data-test="circuit-from-text"
                            @click="circuitFromText"
                        >
                            From text…
                        </button>
                    </div>
                    <section
                        v-if="!group.fold || unfolded(group.fold)"
                        :class="group.fold === group.name ? 'mt-1.5' : 'mt-2.5'"
                    >
                        <!-- A set of one group is named by its row already -->
                        <p
                            v-if="group.fold !== group.name"
                            class="library-heading"
                        >
                            {{ group.name }}
                        </p>
                        <div class="library-grid">
                            <button
                                v-for="entry in group.entries"
                                :key="entry.tool"
                                type="button"
                                class="library-item"
                                :class="{ 'is-active': tool === entry.tool }"
                                :title="titleOf(entry)"
                                :data-test="`tool-${entry.tool}`"
                                @click="pickShape(entry)"
                            >
                                <SymbolIcon
                                    v-if="entry.symbol"
                                    :symbol="entry.symbol"
                                    class="size-7"
                                />
                                <ShapeIcon
                                    v-else-if="entry.shape"
                                    :shape="entry.shape"
                                    class="size-6"
                                />
                                <component
                                    :is="entry.icon"
                                    v-else
                                    class="size-5"
                                />
                                <span>{{ entry.label }}</span>
                            </button>
                        </div>
                    </section>
                </template>

                <p v-if="!shown.length" class="library-empty">
                    Nothing matches “{{ search }}”.
                </p>
            </PopoverContent>
        </Popover>

        <button
            v-for="entry in afterShown"
            :key="entry.tool"
            type="button"
            class="rail-button"
            :class="{ 'is-active': tool === entry.tool }"
            :title="`${entry.label} (${entry.key})`"
            :aria-label="entry.label"
            :data-test="`tool-${entry.tool}`"
            @click="emit('update:tool', entry.tool)"
        >
            <component :is="entry.icon" class="size-[18px]" />
        </button>

        <!-- On a phone: the tools drawn with less often, pictures and
             videos, and what the bar above would have no room for -->
        <Popover v-if="compact" v-model:open="more">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="rail-button"
                    :class="{
                        'is-active':
                            more ||
                            afterFolded.some((entry) => entry.tool === tool),
                    }"
                    title="More to add"
                    aria-label="More"
                    data-test="tool-more"
                >
                    <Ellipsis class="size-[18px]" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                side="top"
                align="center"
                :side-offset="12"
                class="w-56 p-1.5"
                data-test="more-flyout"
            >
                <button
                    v-for="entry in afterFolded"
                    :key="entry.tool"
                    type="button"
                    class="more-item"
                    :class="{ 'is-active': tool === entry.tool }"
                    :data-test="`tool-${entry.tool}`"
                    @click="fromMore(() => emit('update:tool', entry.tool))"
                >
                    <component :is="entry.icon" class="size-[18px]" />
                    {{ entry.label }}
                </button>
                <button
                    type="button"
                    class="more-item"
                    data-test="open-image-picker"
                    @click="fromMore(() => emit('add-picture'))"
                >
                    <ImagePlus class="size-[18px]" />
                    Picture
                </button>
                <button
                    type="button"
                    class="more-item"
                    data-test="open-video-picker"
                    @click="fromMore(() => emit('add-video'))"
                >
                    <Clapperboard class="size-[18px]" />
                    Video
                </button>
                <span class="more-rule" />
                <button
                    type="button"
                    class="more-item"
                    data-test="board-templates"
                    @click="fromMore(() => emit('templates'))"
                >
                    <LayoutTemplate class="size-[18px]" />
                    Start from a template
                </button>
                <button
                    v-if="hasSchema"
                    type="button"
                    class="more-item"
                    data-test="board-schema"
                    @click="fromMore(() => emit('schema'))"
                >
                    <Database class="size-[18px]" />
                    Tables as SQL
                </button>
            </PopoverContent>
        </Popover>

        <span class="rail-divider" />

        <button
            v-if="!compact"
            type="button"
            class="rail-button"
            title="Add a picture: from this computer, your Drive, a link, or SVG markup"
            aria-label="Add picture"
            data-test="open-image-picker"
            @click="emit('add-picture')"
        >
            <ImagePlus class="size-[18px]" />
        </button>
        <button
            v-if="!compact"
            type="button"
            class="rail-button"
            title="Add a video: from this computer, your Drive, or a link. It plays on the board."
            aria-label="Add video"
            data-test="open-video-picker"
            @click="emit('add-video')"
        >
            <Clapperboard class="size-[18px]" />
        </button>

        <span v-if="!compact" class="rail-divider" />

        <button
            type="button"
            class="rail-button"
            title="Undo (Ctrl+Z)"
            aria-label="Undo"
            data-test="undo"
            :disabled="!canUndo"
            @click="emit('undo')"
        >
            <Undo2 class="size-[18px]" />
        </button>
        <button
            type="button"
            class="rail-button"
            title="Redo (Ctrl+Shift+Z)"
            aria-label="Redo"
            data-test="redo"
            :disabled="!canRedo"
            @click="emit('redo')"
        >
            <Redo2 class="size-[18px]" />
        </button>
    </aside>
</template>

<style scoped>
.rail {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 2px;
    padding: 0.3125rem;
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    box-shadow: 0 4px 16px -4px rgb(15 23 42 / 0.14);
}

.rail-button {
    position: relative;
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    color: var(--foreground);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.rail-button:hover:not(:disabled) {
    background-color: var(--muted);
}
.rail-button.is-active {
    color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 14%, transparent);
}
.rail-button:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

/* A little corner mark: this one opens more */
.rail-button.has-more::after {
    content: '';
    position: absolute;
    right: 3px;
    bottom: 3px;
    border: 3px solid transparent;
    border-right-color: currentColor;
    border-bottom-color: currentColor;
    opacity: 0.55;
}

/* What is under "More" on a phone: a row to each, big enough for a thumb */
.more-item {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    width: 100%;
    height: 2.5rem;
    padding: 0 0.625rem;
    font-size: 0.875rem;
    color: var(--foreground);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.more-item:hover {
    background-color: var(--muted);
}
.more-item.is-active {
    color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 12%, transparent);
}
.more-rule {
    display: block;
    height: 1px;
    margin: 0.25rem 0.375rem;
    background-color: var(--border);
}

.rail-divider {
    flex: none;
    width: 1px;
    height: 1.25rem;
    margin: 0 0.25rem;
    background-color: var(--border);
}

.library-search {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    height: 2rem;
    padding: 0 0.5rem;
    background-color: var(--muted);
    border-radius: var(--radius-md);
}
.library-search input {
    width: 100%;
    min-width: 0;
    font-size: 0.8125rem;
    background: transparent;
    border: none;
    outline: none;
}

.library-electrical {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.625rem;
    padding-top: 0.5rem;
    border-top: 1px solid var(--border);
}
.library-fold {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.125rem 0.375rem 0.125rem 0.125rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.library-fold:hover {
    background-color: var(--muted);
}
.library-link {
    padding: 0.125rem 0.375rem;
    font-size: 0.6875rem;
    font-weight: 500;
    color: var(--primary);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.library-link:hover {
    background-color: color-mix(in oklab, var(--primary) 12%, transparent);
}

.library-heading {
    margin-bottom: 0.25rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.library-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 2px;
}

.library-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 0.125rem 0.375rem;
    font-size: 0.625rem;
    color: var(--foreground);
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    cursor: pointer;
}
.library-item span {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.library-item:hover {
    background-color: var(--muted);
}
.library-item.is-active {
    color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 12%, transparent);
    border-color: var(--primary);
}

.library-empty {
    margin-top: 0.5rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
}
</style>
