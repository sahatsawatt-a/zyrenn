<script setup lang="ts">
import { Map as MapIcon, Table2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BulkSelectionBar from '@/components/Table/BulkSelectionBar.vue';
import ColumnDialog from '@/components/Table/ColumnDialog.vue';
import GridViewGrid from '@/components/Table/GridViewGrid.vue';
import TableMapView from '@/components/Table/TableMapView.vue';
import TableParameters from '@/components/Table/TableParameters.vue';
import TableToolbar from '@/components/Table/TableToolbar.vue';
import {
    activeTable,
    columns,
    selectedRowIds,
} from '@/composables/table/useTableState';
import { useTableStore } from '@/composables/table/useTableStore';
import type { ColumnMeta, ColumnOption, ColumnType } from '@/types';

// The whole table: toolbar, grid, the bar for what is selected, and the dialog
// for a column. It works on the shared table state, so whichever page shows
// it -- a saved table or the demo -- only has to load that state first.
const store = useTableStore();

const emit = defineEmits<{
    // Formulas or parameters changed: only the server works the rows out again
    (e: 'reload'): void;
}>();

// The grid, or -- when the table has a place in it -- the rows on a map.
// Which one is remembered for each table, in this browser
const hasPlaces = computed(() =>
    columns.value.some((column) => column.type === 'location'),
);
const viewKey = () => `zyrenn:table-view:${activeTable.value}`;
const view = ref<'grid' | 'map'>('grid');

watch(
    activeTable,
    () => {
        try {
            view.value =
                localStorage.getItem(viewKey()) === 'map' ? 'map' : 'grid';
        } catch {
            view.value = 'grid';
        }
    },
    { immediate: true },
);

watch(view, (now) => {
    try {
        localStorage.setItem(viewKey(), now);
    } catch {
        // a private window may refuse; the choice lasts until the page closes
    }
});

/** From the map back to the grid, with the row picked there selected. */
const showRow = (id: number) => {
    view.value = 'grid';
    selectedRowIds.value = [id];
};

const showColumnDialog = ref(false);
const editingColumn = ref<ColumnMeta | null>(null);
// Why the server wouldn't take a formula, and whether it is being asked
const columnError = ref<string | null>(null);
const columnBusy = ref(false);

watch(showColumnDialog, (isOpen) => {
    if (isOpen) {
        columnError.value = null;
    }
});

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
    if (payload.type === 'formula') {
        void saveFormula(payload.label, payload.extra ?? {});

        return;
    }

    if (editingColumn.value) {
        store.updateColumn(editingColumn.value.name, {
            label: payload.label,
            type: payload.type,
            options: payload.options,
            currencySymbol: payload.extra?.currencySymbol,
            maxRating: payload.extra?.maxRating,
            summary: payload.extra?.summary,
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

/** A formula column is kept once the server has taken its formula, which it then works out. */
const saveFormula = async (label: string, extra: Partial<ColumnMeta>) => {
    columnBusy.value = true;
    columnError.value = await store.saveFormulaColumn(
        editingColumn.value?.name ?? null,
        { ...extra, label },
    );
    columnBusy.value = false;

    if (columnError.value === null) {
        showColumnDialog.value = false;
        emit('reload');
    }
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
        <div class="flex flex-wrap items-start gap-2">
            <TableToolbar
                class="min-w-0 flex-1"
                @open-add-column="openAddColumn"
            />
            <div
                v-if="hasPlaces"
                class="flex rounded-md border p-0.5 text-xs"
                role="tablist"
                aria-label="View"
                data-test="table-view"
            >
                <button
                    type="button"
                    role="tab"
                    :aria-selected="view === 'grid'"
                    class="flex items-center gap-1.5 rounded px-2.5 py-1"
                    :class="
                        view === 'grid'
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    data-test="view-grid"
                    @click="view = 'grid'"
                >
                    <Table2 class="size-3.5" /> Grid
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="view === 'map'"
                    class="flex items-center gap-1.5 rounded px-2.5 py-1"
                    :class="
                        view === 'map'
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    data-test="view-map"
                    @click="view = 'map'"
                >
                    <MapIcon class="size-3.5" /> Map
                </button>
            </div>
        </div>

        <TableParameters @saved="emit('reload')" />

        <TableMapView
            v-if="view === 'map' && hasPlaces"
            :columns="store.visibleColumns.value"
            :rows="store.filteredRows.value"
            @show-row="showRow"
        />
        <GridViewGrid
            v-else
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
            :error="columnError"
            :busy="columnBusy"
            @save="saveColumn"
            @delete="store.deleteColumn"
        />
    </div>
</template>
