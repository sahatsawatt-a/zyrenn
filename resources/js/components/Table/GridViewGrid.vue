<script setup lang="ts">
import { Plus, SearchX, Sheet } from '@lucide/vue';
import GridDataRow from '@/components/Table/GridDataRow.vue';
import GridHeaderRow from '@/components/Table/GridHeaderRow.vue';
import { Button } from '@/components/ui/button';
import type { ColumnMeta, RowData, TableSort } from '@/types';

defineProps<{
    columns: ColumnMeta[];
    rows: RowData[];
    selectedRowIds: number[];
    sort: TableSort | null;
    isAllSelected: boolean;
    isPartiallySelected: boolean;
    // A search or filter is hiding rows, so "nothing here" means something else
    isFiltered: boolean;
    densityPaddingClass: string;
}>();

const emit = defineEmits<{
    (e: 'select-all', checked: boolean): void;
    (e: 'toggle-row-selection', rowId: number): void;
    (
        e: 'patch-cell',
        rowId: number,
        columnName: string,
        value: unknown,
        index: number,
    ): void;
    (e: 'add-row'): void;
    (e: 'clear-filters'): void;
    (e: 'edit-column', column: ColumnMeta): void;
    (e: 'sort-column', columnName: string, direction: 'asc' | 'desc'): void;
    (e: 'hide-column', columnName: string): void;
    (e: 'duplicate-column', columnName: string): void;
    (e: 'delete-column', columnName: string): void;
    (e: 'resize-column', columnName: string, width: number): void;
    (e: 'open-add-column'): void;
}>();
</script>

<template>
    <div
        class="bg-card relative min-h-0 flex-1 overflow-auto rounded-lg border text-sm shadow-xs"
        data-test="table-grid"
    >
        <div class="flex w-full min-w-max flex-col">
            <GridHeaderRow
                :columns="columns"
                :sort="sort"
                :is-all-selected="isAllSelected"
                :is-partially-selected="isPartiallySelected"
                @select-all="emit('select-all', $event)"
                @edit-column="emit('edit-column', $event)"
                @sort-column="(name, dir) => emit('sort-column', name, dir)"
                @hide-column="emit('hide-column', $event)"
                @duplicate-column="emit('duplicate-column', $event)"
                @delete-column="emit('delete-column', $event)"
                @resize-column="
                    (name, width) => emit('resize-column', name, width)
                "
                @open-add-column="emit('open-add-column')"
            />

            <GridDataRow
                v-for="(row, rowIndex) in rows"
                :key="row.id"
                :row="row"
                :row-index="rowIndex"
                :columns="columns"
                :is-selected="selectedRowIds.includes(row.id)"
                :density-padding-class="densityPaddingClass"
                @toggle-row-selection="emit('toggle-row-selection', $event)"
                @patch-cell="
                    (id, column, value, index) =>
                        emit('patch-cell', id, column, value, index)
                "
            />

            <button
                type="button"
                class="border-border/60 text-muted-foreground hover:bg-muted/60 hover:text-foreground flex h-9 w-full items-center border-b text-left text-sm transition-colors"
                @click="emit('add-row')"
            >
                <span class="sticky left-0 flex items-center gap-2 px-4">
                    <Plus class="size-4" /> New row
                </span>
            </button>
        </div>

        <div
            v-if="!rows.length"
            class="pointer-events-none absolute inset-x-0 top-24 flex flex-col items-center gap-2 text-center"
        >
            <div
                class="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-full"
            >
                <SearchX v-if="isFiltered" class="size-5" />
                <Sheet v-else class="size-5" />
            </div>
            <p class="text-sm font-medium">
                {{ isFiltered ? 'No rows match' : 'No rows yet' }}
            </p>
            <p class="text-muted-foreground text-xs">
                {{
                    isFiltered
                        ? 'Try another search, or clear the filters.'
                        : 'Add a row to start filling this table in.'
                }}
            </p>
            <Button
                size="sm"
                variant="outline"
                class="pointer-events-auto mt-1"
                @click="isFiltered ? emit('clear-filters') : emit('add-row')"
            >
                {{ isFiltered ? 'Clear search and filters' : 'Add a row' }}
            </Button>
        </div>
    </div>
</template>
