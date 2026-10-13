<script setup lang="ts">
import {
    ArrowUpRight,
    ChevronRight,
    Circle,
    Clapperboard,
    Cloud,
    Columns3,
    Database,
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
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { SYMBOL_GROUPS } from '@/features/boards/circuit/symbols';
import type { SymbolDef } from '@/features/boards/circuit/symbols';
import type { Tool } from '@/features/boards/composables/items';
import SymbolIcon from './SymbolIcon.vue';

// The tools along the bottom middle of the canvas: the everyday ones a click
// away, and the many shapes in a flyout of their own, so the bar stays one
// icon high and the canvas keeps the room.
const props = defineProps<{
    tool: Tool;
    canUndo: boolean;
    canRedo: boolean;
}>();

const emit = defineEmits<{
    'update:tool': [Tool];
    'add-picture': [];
    'add-video': [];
    'circuit-text': [];
    undo: [];
    redo: [];
}>();

// A shape has an icon and a key; an electrical symbol is drawn as itself
type Entry = {
    tool: Tool;
    icon?: Component;
    symbol?: SymbolDef;
    label: string;
    key?: string;
};

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

// The electrical symbols, a group for each kind of part
const electrical = SYMBOL_GROUPS.map((group) => ({
    name: group.name,
    electrical: true,
    entries: group.symbols.map((symbol): Entry => ({
        tool: `symbol:${symbol.id}`,
        symbol,
        label: symbol.label,
    })),
}));

const groups: { name: string; electrical?: boolean; entries: Entry[] }[] = [
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
        ],
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
                    entry.symbol?.aliases.some((alias) => alias.includes(term)),
            ),
        }))
        .filter((group) => group.entries.length);
});

// Where the electrical symbols begin, to say so once above them
const firstElectrical = computed(
    () => shown.value.find((group) => group.electrical)?.name,
);

// A board is for any kind of diagram: the electrical symbols are one row
// until somebody wants them, and then stay open for whoever does. A search
// looks through them either way.
const electricalOpen = useLocalStorage('board.electrical', false);
const unfolded = computed(
    () => electricalOpen.value || search.value.trim() !== '',
);

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
                    <!-- Above the first of the symbols: the row that
                         unfolds them, and the other way to put a circuit on -->
                    <div
                        v-if="group.name === firstElectrical"
                        class="library-electrical"
                    >
                        <button
                            type="button"
                            class="library-fold"
                            :aria-expanded="unfolded"
                            title="Symbols for circuits: parts with pins, joined by wires"
                            data-test="electrical-toggle"
                            @click="electricalOpen = !electricalOpen"
                        >
                            <ChevronRight
                                class="size-3.5 transition-transform"
                                :class="{ 'rotate-90': unfolded }"
                            />
                            Electrical
                        </button>
                        <button
                            v-if="unfolded"
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
                        v-if="!group.electrical || unfolded"
                        class="mt-2.5"
                    >
                        <p class="library-heading">{{ group.name }}</p>
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
            v-for="entry in after"
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

        <span class="rail-divider" />

        <button
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
            type="button"
            class="rail-button"
            title="Add a video: from this computer, your Drive, or a link. It plays on the board."
            aria-label="Add video"
            data-test="open-video-picker"
            @click="emit('add-video')"
        >
            <Clapperboard class="size-[18px]" />
        </button>

        <span class="rail-divider" />

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

.rail-divider {
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
