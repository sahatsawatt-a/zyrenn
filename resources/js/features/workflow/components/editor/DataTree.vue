<script setup lang="ts">
import { ChevronRight, GripVertical } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';

// What a step was given or gave, as its parts: each on a line under what
// holds it, folded and opened. Beside a step's settings a part can be picked
// -- dragged into a setting, or clicked; in a run it is only read, with long
// text in full.

const props = defineProps<{
    // Each named; a formula reads a root by its name
    roots: { name: string; value: unknown }[];
    // Parts can be dragged or clicked into a setting
    pick?: boolean;
    // The one root's parts with no line for the root itself
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

type Row = {
    id: string;
    label: string;
    // What a formula reads it as; none for a name it can't be written with
    path: string | null;
    depth: number;
    kind: 'record' | 'list' | 'value';
    folded: boolean;
    shown: string;
    tone: string;
    // Text, which reads better set as text than as code
    text: boolean;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

function shown(value: unknown): { text: string; tone: string } {
    if (value === null || value === undefined || value === '') {
        return { text: 'empty', tone: 'text-muted-foreground italic' };
    }

    if (Array.isArray(value)) {
        return {
            text: `${value.length} ${value.length === 1 ? 'item' : 'items'}`,
            tone: 'text-muted-foreground',
        };
    }

    if (isRecord(value)) {
        const n = Object.keys(value).length;

        return {
            text: `${n} ${n === 1 ? 'part' : 'parts'}`,
            tone: 'text-muted-foreground',
        };
    }

    if (typeof value === 'string') {
        return {
            text:
                !props.full && value.length > LINE
                    ? `${value.slice(0, LINE)}…`
                    : value,
            tone: 'text-foreground',
        };
    }

    return {
        text: String(value),
        tone: 'text-sky-700 dark:text-sky-400',
    };
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

const rows = computed(() => {
    const found: Row[] = [];

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
        const kind = Array.isArray(value)
            ? 'list'
            : isRecord(value)
              ? 'record'
              : 'value';
        const { text, tone } = shown(value);
        const folded = kind !== 'value' && isFolded(id, depth, value);
        found.push({
            id,
            label,
            path,
            depth,
            kind,
            folded,
            shown: text,
            tone,
            text: typeof value === 'string' && value !== '',
        });

        if (kind !== 'value' && !folded) {
            within(id, path, value, depth + 1);
        }
    };

    for (const root of props.roots) {
        const path = props.pick ? root.name : null;
        const holds =
            Array.isArray(root.value) || isRecord(root.value)
                ? Object.keys(root.value as object).length
                : 0;

        if (props.bare && holds) {
            within(root.name, path, root.value, 0);
        } else {
            walk(root.name, props.bare ? '' : root.name, path, root.value, 0);
        }
    }

    return found;
});

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

function drag(event: DragEvent, row: Row) {
    if (!row.path || !event.dataTransfer) {
        return;
    }

    event.dataTransfer.setData('text/plain', `{{ ${row.path} }}`);
    event.dataTransfer.setData('application/x-workflow-path', row.path);
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
    <div class="font-mono" :class="full || roomy ? 'text-xs' : 'text-[11px]'">
        <div
            v-for="row in rows"
            :key="row.id"
            class="group flex gap-1 pr-2"
            :class="[
                full ? 'items-start' : 'items-center',
                roomy ? 'py-1.5' : 'py-0.5',
                row.depth === 0 && !bare ? 'mt-1.5 first:mt-0' : '',
                row.path && pick
                    ? 'hover:bg-primary/5 cursor-pointer active:cursor-grabbing'
                    : '',
            ]"
            :style="{ paddingLeft: `${8 + row.depth * 12}px` }"
            :draggable="!!row.path && pick"
            :title="
                pick
                    ? (row.path ?? 'A formula cannot name this part')
                    : undefined
            "
            :data-sample="pick ? row.path : undefined"
            @dragstart="drag($event, row)"
            @dragend="dropped"
            @click="row.path && pick && emit('pick', row.path)"
        >
            <button
                v-if="row.kind !== 'value'"
                type="button"
                class="text-muted-foreground hover:text-foreground -ml-0.5 shrink-0"
                :class="{ 'mt-0.5': full }"
                :aria-label="`${row.folded ? 'Open' : 'Fold'} ${row.label}`"
                @click.stop="toggle(row)"
            >
                <ChevronRight
                    class="transition-transform"
                    :class="[
                        roomy ? 'size-4' : 'size-3',
                        { 'rotate-90': !row.folded },
                    ]"
                />
            </button>
            <span v-else class="w-2.5 shrink-0" />
            <span
                v-if="row.label"
                class="shrink-0 rounded px-1"
                :class="
                    row.depth === 0 && !bare
                        ? 'bg-background border font-medium'
                        : 'text-muted-foreground'
                "
                >{{ row.label }}</span
            >
            <span
                class="min-w-0 flex-1"
                :class="[
                    row.tone,
                    full ? 'break-words whitespace-pre-wrap' : 'truncate',
                    { 'font-sans': full && row.text },
                ]"
                >{{ row.shown }}</span
            >
            <GripVertical
                v-if="row.path && pick"
                class="text-muted-foreground size-3 shrink-0 opacity-0 group-hover:opacity-100"
            />
        </div>
    </div>
</template>
