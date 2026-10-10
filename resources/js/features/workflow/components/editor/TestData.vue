<script setup lang="ts">
import { ChevronRight, GripVertical, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import PickOne from '@/features/workflow/components/editor/PickOne.vue';
import type { Sample } from '@/features/workflow/composables/useTestData';
import { statusLook } from '@/features/workflow/steps';
import type { RunSummary } from '@/features/workflow/types';

// What a step can read, as a real run had it, beside its settings: each part
// is dragged into a setting -- as {{ trigger.chat.id }} into a template, or
// bare into a formula. As in n8n and Activepieces, set up from what came.

const props = defineProps<{
    samples: Sample[];
    runs: RunSummary[];
    // The run shown
    run: string | null;
    loading: boolean;
    editable: boolean;
}>();

const emit = defineEmits<{
    choose: [ref: string];
    dragging: [on: boolean];
    close: [];
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

type Row = {
    id: string;
    label: string;
    // What a formula reads it as; none for a name it can't be written with
    path: string | null;
    depth: number;
    kind: 'record' | 'list' | 'value';
    shown: string;
    tone: string;
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
            text: value.length > 80 ? `${value.slice(0, 80)}…` : value,
            tone: 'text-foreground',
        };
    }

    return {
        text: String(value),
        tone: 'text-sky-700 dark:text-sky-400',
    };
}

// The parts folded away; the rest are open
const folded = ref(new Set<string>());

function toggle(id: string) {
    const next = new Set(folded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    folded.value = next;
}

const rows = computed(() => {
    const found: Row[] = [];

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
        found.push({ id, label, path, depth, kind, shown: text, tone });

        if (kind === 'value' || folded.value.has(id)) {
            return;
        }

        if (Array.isArray(value)) {
            value
                .slice(0, SHOWN_ITEMS)
                .forEach((item, at) =>
                    walk(
                        `${id}/${at}`,
                        `${at + 1}`,
                        path === null ? null : `item(${path}, ${at + 1})`,
                        item,
                        depth + 1,
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
                depth + 1,
            );
        }
    };

    for (const sample of props.samples) {
        if (sample.reached) {
            walk(sample.name, sample.name, sample.name, sample.value, 0);
        }
    }

    return found;
});

const unreached = computed(() =>
    props.samples.filter((sample) => !sample.reached),
);

const runOptions = computed(() =>
    props.runs.map((run) => ({
        value: run.ref_id,
        label: `${statusLook(run.status).label} · ${new Date(run.created_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}`,
        hint: run.version.kind === 'test' ? 'test' : `v${run.version.number}`,
    })),
);

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
    <aside
        class="bg-muted/20 flex w-72 shrink-0 flex-col border-l"
        data-test-data
    >
        <div class="flex items-center gap-2 border-b px-3 py-2.5">
            <p class="flex-1 text-sm font-medium">Test data</p>
            <Button
                size="icon-sm"
                variant="ghost"
                aria-label="Hide the test data"
                @click="emit('close')"
            >
                <X />
            </Button>
        </div>

        <div class="border-b p-3">
            <PickOne
                :model-value="run"
                :options="runOptions"
                placeholder="No runs yet"
                :disabled="!runs.length"
                @update:model-value="$event && emit('choose', $event)"
            />
            <p class="text-muted-foreground mt-1.5 text-xs leading-relaxed">
                <template v-if="!runs.length">
                    Run it once, or let its trigger fire, and what each step
                    gives shows here.
                </template>
                <template v-else-if="editable">
                    Drag a part into a setting.
                </template>
                <template v-else>What this run gave.</template>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto py-1.5 font-mono text-[11px]">
            <p
                v-if="loading && !rows.length"
                class="text-muted-foreground px-3 py-2 font-sans text-xs"
            >
                Loading…
            </p>
            <div
                v-for="row in rows"
                :key="row.id"
                class="group flex items-center gap-1 py-0.5 pr-2"
                :class="[
                    row.depth === 0 ? 'mt-1.5 first:mt-0' : '',
                    row.path && editable
                        ? 'hover:bg-primary/5 cursor-grab active:cursor-grabbing'
                        : '',
                ]"
                :style="{ paddingLeft: `${8 + row.depth * 12}px` }"
                :draggable="!!row.path && editable"
                :title="row.path ?? 'A formula cannot name this part'"
                :data-sample="row.path"
                @dragstart="drag($event, row)"
                @dragend="dropped"
            >
                <button
                    v-if="row.kind !== 'value'"
                    type="button"
                    class="text-muted-foreground hover:text-foreground -ml-0.5 shrink-0"
                    :aria-label="`${folded.has(row.id) ? 'Open' : 'Fold'} ${row.label}`"
                    @click="toggle(row.id)"
                >
                    <ChevronRight
                        class="size-3 transition-transform"
                        :class="{ 'rotate-90': !folded.has(row.id) }"
                    />
                </button>
                <span v-else class="w-2.5 shrink-0" />
                <span
                    class="shrink-0 rounded px-1"
                    :class="
                        row.depth === 0
                            ? 'bg-background border font-medium'
                            : 'text-muted-foreground'
                    "
                    >{{ row.label }}</span
                >
                <span class="min-w-0 flex-1 truncate" :class="row.tone">{{
                    row.shown
                }}</span>
                <GripVertical
                    v-if="row.path && editable"
                    class="text-muted-foreground size-3 shrink-0 opacity-0 group-hover:opacity-100"
                />
            </div>

            <div
                v-for="sample in unreached"
                :key="sample.name"
                class="mt-1.5 flex items-center gap-1 px-2 py-0.5"
            >
                <span class="w-2.5 shrink-0" />
                <span class="bg-background shrink-0 rounded border px-1">{{
                    sample.name
                }}</span>
                <span
                    class="text-muted-foreground truncate font-sans text-xs italic"
                    >{{ runs.length ? 'not reached in this run' : '' }}</span
                >
            </div>
        </div>
    </aside>
</template>
