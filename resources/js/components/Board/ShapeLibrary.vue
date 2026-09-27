<script setup lang="ts">
import {
    ArrowUpRight,
    Circle,
    Cloud,
    Columns3,
    Database,
    Diamond,
    FileText,
    Frame,
    Hexagon,
    MousePointer2,
    Pencil,
    RectangleHorizontal,
    ImagePlus,
    Search,
    Shapes,
    Sigma,
    Square,
    Star,
    StickyNote,
    Triangle,
    Type,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Tool } from './items';

// The shape library Lucidchart puts down the left: grouped, searchable, and
// the thing you reach for before every other control.
defineProps<{ tool: Tool }>();
const emit = defineEmits<{ 'update:tool': [Tool]; 'add-picture': [] }>();

type Entry = { tool: Tool; icon: unknown; label: string; key: string };

const groups: { name: string; entries: Entry[] }[] = [
    {
        name: 'Tools',
        entries: [
            { tool: 'select', icon: MousePointer2, label: 'Select', key: 'V' },
            { tool: 'draw', icon: Pencil, label: 'Pen', key: 'D' },
            { tool: 'arrow', icon: ArrowUpRight, label: 'Arrow', key: 'A' },
            { tool: 'frame', icon: Frame, label: 'Frame', key: 'F' },
        ],
    },
    {
        name: 'Notes',
        entries: [
            { tool: 'sticky', icon: StickyNote, label: 'Sticky', key: 'S' },
            { tool: 'text', icon: Type, label: 'Text', key: 'T' },
            { tool: 'math', icon: Sigma, label: 'Formula', key: 'E' },
        ],
    },
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
];

const search = ref('');

const shown = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return groups;
    }

    return groups
        .map((group) => ({
            ...group,
            entries: group.entries.filter((entry) =>
                entry.label.toLowerCase().includes(term),
            ),
        }))
        .filter((group) => group.entries.length);
});
</script>

<template>
    <aside class="library" data-test="shape-library">
        <label class="library-search">
            <Search class="text-muted-foreground size-3.5 shrink-0" />
            <input
                v-model="search"
                type="search"
                placeholder="Search shapes"
                data-test="shape-search"
            />
        </label>

        <div class="library-scroll">
            <section v-for="group in shown" :key="group.name">
                <p class="library-heading">{{ group.name }}</p>
                <div class="library-grid">
                    <button
                        v-for="entry in group.entries"
                        :key="entry.tool"
                        type="button"
                        class="library-item"
                        :class="{ 'is-active': tool === entry.tool }"
                        :title="`${entry.label} (${entry.key})`"
                        :data-test="`tool-${entry.tool}`"
                        @click="emit('update:tool', entry.tool)"
                    >
                        <component :is="entry.icon" class="size-5" />
                        <span>{{ entry.label }}</span>
                    </button>
                </div>
            </section>

            <section>
                <p class="library-heading">Your own</p>
                <button
                    type="button"
                    class="library-item is-wide"
                    title="From this computer, your Drive, a link, or SVG markup"
                    data-test="open-image-picker"
                    @click="emit('add-picture')"
                >
                    <ImagePlus class="size-5" />
                    <span>Add picture</span>
                </button>
            </section>

            <p v-if="!shown.length" class="library-empty">
                Nothing matches “{{ search }}”.
            </p>
        </div>
    </aside>
</template>

<style scoped>
.library {
    display: flex;
    flex-direction: column;
    width: 13rem;
    flex-shrink: 0;
    /* Its own scroll, so a long list cannot stretch the row and push the
       canvas off the bottom of the window */
    min-height: 0;
    gap: 0.5rem;
    padding: 0.625rem;
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
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

.library-scroll {
    display: flex;
    flex: 1;
    min-height: 0;
    flex-direction: column;
    gap: 0.75rem;
    overflow-y: auto;
}

.library-heading {
    margin-bottom: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.library-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 4px;
}

.library-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 0.25rem;
    font-size: 0.6875rem;
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

.library-item.is-wide {
    width: 100%;
    flex-direction: row;
    justify-content: flex-start;
    gap: 0.5rem;
    padding: 0.5rem 0.625rem;
    border-color: var(--border);
}

.library-empty {
    font-size: 0.75rem;
    color: var(--muted-foreground);
}
</style>
