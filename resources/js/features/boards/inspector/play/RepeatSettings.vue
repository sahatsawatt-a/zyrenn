<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import FoldSection from '@/components/common/FoldSection.vue';
import NumberField from '@/components/common/NumberField.vue';
import Tip from '@/components/common/Tip.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Repeat } from '@/features/prototypes/interactions';
import type { Item } from '../../model/items';
import { REPEAT_ROWS, repeatOf } from '../../model/links';
import type { ProjectTable } from './tables';
import { tableOf } from './tables';

// A card made a row: shown in Play once for each row of a project table,
// with what lies inside it, its words' {Column} that row's. Which rows, in
// what order, how many, how far apart and which way are folded away -- most
// rows cards want only the table.
const props = defineProps<{
    card: Item;
    tables: ProjectTable[];
}>();

const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const NONE = '__none__';

const repeat = computed(() => repeatOf(props.card));
const table = computed(() => tableOf(props.tables, repeat.value?.table));
const columns = computed(
    () => table.value?.columns.map((each) => each.label) ?? [],
);

const set = (next: Repeat | null) => emit('update', { repeat: next });
const patch = (fields: Partial<Repeat>) =>
    repeat.value && set({ ...repeat.value, ...fields });
const without = (field: keyof Repeat) => {
    if (repeat.value) {
        const copy = { ...repeat.value };
        delete copy[field];
        set(copy);
    }
};

const pickTable = (ref: unknown) =>
    set(
        ref === NONE
            ? null
            : ({
                  ...repeat.value,
                  table: String(ref),
                  where: undefined,
                  sort: undefined,
              } as Repeat),
    );

const where = computed(() => repeat.value?.where ?? []);

// "{Title} {Status}": what the row's words may say
const saidColumns = computed(() =>
    columns.value
        .filter((column) => column !== 'ID')
        .map((column) => '{' + column + '}')
        .join(' '),
);

const setWhere = (next: { column: string; is: string }[]) =>
    next.length ? patch({ where: next }) : without('where');

const open = ref(false);
const said = computed(() => {
    const now = repeat.value;

    if (!now) {
        return undefined;
    }

    return (
        [
            where.value.length
                ? `${where.value.length} filter${where.value.length === 1 ? '' : 's'}`
                : '',
            now.sort ? `by ${now.sort.column}` : '',
            now.limit ? `up to ${now.limit}` : '',
            now.across ? 'across' : '',
        ]
            .filter(Boolean)
            .join(', ') || undefined
    );
});
</script>

<template>
    <div class="flex flex-col gap-1" data-test="repeat-config">
        <label class="repeat-field">
            Repeat for each row of
            <Select
                :model-value="repeat?.table ?? NONE"
                @update:model-value="pickTable"
            >
                <SelectTrigger
                    size="sm"
                    class="h-8 w-full text-xs"
                    data-test="repeat-table"
                    :data-value="repeat?.table ?? ''"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="NONE" class="text-xs"
                        >Nothing: it is shown once</SelectItem
                    >
                    <SelectItem
                        v-for="each in tables"
                        :key="each.ref_id"
                        :value="each.ref_id"
                        class="text-xs"
                        :data-test="`repeat-table-${each.ref_id}`"
                        >{{ each.title }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </label>
        <p v-if="!tables.length" class="repeat-said">
            No tables in this project yet.
        </p>

        <template v-if="repeat && table">
            <p class="repeat-said">
                Everything inside it is one row. Its words may say
                <span class="font-mono">{{ saidColumns }}</span
                >.
            </p>

            <FoldSection
                v-model:open="open"
                title="Which rows"
                :hint="said"
                data-test="repeat-more"
            >
                <div class="flex flex-col gap-2">
                    <!-- Only the rows where a column is so -->
                    <div
                        v-for="(each, at) in where"
                        :key="at"
                        class="repeat-where"
                    >
                        <Select
                            :model-value="each.column"
                            @update:model-value="
                                setWhere(
                                    where.map((was, index) =>
                                        index === at
                                            ? { ...was, column: String($event) }
                                            : was,
                                    ),
                                )
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="h-8 min-w-0 flex-1 text-xs"
                                aria-label="Column"
                                :data-test="`repeat-where-column-${at}`"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="column in columns"
                                    :key="column"
                                    :value="column"
                                    class="text-xs"
                                    >{{ column }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <span class="repeat-word">is</span>
                        <Input
                            :model-value="each.is"
                            class="h-8 min-w-0 flex-1 text-xs"
                            placeholder="Words, or {a name}"
                            aria-label="Is"
                            :data-test="`repeat-where-is-${at}`"
                            @change="
                                setWhere(
                                    where.map((was, index) =>
                                        index === at
                                            ? {
                                                  ...was,
                                                  is: (
                                                      $event.target as HTMLInputElement
                                                  ).value,
                                              }
                                            : was,
                                    ),
                                )
                            "
                        />
                        <Tip text="No filter">
                            <button
                                type="button"
                                class="repeat-remove"
                                aria-label="No filter"
                                @click="
                                    setWhere(
                                        where.filter(
                                            (_, index) => index !== at,
                                        ),
                                    )
                                "
                            >
                                <X class="size-3.5" />
                            </button>
                        </Tip>
                    </div>
                    <button
                        v-if="columns.length"
                        type="button"
                        class="repeat-add"
                        data-test="repeat-add-where"
                        @click="
                            setWhere([...where, { column: columns[0], is: '' }])
                        "
                    >
                        <Plus class="size-3.5" /> Only rows where…
                    </button>

                    <!-- In the order of a column -->
                    <div class="repeat-where">
                        <Select
                            :model-value="repeat.sort?.column ?? NONE"
                            @update:model-value="
                                $event === NONE
                                    ? without('sort')
                                    : patch({
                                          sort: {
                                              column: String($event),
                                              ...(repeat.sort?.desc
                                                  ? { desc: true }
                                                  : {}),
                                          },
                                      })
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="h-8 min-w-0 flex-1 text-xs"
                                aria-label="In the order of"
                                data-test="repeat-sort"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE" class="text-xs"
                                    >As the table has them</SelectItem
                                >
                                <SelectItem
                                    v-for="column in columns"
                                    :key="column"
                                    :value="column"
                                    class="text-xs"
                                    >By {{ column }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Select
                            v-if="repeat.sort"
                            :model-value="repeat.sort.desc ? 'desc' : 'asc'"
                            @update:model-value="
                                patch({
                                    sort: {
                                        column: repeat.sort!.column,
                                        ...($event === 'desc'
                                            ? { desc: true }
                                            : {}),
                                    },
                                })
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="h-8 w-24 text-xs"
                                aria-label="Which way"
                                data-test="repeat-order"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="asc" class="text-xs"
                                    >A to Z</SelectItem
                                >
                                <SelectItem value="desc" class="text-xs"
                                    >Z to A</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

                    <label class="repeat-line">
                        At most
                        <NumberField
                            :model-value="repeat.limit ?? REPEAT_ROWS.usual"
                            label="Rows at most"
                            :min="REPEAT_ROWS.least"
                            :max="REPEAT_ROWS.most"
                            unit="rows"
                            compact
                            data-test="repeat-limit"
                            @change="
                                $event !== null && patch({ limit: $event })
                            "
                        />
                    </label>
                    <label class="repeat-line">
                        Apart by
                        <NumberField
                            :model-value="repeat.gap ?? 0"
                            label="Room between rows"
                            :min="0"
                            :max="400"
                            unit="px"
                            compact
                            data-test="repeat-gap"
                            @change="$event !== null && patch({ gap: $event })"
                        />
                    </label>
                    <Select
                        :model-value="repeat.across ? 'across' : 'down'"
                        @update:model-value="
                            $event === 'across'
                                ? patch({ across: true })
                                : without('across')
                        "
                    >
                        <SelectTrigger
                            size="sm"
                            class="h-8 w-full text-xs"
                            aria-label="Which way the rows go"
                            data-test="repeat-direction"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="down" class="text-xs"
                                >One under another</SelectItem
                            >
                            <SelectItem value="across" class="text-xs"
                                >Side by side</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </FoldSection>
        </template>
    </div>
</template>

<style scoped>
.repeat-field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.repeat-said,
.repeat-word {
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.repeat-where,
.repeat-line {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.repeat-line {
    gap: 0.5rem;
    font-size: 0.75rem;
}

.repeat-add,
.repeat-remove {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
    border-radius: var(--radius-sm);
}

.repeat-add {
    align-self: flex-start;
    padding: 0.125rem 0.25rem;
}

.repeat-remove {
    justify-content: center;
    width: 1.75rem;
    height: 2rem;
    flex-shrink: 0;
}

.repeat-add:hover,
.repeat-remove:hover {
    color: var(--foreground);
    background-color: var(--muted);
}
</style>
