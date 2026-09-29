<script setup lang="ts">
import { computed, ref } from 'vue';
import BulkSelectionBar from '@/components/Table/BulkSelectionBar.vue';
import ColumnDialog from '@/components/Table/ColumnDialog.vue';
import GridViewGrid from '@/components/Table/GridViewGrid.vue';
import TableToolbar from '@/components/Table/TableToolbar.vue';
import { useTableStore } from '@/composables/table/useTableStore';
import type { ColumnMeta, ColumnOption, ColumnType } from '@/types';

// The whole table: toolbar, grid, the bar for what is selected, and the dialog
// for a column. It works on the shared table state, so whichever page shows
// it -- a saved table or the demo -- only has to load that state first.
const store = useTableStore();

const showColumnDialog = ref(false);
const editingColumn = ref<ColumnMeta | null>(null);

const densityPaddingClass = computed(() => {
    switch (store.density.value) {
        case 'compact':
            return 'px-2 py-0.5 min-h-[30px]';
        case 'spacious':
            return 'px-3 py-3 min-h-[52px]';
        default:
            return 'px-2 py-1.5 min-h-[38px]';
    }
});

const isFiltered = computed(
    () => !!store.searchQuery.value || store.filters.value.length > 0,
);

const clearFilters = () => {
    store.searchQuery.value = '';
    store.clearFilters();
};

const openAddColumn = () => {
    editingColumn.value = null;
    showColumnDialog.value = true;
};

const editColumn = (column: ColumnMeta) => {
    editingColumn.value = column;
    showColumnDialog.value = true;
};

const saveColumn = (payload: {
    name: string;
    label: string;
    type: ColumnType;
    options?: ColumnOption[];
    extra?: Partial<ColumnMeta>;
}) => {
    if (editingColumn.value) {
        store.updateColumn(editingColumn.value.name, {
            label: payload.label,
            type: payload.type,
            options: payload.options,
            currencySymbol: payload.extra?.currencySymbol,
            maxRating: payload.extra?.maxRating,
        });

        return;
    }

    void store.addColumn(
        payload.name,
        payload.label,
        payload.type,
        payload.options,
        payload.extra,
    );
};

const duplicateSelected = () => {
    store.selectedRowIds.value.forEach((id) => void store.duplicateRow(id));
};
</script>

<template>
    <div
        class="relative flex h-full min-h-0 w-full flex-col gap-3"
        data-test="table-workspace"
    >
        <TableToolbar @open-add-column="openAddColumn" />

        <GridViewGrid
            :columns="store.visibleColumns.value"
            :rows="store.filteredRows.value"
            :selected-row-ids="store.selectedRowIds.value"
            :sort="store.sort.value"
            :is-all-selected="store.isAllSelected.value"
            :is-partially-selected="store.isPartiallySelected.value"
            :is-filtered="isFiltered"
            :density-padding-class="densityPaddingClass"
            @select-all="(checked) => store.handleSelectAll(checked)"
            @toggle-row-selection="(id) => store.toggleRowSelection(id)"
            @patch-cell="
                (id, column, value, index) =>
                    store.patchCellValue(id, column, value, index)
            "
            @add-row="() => void store.addRow()"
            @clear-filters="clearFilters"
            @open-add-column="openAddColumn"
            @edit-column="editColumn"
            @sort-column="(name, direction) => store.setSort(name, direction)"
            @hide-column="(name) => store.toggleColumnVisibility(name)"
            @duplicate-column="(name) => store.duplicateColumn(name)"
            @delete-column="(name) => store.deleteColumn(name)"
            @resize-column="
                (name, width) => store.resizeColumnWidth(name, width)
            "
        />

        <BulkSelectionBar
            :selected-ids="store.selectedRowIds.value"
            @clear="store.selectedRowIds.value = []"
            @delete="store.deleteSelectedRows()"
            @duplicate="duplicateSelected"
        />

        <ColumnDialog
            v-model:open="showColumnDialog"
            :column="editingColumn"
            @save="saveColumn"
            @delete="store.deleteColumn"
        />
    </div>
</template>
