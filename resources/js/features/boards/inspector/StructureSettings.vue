<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, TextCursorInput, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import type { Item } from '@/features/boards/model/items';
import {
    KEYS,
    VISIBILITIES,
    hasRows,
    headerSide,
    makeRow,
    markShown,
    rowsFromText,
    rowsHeight,
    rowsKind,
    rowsToText,
} from '@/features/boards/model/rows';
import type { Row } from '@/features/boards/model/rows';

// What is inside a shape that has a structure: a table's columns, a class's
// fields and methods, the side a lane's title runs along. Each row is edited
// a part at a time -- its name and what it holds on the row, its key and
// the rest a click away -- or the whole list at once, as text.
const props = defineProps<{ item: Item }>();

const emit = defineEmits<{ update: [Partial<Item>] }>();

const kind = computed(() => rowsKind(props.item));
const rows = computed(() => props.item.rows ?? []);
const table = computed(() => kind.value === 'table');

const words = computed(() =>
    table.value
        ? { many: 'Columns', one: 'column', name: 'name', type: 'type' }
        : { many: 'Members', one: 'member', name: 'name', type: 'type' },
);

// What a column usually holds, offered as the type is typed
const TYPES = [
    'int',
    'bigint',
    'uuid',
    'varchar',
    'text',
    'boolean',
    'date',
    'timestamp',
    'decimal',
    'json',
];

/** The rows changed, and the shape made as tall as they now need. */
const save = (next: Row[]) =>
    emit('update', {
        rows: next,
        height: rowsHeight({ ...props.item, rows: next }),
    });

const change = (id: string, patch: Partial<Row>) =>
    save(rows.value.map((row) => (row.id === id ? { ...row, ...patch } : row)));

const remove = (id: string) => save(rows.value.filter((row) => row.id !== id));

const move = (id: string, by: -1 | 1) => {
    const from = rows.value.findIndex((row) => row.id === id);
    const to = from + by;

    if (from < 0 || to < 0 || to >= rows.value.length) {
        return;
    }

    const next = [...rows.value];

    [next[from], next[to]] = [next[to], next[from]];
    save(next);
};

const names = useTemplateRef<HTMLInputElement[]>('names');

/** A new row at the end, with its name ready to be typed. */
const add = async (method = false) => {
    if (!kind.value) {
        return;
    }

    save([...rows.value, makeRow(kind.value, { method })]);
    await nextTick();
    names.value?.at(-1)?.focus();
};

const onText = (id: string, part: 'name' | 'type', event: Event) => {
    const value = (event.target as HTMLInputElement).value.trim();

    // A method is known by its brackets, typed or taken away
    change(id, {
        [part]: value,
        ...(part === 'name' && !table.value
            ? { method: /\(.*\)/.test(value) }
            : {}),
    });
};

// ------------------------------------------------------------------ As text
// The whole list as lines, for writing ten columns without ten clicks
const asText = ref(false);
const draft = ref('');

watch(asText, (on) => {
    if (on && kind.value) {
        draft.value = rowsToText(kind.value, rows.value);
    }
});

const applyText = () => {
    if (kind.value) {
        save(rowsFromText(kind.value, draft.value, rows.value));
    }
};

const hint = computed(() =>
    table.value
        ? 'A column to a line: its name, what it holds, then pk, fk or uk. A “?” after the type lets it be empty.\n\nid int pk\nemail varchar uk\nbio text?'
        : 'A member to a line: + public, - private, # protected. Brackets make it a method.\n\n+ name: string\n- total(): number',
);
</script>

<template>
    <!-- A lane: which edge its title runs along -->
    <div v-if="!hasRows(item)" class="structure" data-test="structure-config">
        <p class="structure-label">Title</p>
        <div class="structure-segments">
            <button
                v-for="side in ['top', 'left'] as const"
                :key="side"
                type="button"
                :class="{ 'is-on': headerSide(item) === side }"
                :data-test="`header-${side}`"
                @click="emit('update', { variant: side })"
            >
                {{ side === 'top' ? 'Along the top' : 'Down the side' }}
            </button>
        </div>
    </div>

    <div v-else class="structure" data-test="structure-config">
        <div class="structure-head">
            <p class="structure-label">{{ words.many }}</p>
            <button
                type="button"
                class="structure-link"
                :class="{ 'is-on': asText }"
                :aria-pressed="asText"
                title="Write the whole list as text, a line to each"
                data-test="rows-as-text"
                @click="asText = !asText"
            >
                <TextCursorInput class="size-3.5" />
                As text
            </button>
        </div>

        <template v-if="asText">
            <textarea
                v-model="draft"
                class="structure-text"
                rows="7"
                spellcheck="false"
                :placeholder="hint"
                data-test="rows-text"
                @change="applyText"
            />
            <p class="structure-hint">
                {{
                    table
                        ? 'name type, then pk · fk · uk. A “?” after the type lets it be empty.'
                        : '+ public · - private · # protected. Brackets make a method.'
                }}
            </p>
        </template>

        <template v-else>
            <p v-if="!rows.length" class="structure-hint">
                No {{ words.many.toLowerCase() }} yet.
            </p>

            <div
                v-for="(row, index) in rows"
                :key="row.id"
                class="structure-row"
                :data-test="`row-${index}`"
            >
                <!-- Its key or its sign, and behind it everything about the
                     row that is set once and seldom again -->
                <Popover>
                    <PopoverTrigger as-child>
                        <button
                            type="button"
                            class="structure-mark"
                            :class="[
                                `is-${row.mark || 'none'}`,
                                { 'is-key': table },
                            ]"
                            :title="
                                table
                                    ? 'Its key, whether it may be empty, and its place in the list'
                                    : 'Who can see it, and its place in the list'
                            "
                            :data-test="`row-mark-${index}`"
                        >
                            {{ markShown(kind!, row.mark) || '·' }}
                        </button>
                    </PopoverTrigger>
                    <PopoverContent
                        side="left"
                        align="start"
                        class="w-56 p-2.5"
                        :data-test="`row-options-${index}`"
                    >
                        <p class="structure-label">
                            {{ table ? 'Key' : 'Seen by' }}
                        </p>
                        <div class="structure-segments">
                            <button
                                v-for="each in table ? KEYS : VISIBILITIES"
                                :key="each.value"
                                type="button"
                                :title="each.label"
                                :class="{ 'is-on': row.mark === each.value }"
                                :data-test="`row-mark-${index}-${each.value || 'none'}`"
                                @click="change(row.id, { mark: each.value })"
                            >
                                {{
                                    table
                                        ? markShown('table', each.value) ||
                                          'None'
                                        : each.value
                                }}
                            </button>
                        </div>
                        <p v-if="!table" class="structure-hint">
                            {{
                                VISIBILITIES.find(
                                    (each) => each.value === row.mark,
                                )?.label ?? 'Not said'
                            }}
                        </p>

                        <label v-if="table" class="structure-switch">
                            May be empty
                            <Switch
                                :model-value="row.nullable"
                                :data-test="`row-nullable-${index}`"
                                @update:model-value="
                                    change(row.id, { nullable: !!$event })
                                "
                            />
                        </label>
                        <label v-else class="structure-switch">
                            A method
                            <Switch
                                :model-value="row.method"
                                :data-test="`row-method-${index}`"
                                @update:model-value="
                                    change(row.id, { method: !!$event })
                                "
                            />
                        </label>

                        <div class="structure-actions">
                            <button
                                type="button"
                                title="Move up"
                                :disabled="index === 0"
                                :data-test="`row-up-${index}`"
                                @click="move(row.id, -1)"
                            >
                                <ArrowUp class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                title="Move down"
                                :disabled="index === rows.length - 1"
                                :data-test="`row-down-${index}`"
                                @click="move(row.id, 1)"
                            >
                                <ArrowDown class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="is-danger"
                                :title="`Remove this ${words.one}`"
                                :data-test="`row-remove-${index}`"
                                @click="remove(row.id)"
                            >
                                <Trash2 class="size-3.5" />
                                Remove
                            </button>
                        </div>
                    </PopoverContent>
                </Popover>

                <input
                    ref="names"
                    type="text"
                    class="structure-name"
                    :value="row.name"
                    :placeholder="words.name"
                    :aria-label="`${words.one} name`"
                    :data-test="`row-name-${index}`"
                    @change="onText(row.id, 'name', $event)"
                    @keydown.enter="($event.target as HTMLInputElement).blur()"
                />
                <input
                    type="text"
                    class="structure-type"
                    :value="row.type"
                    :placeholder="words.type"
                    :list="table ? 'board-column-types' : undefined"
                    :aria-label="`${words.one} type`"
                    :data-test="`row-type-${index}`"
                    @change="onText(row.id, 'type', $event)"
                    @keydown.enter="($event.target as HTMLInputElement).blur()"
                />
            </div>

            <datalist v-if="table" id="board-column-types">
                <option v-for="type in TYPES" :key="type" :value="type" />
            </datalist>

            <div class="structure-add">
                <button type="button" data-test="row-add" @click="add()">
                    <Plus class="size-3.5" />
                    {{ table ? 'Column' : 'Field' }}
                </button>
                <button
                    v-if="!table"
                    type="button"
                    data-test="row-add-method"
                    @click="add(true)"
                >
                    <Plus class="size-3.5" />
                    Method
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.structure {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.structure-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.structure-label {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.structure-link {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.125rem 0.375rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.structure-link:hover,
.structure-link.is-on {
    color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
}

.structure-hint {
    font-size: 0.6875rem;
    line-height: 1.4;
    color: var(--muted-foreground);
}

/* A row: its mark, its name taking what room there is, and what it holds */
.structure-row {
    display: grid;
    grid-template-columns: 1.75rem minmax(0, 1fr) 5.75rem;
    gap: 2px;
}

.structure-row input,
.structure-text {
    min-width: 0;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
.structure-row input {
    height: 1.75rem;
    padding: 0 0.375rem;
}
.structure-row input:focus,
.structure-text:focus {
    border-color: var(--primary);
    outline: none;
}
.structure-type {
    color: var(--muted-foreground);
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 0.6875rem;
}

.structure-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 1.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--muted-foreground);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.structure-mark.is-key {
    font-size: 0.625rem;
    letter-spacing: 0.02em;
}
.structure-mark:hover {
    background-color: var(--muted);
}
/* A key says which it is by its colour, as it does on the board */
.structure-mark.is-pk {
    color: #b45309;
    border-color: color-mix(in oklab, #b45309 45%, var(--border));
    background-color: color-mix(in oklab, #f59e0b 12%, transparent);
}
.structure-mark.is-fk {
    color: #4f46e5;
    border-color: color-mix(in oklab, #4f46e5 45%, var(--border));
    background-color: color-mix(in oklab, #6366f1 10%, transparent);
}
.structure-mark.is-uk {
    color: #0f766e;
    border-color: color-mix(in oklab, #0f766e 45%, var(--border));
    background-color: color-mix(in oklab, #14b8a6 10%, transparent);
}

.structure-text {
    width: 100%;
    padding: 0.375rem 0.5rem;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 0.6875rem;
    line-height: 1.5;
    resize: vertical;
}

.structure-segments {
    display: flex;
    gap: 2px;
}
.structure-segments button {
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
.structure-segments button:hover {
    background-color: var(--muted);
}
.structure-segments button.is-on {
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-color: var(--primary);
}

.structure-switch {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.5rem;
    font-size: 0.75rem;
}

.structure-actions {
    display: flex;
    gap: 2px;
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px solid var(--border);
}
.structure-actions button,
.structure-add button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    height: 1.75rem;
    padding: 0 0.5rem;
    font-size: 0.6875rem;
    color: var(--foreground);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.structure-actions button:hover:not(:disabled),
.structure-add button:hover {
    background-color: var(--muted);
}
.structure-actions button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.structure-actions button.is-danger {
    margin-left: auto;
    color: var(--destructive);
}

.structure-add {
    display: flex;
    gap: 0.25rem;
    margin-top: 0.125rem;
}
.structure-add button {
    flex: 1;
    color: var(--muted-foreground);
    border-style: dashed;
}
</style>

<style scoped>
/* Under a thumb, everything is a size a thumb can land on */
@media (pointer: coarse) {
    .structure {
        gap: 0.375rem;
    }
    .structure-row {
        grid-template-columns: 2.25rem minmax(0, 1fr) 6rem;
        gap: 4px;
    }
    .structure-row input,
    .structure-mark,
    .structure-segments button,
    .structure-actions button,
    .structure-add button {
        height: 2.25rem;
    }
    .structure-row input {
        font-size: 0.875rem;
    }
}
</style>
