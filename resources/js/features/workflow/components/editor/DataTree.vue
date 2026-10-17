<script setup lang="ts">
import {
    Braces,
    ChevronRight,
    CircleDashed,
    GripVertical,
    Hash,
    List,
    ToggleLeft,
    Type,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import type { Component } from 'vue';
import Tip from '@/components/common/Tip.vue';

// What a step was given or gave, as its parts: each on a line under what
// holds it, with a mark for the kind of thing it is, folded and opened.
// Beside a step's settings each root is a card under its step's name, and a
// part can be picked -- dragged into a setting, or clicked; in a run it is
// only read, with long text in full.

export type Root = {
    // What a formula reads it by: trigger, input, node("key")
    name: string;
    value: unknown;
    // What it is called on its card, with its step's icon and colour
    title?: string;
    icon?: Component;
    tile?: string;
    // In place of its parts, when there are none to show: why, or '' when
    // that goes without saying
    note?: string;
};

const props = defineProps<{
    roots: Root[];
    // Parts can be dragged or clicked into a setting
    pick?: boolean;
    // The one root's parts with no card for the root itself
    bare?: boolean;
    // Text in full over as many lines as it takes, not cut to one
    full?: boolean;
    // Larger, for a finger
    roomy?: boolean;
    // Which parts come folded; without it only what is deep or long does
    foldedAtFirst?: (id: string, depth: number, value: unknown) => boolean;
}>();

const emit = defineEmits<{
    dragging: [on: boolean];
    // A part clicked: into the setting last written in
    pick: [path: string];
}>();

// How a formula names a part: trigger.chat.id, trigger.[Cost (THB)],
// item(trigger.photos, 1).file_id
const NAME = /^[\p{L}_][\p{L}\p{N}_]*$/u;
const WORDS = ['true', 'false', 'and', 'or', 'not'];

const member = (base: string, key: string) =>
    NAME.test(key) && !WORDS.includes(key.toLowerCase())
        ? `${base}.${key}`
        : key.includes(']')
          ? null
          : `${base}.[${key}]`;

// Long lists show their first few; the rest are reached the same way
const SHOWN_ITEMS = 20;
// On one line, text is cut here
const LINE = 80;

type Kind = 'record' | 'list' | 'text' | 'number' | 'switch' | 'empty';

type Row = {
    id: string;
    label: string;
    // What a formula reads it as; none for a name it can't be written with
    path: string | null;
    depth: number;
    kind: Kind;
    // Holds parts of its own, which fold away
    holds: boolean;
    folded: boolean;
    shown: string;
};

// The mark each kind of thing goes by, and how its value is set
const LOOKS: Record<Kind, { icon: Component; value: string }> = {
    record: { icon: Braces, value: 'text-muted-foreground' },
    list: { icon: List, value: 'text-muted-foreground' },
    text: { icon: Type, value: 'text-foreground/90' },
    number: { icon: Hash, value: 'font-mono text-sky-700 dark:text-sky-400' },
    switch: {
        icon: ToggleLeft,
        value: 'font-mono text-violet-700 dark:text-violet-400',
    },
    empty: { icon: CircleDashed, value: 'text-muted-foreground/70 italic' },
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

function kindOf(value: unknown): Kind {
    if (value === null || value === undefined || value === '') {
        return 'empty';
    }

    if (Array.isArray(value)) {
        return 'list';
    }

    if (isRecord(value)) {
        return 'record';
    }

    return typeof value === 'number'
        ? 'number'
        : typeof value === 'boolean'
          ? 'switch'
          : 'text';
}

function shown(value: unknown, kind: Kind): string {
    switch (kind) {
        case 'empty':
            return 'empty';
        case 'list': {
            const n = (value as unknown[]).length;

            return `${n} ${n === 1 ? 'item' : 'items'}`;
        }
        case 'record': {
            const n = Object.keys(value as object).length;

            return `${n} ${n === 1 ? 'part' : 'parts'}`;
        }
        case 'text': {
            const text = String(value);

            return !props.full && text.length > LINE
                ? `${text.slice(0, LINE)}…`
                : text;
        }
        default:
            return String(value);
    }
}

// Left to itself: what is three deep, and a long list, wait to be opened
const usual = (_id: string, depth: number, value: unknown) =>
    depth >= 3 || (Array.isArray(value) && value.length > 10);

// The parts opened or folded by hand
const turned = ref(new Map<string, boolean>());

const isFolded = (id: string, depth: number, value: unknown) =>
    turned.value.get(id) ?? (props.foldedAtFirst ?? usual)(id, depth, value);

function toggle(row: Row) {
    turned.value = new Map(turned.value).set(row.id, !row.folded);
}

// Each root with its lines: its own first, unless it is bare, then its parts
const groups = computed(() =>
    props.roots.map((root) => {
        const rows: Row[] = [];

        const within = (
            id: string,
            path: string | null,
            value: unknown,
            depth: number,
        ) => {
            if (Array.isArray(value)) {
                value
                    .slice(0, SHOWN_ITEMS)
                    .forEach((item, at) =>
                        walk(
                            `${id}/${at}`,
                            `${at + 1}`,
                            path === null ? null : `item(${path}, ${at + 1})`,
                            item,
                            depth,
                        ),
                    );

                return;
            }

            for (const [key, part] of Object.entries(
                value as Record<string, unknown>,
            )) {
                walk(
                    `${id}/${key}`,
                    key,
                    path === null ? null : member(path, key),
                    part,
                    depth,
                );
            }
        };

        const walk = (
            id: string,
            label: string,
            path: string | null,
            value: unknown,
            depth: number,
        ) => {
            const kind = kindOf(value);
            const holds = kind === 'list' || kind === 'record';
            const folded = holds && isFolded(id, depth, value);
            rows.push({
                id,
                label,
                path,
                depth,
                kind,
                holds,
                folded,
                shown: shown(value, kind),
            });

            if (holds && !folded) {
                within(id, path, value, depth + 1);
            }
        };

        const off = root.note !== undefined;
        const path = props.pick && !off ? root.name : null;
        const kind = kindOf(root.value);
        const parts =
            kind === 'list' || kind === 'record'
                ? Object.keys(root.value as object).length
                : 0;

        if (off) {
            // Nothing of it to show: its card says why
        } else if (props.bare && parts) {
            within(root.name, path, root.value, 0);
        } else {
            walk(root.name, props.bare ? '' : root.name, path, root.value, 0);
        }

        return {
            root,
            off,
            // On a card, the root's own line is the card's top
            head: props.bare ? undefined : rows[0],
            parts: props.bare ? rows : rows.slice(1),
        };
    }),
);

// A part's lines are set in under what holds it, one rule for each step in
const rules = (row: Row) => (props.bare ? row.depth : row.depth - 1);

// While a part is dragged, only a setting that reads data takes it -- marked
// data-takes-data -- and a formula takes it bare, without the {{ }} the
// browser put in for a template
function landing(event: DragEvent) {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement;
    const takes = target.getAttribute?.('data-takes-data');
    const path = event.dataTransfer?.getData('application/x-workflow-path');

    if (!takes || target.disabled) {
        event.preventDefault();

        return;
    }

    if (takes === 'formula' && path) {
        setTimeout(() => unwrap(target, path));
    }
}

function unwrap(field: HTMLInputElement | HTMLTextAreaElement, path: string) {
    const dropped = `{{ ${path} }}`;
    const start = field.selectionStart ?? 0;
    const at =
        field.value.slice(start, field.selectionEnd ?? start) === dropped
            ? start
            : field.value.indexOf(dropped);

    if (at < 0) {
        return;
    }

    field.value =
        field.value.slice(0, at) +
        path +
        field.value.slice(at + dropped.length);
    field.setSelectionRange(at + path.length, at + path.length);
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

function drag(event: DragEvent, path: string | null) {
    if (!path || !event.dataTransfer) {
        return;
    }

    event.dataTransfer.setData('text/plain', `{{ ${path} }}`);
    event.dataTransfer.setData('application/x-workflow-path', path);
    event.dataTransfer.effectAllowed = 'copy';
    document.addEventListener('drop', landing, true);
    emit('dragging', true);
}

function dropped() {
    document.removeEventListener('drop', landing, true);
    emit('dragging', false);
}

onBeforeUnmount(dropped);
</script>

<template>
    <div class="text-xs" :class="{ 'space-y-2': !bare }">
        <div
            v-for="group in groups"
            :key="group.root.name"
            :class="
                bare
                    ? ''
                    : [
                          'overflow-hidden rounded-lg border',
                          group.off
                              ? 'border-dashed'
                              : 'bg-background shadow-xs',
                      ]
            "
        >
            <!-- The card's top: whose data it is, and how a formula names it -->
            <Tip
                v-if="!bare"
                :text="pick ? (group.head?.path ?? undefined) : undefined"
            >
                <div
                    class="group flex items-center gap-2 px-2"
                    :class="[
                        roomy ? 'py-2.5' : 'py-1.5',
                        group.head?.path && pick
                            ? 'hover:bg-primary/5 cursor-pointer active:cursor-grabbing'
                            : '',
                    ]"
                    :draggable="!!group.head?.path && pick"
                    :data-sample="pick ? group.head?.path : undefined"
                    @dragstart="drag($event, group.head?.path ?? null)"
                    @dragend="dropped"
                    @click="
                        group.head?.path &&
                        pick &&
                        emit('pick', group.head.path)
                    "
                >
                    <Tip
                        v-if="group.head?.holds"
                        :text="`${group.head.folded ? 'Open' : 'Fold'} ${group.root.name}`"
                    >
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground -mx-0.5 shrink-0"
                            :aria-label="`${group.head.folded ? 'Open' : 'Fold'} ${group.root.name}`"
                            :aria-expanded="!group.head.folded"
                            @click.stop="toggle(group.head)"
                        >
                            <ChevronRight
                                class="transition-transform"
                                :class="[
                                    roomy ? 'size-4' : 'size-3.5',
                                    { 'rotate-90': !group.head.folded },
                                ]"
                            />
                        </button>
                    </Tip>
                    <span
                        v-if="group.root.icon"
                        class="flex size-5 shrink-0 items-center justify-center rounded"
                        :class="[
                            group.root.tile ?? 'bg-muted text-muted-foreground',
                            { 'opacity-50': group.off },
                        ]"
                    >
                        <component :is="group.root.icon" class="size-3" />
                    </span>
                    <span
                        class="min-w-0 truncate font-medium"
                        :class="{ 'text-muted-foreground': group.off }"
                        >{{ group.root.title ?? group.root.name }}</span
                    >
                    <!-- What a formula calls it, where its title does not say -->
                    <code
                        v-if="
                            group.root.title &&
                            group.root.title.toLowerCase() !== group.root.name
                        "
                        class="text-muted-foreground shrink-0 font-mono text-[10px]"
                        >{{ group.root.name }}</code
                    >
                    <span
                        class="text-muted-foreground ml-auto min-w-0 shrink truncate text-[11px]"
                        :class="{ italic: group.off }"
                        >{{
                            group.root.note ??
                            (group.head?.holds ? '' : group.head?.shown)
                        }}</span
                    >
                </div>
            </Tip>

            <div
                v-if="group.parts.length"
                :class="bare ? '' : 'bg-muted/20 border-t py-1'"
            >
                <Tip
                    v-for="row in group.parts"
                    :key="row.id"
                    :text="
                        pick
                            ? (row.path ?? 'A formula cannot name this part')
                            : undefined
                    "
                >
                    <div
                        class="group flex items-stretch pr-2 pl-2"
                        :class="
                            row.path && pick
                                ? 'hover:bg-primary/5 cursor-pointer active:cursor-grabbing'
                                : ''
                        "
                        :draggable="!!row.path && pick"
                        :data-sample="pick ? row.path : undefined"
                        @dragstart="drag($event, row.path)"
                        @dragend="dropped"
                        @click="row.path && pick && emit('pick', row.path)"
                    >
                        <span
                            v-for="rule in rules(row)"
                            :key="rule"
                            class="border-border/70 ml-[6px] w-[8px] shrink-0 border-l"
                        />
                        <div
                            class="flex min-w-0 flex-1 gap-1.5"
                            :class="[
                                full ? 'items-start' : 'items-center',
                                roomy ? 'py-1.5' : 'py-[3px]',
                            ]"
                        >
                            <Tip
                                v-if="row.holds"
                                :text="`${row.folded ? 'Open' : 'Fold'} ${row.label}`"
                            >
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-foreground shrink-0"
                                    :class="{ 'mt-0.5': full }"
                                    :aria-label="`${row.folded ? 'Open' : 'Fold'} ${row.label}`"
                                    :aria-expanded="!row.folded"
                                    @click.stop="toggle(row)"
                                >
                                    <ChevronRight
                                        class="transition-transform"
                                        :class="[
                                            roomy ? 'size-4' : 'size-3.5',
                                            { 'rotate-90': !row.folded },
                                        ]"
                                    />
                                </button>
                            </Tip>
                            <component
                                :is="LOOKS[row.kind].icon"
                                v-else
                                class="text-muted-foreground/60 shrink-0"
                                :class="[
                                    roomy ? 'size-4 p-0.5' : 'size-3.5 p-px',
                                    { 'mt-0.5': full },
                                ]"
                            />
                            <span
                                v-if="row.label"
                                class="max-w-[55%] shrink-0 truncate"
                                :class="
                                    pick
                                        ? 'bg-muted/70 group-hover:bg-background rounded px-1.5 py-px font-medium'
                                        : 'text-muted-foreground'
                                "
                                >{{ row.label }}</span
                            >
                            <span
                                class="min-w-0 flex-1"
                                :class="[
                                    LOOKS[row.kind].value,
                                    full
                                        ? 'break-words whitespace-pre-wrap'
                                        : 'truncate',
                                ]"
                                >{{ row.shown }}</span
                            >
                            <GripVertical
                                v-if="row.path && pick"
                                class="text-muted-foreground size-3 shrink-0 opacity-0 group-hover:opacity-100"
                            />
                        </div>
                    </div>
                </Tip>
            </div>
        </div>
    </div>
</template>
