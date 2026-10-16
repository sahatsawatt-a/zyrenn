<script setup lang="ts">
import {
    ArrowLeft,
    ChevronRight,
    PanelLeftClose,
    Search,
    X,
    Zap,
} from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { iconOf, toneOf, TRIGGER_STRIP } from '@/features/workflow/steps';
import type { NodeType } from '@/features/workflow/types';
import PaletteStep from './PaletteStep.vue';

// The kinds of step, found three ways: by searching -- always on top, by
// name, what it does, its group or its kind -- by group, a short list of
// rows under three headings (general steps, those on the app's own things,
// then the services it reaches, as the server sorts them), each
// opened to its steps set out in parts; or among the ones used lately. A
// step is dragged onto the canvas, or clicked to add it beside the last --
// or after the step whose "+" was pressed. With nothing yet to start a run,
// what starts one is offered first.

const props = defineProps<{
    types: NodeType[];
    groups: Record<string, string>;
    // A group in parts: each part's name and its kinds, in the order shown
    sections?: Record<string, Record<string, string[]>>;
    // The step the next one is joined to, and by which way out
    after?: { label: string; way: string } | null;
    // Over the canvas rather than beside it, on a narrow screen
    compact?: boolean;
    // The workflow has something that starts a run
    started?: boolean;
    // The heading each group is listed under, in order; any other is a service
    headings?: Record<string, 'general' | 'zyrenn'>;
}>();

const emit = defineEmits<{
    add: [type: NodeType];
    close: [];
    forget: [];
}>();

// The headings groups are listed under, as the server sorts them
const HEADINGS = { general: 'General', zyrenn: 'ZyrenN', services: 'Services' };
// Every trigger, gathered: what is offered first with nothing to start a run
const STARTS = 'starts';
// The most of those used lately that are shown
const RECENT = 5;

const query = ref('');
const asked = computed(() => query.value.trim().toLowerCase());
// The group opened, or none: the list of groups
const opened = ref<string | null>(null);

// Parts folded away, as they were left: "line:Rich menus"
const folded = useLocalStorage<string[]>('workflow.palette.folded', []);
// The kinds this viewer added lately, newest first
const recent = useLocalStorage<string[]>('workflow.palette.recent', []);

const fold = (part: string) =>
    (folded.value = folded.value.includes(part)
        ? folded.value.filter((one) => one !== part)
        : [...folded.value, part]);

// Nothing leads into what starts a run
const usable = computed(() =>
    props.types.filter((type) => !(props.after && type.trigger)),
);

const titleOf = (group: string) =>
    group === STARTS ? 'Start a run' : (props.groups[group] ?? group);

// Each group's kinds: what starts a run first, then what it can do
const kindsOf = (group: string) =>
    usable.value
        .filter((type) =>
            group === STARTS ? type.trigger : type.group === group,
        )
        .sort((a, b) => Number(b.trigger) - Number(a.trigger));

type Row = { key: string; title: string; count: number; looks: NodeType };

const row = (key: string): Row | null => {
    const kinds = kindsOf(key);
    // Drawn as one of its own: the first that does something, not one that starts
    const looks = kinds.find((type) => !type.trigger) ?? kinds[0];

    return looks
        ? { key, title: titleOf(key), count: kinds.length, looks }
        : null;
};

const rows = computed(() => {
    const keys = Object.keys(props.groups);
    const placed = props.headings ?? {};
    const under = (heading: 'general' | 'zyrenn') =>
        Object.keys(placed).filter(
            (key) => placed[key] === heading && keys.includes(key),
        );

    return {
        starts: !props.after && !props.started ? row(STARTS) : null,
        lists: [
            { heading: 'general' as const, keys: under('general') },
            { heading: 'zyrenn' as const, keys: under('zyrenn') },
            {
                heading: 'services' as const,
                keys: keys.filter((key) => !placed[key]),
            },
        ].map((list) => ({
            ...list,
            title: HEADINGS[list.heading],
            rows: list.keys.map(row).filter((one) => one !== null),
        })),
    };
});

const lately = computed(() =>
    recent.value
        .map((kind) => usable.value.find((type) => type.type === kind))
        .filter((type) => type !== undefined)
        .slice(0, RECENT),
);

// The open group, in its parts; those in no part before them all
const parts = computed(() => {
    const group = opened.value;

    if (!group) {
        return [];
    }

    const kinds = kindsOf(group);
    const named = Object.entries(
        group === STARTS ? {} : (props.sections?.[group] ?? {}),
    )
        .map(([name, listed]) => ({
            name,
            id: `${group}:${name}`,
            types: listed
                .map((kind) => kinds.find((type) => type.type === kind))
                .filter((type) => type !== undefined),
        }))
        .filter((part) => part.types.length)
        .map((part) => ({ ...part, open: !folded.value.includes(part.id) }));
    const placed = new Set(
        named.flatMap((part) => part.types.map((type) => type.type)),
    );

    // What starts a run, gathered: under the group each is of
    if (group === STARTS) {
        return Object.keys(props.groups)
            .map((key) => ({
                name: props.groups[key],
                id: `${STARTS}:${key}`,
                open: !folded.value.includes(`${STARTS}:${key}`),
                types: kinds.filter((type) => type.group === key),
            }))
            .filter((part) => part.types.length);
    }

    return [
        {
            name: '',
            id: group,
            open: true,
            types: kinds.filter((type) => !placed.has(type.type)),
        },
        ...named,
    ].filter((part) => part.types.length);
});

// Searched for: what matches every word, the best first -- its name
// starting so, then its name, then its group or kind, then what it does --
// each group's together, the group with the best one first
const found = computed(() => {
    if (!asked.value) {
        return [];
    }

    const words = asked.value.split(/\s+/);
    const order = Object.keys(props.groups);
    const scored = usable.value
        .map((type) => {
            const label = type.label.toLowerCase();
            const group = titleOf(type.group).toLowerCase();
            const all = `${label} ${group} ${type.type} ${type.description.toLowerCase()}`;

            if (!words.every((word) => all.includes(word))) {
                return null;
            }

            const score = label.startsWith(asked.value)
                ? 0
                : label.includes(asked.value)
                  ? 1
                  : `${group} ${type.type}`.includes(asked.value)
                    ? 2
                    : words.every((word) => label.includes(word))
                      ? 3
                      : 4;

            return { type, score };
        })
        .filter((one) => one !== null);
    const groups = new Map<string, { score: number; types: typeof scored }>();

    for (const one of scored) {
        const group = groups.get(one.type.group) ?? {
            score: one.score,
            types: [],
        };
        group.score = Math.min(group.score, one.score);
        group.types.push(one);
        groups.set(one.type.group, group);
    }

    return [...groups.entries()]
        .sort(
            ([a, one], [b, other]) =>
                one.score - other.score || order.indexOf(a) - order.indexOf(b),
        )
        .map(([key, group]) => ({
            key,
            title: titleOf(key),
            types: group.types
                .sort((a, b) => a.score - b.score)
                .map((one) => one.type),
        }));
});

// A search started from inside a group looks everywhere
watch(asked, (now) => {
    if (now) {
        opened.value = null;
    }
});

function remember(type: NodeType) {
    recent.value = [
        type.type,
        ...recent.value.filter((kind) => kind !== type.type),
    ].slice(0, 12);
}

function add(type: NodeType) {
    remember(type);
    emit('add', type);
}

// Enter adds the best match; Escape clears the search, then goes back
function pickFirst() {
    const first = found.value[0]?.types[0];

    if (first) {
        add(first);
    }
}

function back() {
    if (query.value) {
        query.value = '';
    } else {
        opened.value = null;
    }
}
</script>

<template>
    <aside
        class="bg-background flex flex-col"
        :class="compact ? 'absolute inset-0 z-20' : 'w-56 shrink-0 border-r'"
        data-step-palette
    >
        <div class="flex items-center gap-1.5 border-b p-2">
            <div class="relative min-w-0 flex-1">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
                />
                <input
                    v-model="query"
                    type="search"
                    placeholder="Find a step"
                    aria-label="Find a step"
                    class="bg-muted/40 focus-visible:ring-ring/50 h-8 w-full rounded-md border pr-2 pl-8 text-sm outline-none focus-visible:ring-[3px]"
                    data-palette-search
                    @keydown.enter.prevent="pickFirst"
                    @keydown.esc="back"
                />
            </div>
            <Button
                size="icon-sm"
                variant="ghost"
                aria-label="Hide the steps"
                data-palette-close
                @click="emit('close')"
            >
                <X v-if="compact" />
                <PanelLeftClose v-else />
            </Button>
        </div>

        <p
            v-if="after"
            class="bg-primary/5 flex items-center gap-1.5 border-b py-1.5 pr-1.5 pl-3 text-xs"
            data-palette-after
        >
            <span class="min-w-0 flex-1 truncate">
                Next after <b class="font-medium">{{ after.label }}</b>
                <template v-if="after.way"> · {{ after.way }}</template>
            </span>
            <Button
                size="icon-sm"
                variant="ghost"
                class="size-6"
                aria-label="Add it on its own instead"
                @click="emit('forget')"
            >
                <X />
            </Button>
        </p>

        <div class="flex-1 overflow-y-auto p-2">
            <!-- Searched for: every match, by group -->
            <template v-if="asked">
                <section
                    v-for="group in found"
                    :key="group.key"
                    class="mb-2"
                    :data-palette-found="group.key"
                >
                    <h3
                        class="text-muted-foreground mb-0.5 px-1.5 text-[11px] font-medium tracking-wide uppercase"
                    >
                        {{ group.title }}
                    </h3>
                    <PaletteStep
                        v-for="type in group.types"
                        :key="type.type"
                        :type="type"
                        :compact="compact"
                        @add="add"
                        @drag="remember"
                    />
                </section>
                <p
                    v-if="!found.length"
                    class="text-muted-foreground px-1 text-sm"
                >
                    No step matches.
                </p>
            </template>

            <!-- One group opened: its steps, in its parts -->
            <section v-else-if="opened" :data-palette-open="opened">
                <div class="mb-1 flex items-center gap-1">
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        class="size-7"
                        aria-label="All the groups"
                        data-palette-back
                        @click="opened = null"
                    >
                        <ArrowLeft />
                    </Button>
                    <h3 class="min-w-0 flex-1 truncate text-sm font-medium">
                        {{ titleOf(opened) }}
                    </h3>
                </div>
                <template v-for="part in parts" :key="part.id">
                    <button
                        v-if="part.name"
                        type="button"
                        class="text-muted-foreground hover:text-foreground mt-1 flex w-full items-center gap-1 rounded-md px-1 py-1 text-left text-xs font-medium"
                        :aria-expanded="part.open"
                        :data-palette-part="part.id"
                        @click="fold(part.id)"
                    >
                        <ChevronRight
                            class="size-3 shrink-0 transition-transform"
                            :class="{ 'rotate-90': part.open }"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            part.name
                        }}</span>
                        <span class="tabular-nums opacity-60">{{
                            part.types.length
                        }}</span>
                    </button>
                    <template v-if="part.open">
                        <PaletteStep
                            v-for="type in part.types"
                            :key="type.type"
                            :type="type"
                            :compact="compact"
                            @add="add"
                            @drag="remember"
                        />
                    </template>
                </template>
            </section>

            <!-- The groups, as rows; what was used lately above them -->
            <template v-else>
                <button
                    v-if="rows.starts"
                    type="button"
                    class="mb-2 flex w-full items-center gap-2 rounded-md border border-emerald-500/30 bg-emerald-500/5 px-2 text-left transition-colors hover:bg-emerald-500/10"
                    :class="compact ? 'py-2.5' : 'py-2'"
                    :data-palette-group="rows.starts.key"
                    :data-palette-title="rows.starts.title"
                    @click="opened = rows.starts.key"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-md text-white"
                        :class="TRIGGER_STRIP"
                    >
                        <Zap class="size-3.5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{
                            rows.starts.title
                        }}</span>
                        <span class="text-muted-foreground block text-xs"
                            >Every way a run can begin</span
                        >
                    </span>
                    <ChevronRight class="text-muted-foreground size-4" />
                </button>

                <div v-if="lately.length" class="mb-2" data-palette-recent>
                    <h3
                        class="text-muted-foreground mb-0.5 px-1.5 text-[11px] font-medium tracking-wide uppercase"
                    >
                        Used lately
                    </h3>
                    <PaletteStep
                        v-for="type in lately"
                        :key="type.type"
                        :type="type"
                        :compact="compact"
                        @add="add"
                        @drag="remember"
                    />
                </div>

                <template v-for="list in rows.lists" :key="list.heading">
                    <h3
                        v-if="list.rows.length"
                        class="text-muted-foreground mt-1 mb-0.5 px-1.5 text-[11px] font-medium tracking-wide uppercase"
                        :data-palette-heading="list.heading"
                    >
                        {{ list.title }}
                    </h3>
                    <button
                        v-for="one in list.rows"
                        :key="one.key"
                        type="button"
                        class="hover:bg-muted/60 flex w-full items-center gap-2 rounded-md px-1.5 text-left transition-colors"
                        :class="compact ? 'py-2' : 'py-1'"
                        :data-palette-group="one.key"
                        :data-palette-title="one.title"
                        @click="opened = one.key"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-md"
                            :class="toneOf(one.looks).tile"
                        >
                            <component
                                :is="iconOf(one.looks)"
                                class="size-3.5"
                            />
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm">{{
                            one.title
                        }}</span>
                        <span
                            class="text-muted-foreground text-xs tabular-nums"
                            >{{ one.count }}</span
                        >
                        <ChevronRight
                            class="text-muted-foreground size-3.5 shrink-0"
                        />
                    </button>
                </template>
            </template>
        </div>
    </aside>
</template>
