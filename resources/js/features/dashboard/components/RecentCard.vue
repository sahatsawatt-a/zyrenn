<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatRelativeTime } from '@/lib/utils';
import { fitBoard, kindLabel, rowsLabel } from '../preview';
import type { Preview, RecentItem } from '../preview';

// A thing changed lately, as a card to pick it up again by: a glimpse of it
// on top -- a note's first lines, a board's first frame in small, a table's
// columns -- then its title, and what it is, where, and when. All of it one
// link.
const props = defineProps<{
    item: RecentItem;
    // What the server could show of it (Previews); without one, its kind
    preview?: Preview | null;
    href: InertiaLinkProps['href'];
}>();

// The card's drawing area, in the pixels the board is drawn to; the picture
// scales with the card from there
const WIDTH = 280;
const HEIGHT = 84;

const board = computed(() =>
    props.preview?.kind === 'board'
        ? fitBoard(props.preview, WIDTH, HEIGHT)
        : null,
);

const note = computed(() =>
    props.preview?.kind === 'note' ? props.preview : null,
);

const table = computed(() =>
    props.preview?.kind === 'table' ? props.preview : null,
);

// Empty rows under a table's columns: as many as it has, up to three
const ghostRows = computed(() => Math.min(3, table.value?.rows ?? 0));

const said = computed(() =>
    [
        kindLabel(props.item.kind),
        props.item.project?.name,
        table.value ? rowsLabel(table.value.rows) : null,
        props.item.edited_by,
        formatRelativeTime(props.item.updated_at),
    ]
        .filter(Boolean)
        .join(' · '),
);
</script>

<template>
    <Link
        :href="href"
        class="bg-card hover:border-primary/40 focus-visible:ring-ring/50 group flex min-w-0 snap-start flex-col overflow-hidden rounded-xl border transition-colors outline-none focus-visible:ring-[3px]"
        data-test="recent-card"
        :data-kind="item.kind"
    >
        <!-- A glimpse of it -->
        <div
            class="bg-muted text-muted-foreground relative h-[84px] shrink-0 overflow-hidden"
            aria-hidden="true"
            data-test="recent-preview"
        >
            <div v-if="note" class="px-3 py-2.5 text-[11px] leading-normal">
                <b
                    v-if="note.heading"
                    class="text-foreground block truncate text-xs font-semibold"
                    >{{ note.heading }}</b
                >
                <p
                    class="overflow-hidden"
                    :class="note.heading ? 'line-clamp-3' : 'line-clamp-4'"
                >
                    <template v-if="note.text">{{ note.text }}</template>
                    <span v-else-if="!note.heading" class="italic"
                        >Nothing written yet</span
                    >
                </p>
            </div>

            <svg
                v-else-if="board"
                class="size-full"
                :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
                preserveAspectRatio="xMidYMid meet"
            >
                <line
                    v-for="(line, index) in board.lines"
                    :key="`line-${index}`"
                    :x1="line[0]"
                    :y1="line[1]"
                    :x2="line[2]"
                    :y2="line[3]"
                    stroke="currentColor"
                    stroke-opacity="0.45"
                    stroke-width="1"
                />
                <rect
                    v-for="(box, index) in board.boxes"
                    :key="`box-${index}`"
                    :x="box.x"
                    :y="box.y"
                    :width="box.width"
                    :height="box.height"
                    :rx="box.radius"
                    :fill="
                        box.look === 'shape'
                            ? box.fill || 'none'
                            : 'currentColor'
                    "
                    :fill-opacity="box.look === 'shape' ? 1 : 0.3"
                    :stroke="
                        box.look === 'shape'
                            ? box.stroke || (box.fill ? 'none' : 'currentColor')
                            : 'none'
                    "
                    :stroke-opacity="box.stroke ? 1 : 0.5"
                    stroke-width="1"
                />
                <text
                    v-if="!board.boxes.length"
                    :x="WIDTH / 2"
                    :y="HEIGHT / 2"
                    text-anchor="middle"
                    dominant-baseline="middle"
                    font-size="11"
                    fill="currentColor"
                    font-style="italic"
                >
                    Nothing on it yet
                </text>
            </svg>

            <div v-else-if="table" class="p-2">
                <div
                    class="grid gap-px"
                    :style="{
                        gridTemplateColumns: `repeat(${Math.max(1, table.columns.length)}, minmax(0, 1fr))`,
                    }"
                >
                    <span
                        v-for="(column, index) in table.columns"
                        :key="`head-${index}`"
                        class="bg-accent text-accent-foreground truncate rounded-[2px] px-1 text-[10px] leading-4 font-medium"
                        >{{ column }}</span
                    >
                    <template v-for="row in ghostRows" :key="`row-${row}`">
                        <span
                            v-for="(_, index) in table.columns"
                            :key="`cell-${row}-${index}`"
                            class="bg-card h-3 rounded-[2px]"
                        />
                    </template>
                </div>
                <p v-if="!table.rows" class="mt-2 text-[11px] italic">
                    No rows yet
                </p>
            </div>
        </div>

        <!-- What it is, where, and when -->
        <div class="flex min-w-0 flex-col gap-px px-3 py-2">
            <b
                class="group-hover:text-primary truncate text-sm font-semibold"
                >{{ item.title || 'Untitled' }}</b
            >
            <span class="text-muted-foreground truncate text-xs">{{
                said
            }}</span>
        </div>
    </Link>
</template>
