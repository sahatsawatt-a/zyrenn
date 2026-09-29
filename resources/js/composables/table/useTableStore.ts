import { computed } from 'vue';
import type {
    ColumnMeta,
    ColumnOption,
    ColumnType,
    RowData,
    TableDensity,
} from '@/types';
import { useColumns } from './useColumns';
import { useRows } from './useRows';
import { useTableExport } from './useTableExport';
import { useTableFilters } from './useTableFilters';
import { useTableHistory } from './useTableHistory';
import { useTableSort } from './useTableSort';
import {
    activeTable,
    columns,
    density,
    filters,
    isLoading,
    rows,
    searchQuery,
    selectedRowIds,
    sort,
} from './useTableState';
import { useTableSync } from './useTableSync';

/**
 * Everything the grid does, in one place for its components to call.
 *
 * Each change happens here first, so the grid never waits, and is then handed
 * to useTableSync to save. The state underneath is shared, so the toolbar, the
 * header and a single cell all see the same table.
 */
export function useTableStore() {
    const colModule = useColumns();
    const rowModule = useRows();
    const filterModule = useTableFilters();
    const sortModule = useTableSort();
    const history = useTableHistory();
    const sync = useTableSync();
    const exporter = useTableExport();

    const filteredRows = computed(() =>
        sortModule.applySorting(filterModule.applyFiltering(rows.value)),
    );

    /**
     * Opens a table. Everything left over from the last one -- its search,
     * filters, selection and undo history -- goes, since the state is shared.
     * With no ref_id it is the demo, and nothing is saved.
     */
    const setInertiaStateData = (
        tableColumns: ColumnMeta[],
        tableRows: RowData[],
        refId: string,
        tableDensity: TableDensity = 'normal',
    ): void => {
        activeTable.value = refId;
        columns.value = tableColumns;
        rows.value = tableRows;
        density.value = tableDensity;
        searchQuery.value = '';
        filters.value = [];
        sort.value = null;
        selectedRowIds.value = [];
        history.clear();
    };

    // --------------------------------------------------------------- cells
    const patchCellValue = (
        rowId: number,
        columnName: string,
        newValue: unknown,
        rowIndex?: number,
    ): void => {
        rowModule.patchCellValue(rowId, columnName, newValue, rowIndex);
        sync.cell(rowId, columnName, newValue);
    };

    // ---------------------------------------------------------------- rows
    const addRow = async (): Promise<void> => {
        if (!sync.saving.value) {
            rowModule.addRow();

            return;
        }

        // The server gives the row its id, so the grid waits for that one
        const saved = await sync.createRow();

        if (saved) {
            rows.value.push(saved.row);
            history.clear();
        }
    };

    const duplicateRow = async (rowId: number): Promise<void> => {
        if (!sync.saving.value) {
            rowModule.duplicateRow(rowId);

            return;
        }

        const saved = await sync.duplicateRow(rowId);

        if (saved) {
            const after = rows.value.findIndex((row) => row.id === rowId);
            rows.value.splice(after + 1, 0, saved.row);
            history.clear();
        }
    };

    const deleteSelectedRows = (): void => {
        const ids = [...selectedRowIds.value];

        if (!ids.length) {
            return;
        }

        rowModule.deleteSelectedRows();

        if (sync.saving.value) {
            void sync.deleteRows(ids);
            history.clear();
        }
    };

    const deleteRow = (rowId: number): void => {
        selectedRowIds.value = [rowId];
        deleteSelectedRows();
    };

    // ------------------------------------------------------------- columns
    const addColumn = async (
        name: string,
        label: string,
        type: ColumnType,
        options?: ColumnOption[],
        extra?: Partial<ColumnMeta>,
    ): Promise<void> => {
        if (!sync.saving.value) {
            colModule.addColumn(name, label, type, options, extra);

            return;
        }

        // The server names the column, from its label, so wait for that name
        const saved = await sync.createColumn({
            label: label || name,
            type,
            options: options ?? [],
            currencySymbol: extra?.currencySymbol,
            maxRating: extra?.maxRating,
        });

        if (saved) {
            columns.value.push(saved.column);
            rows.value.forEach((row) => {
                row[saved.column.name] = type === 'multi_select' ? [] : null;
            });
            history.clear();
        }
    };

    const updateColumn = (
        columnName: string,
        updates: Partial<ColumnMeta>,
    ): void => {
        colModule.updateColumn(columnName, updates);
        void sync.updateColumn(columnName, {
            label: updates.label,
            type: updates.type,
            options: updates.options,
            currencySymbol: updates.currencySymbol,
            maxRating: updates.maxRating,
        });
    };

    const deleteColumn = (columnName: string): void => {
        const column = columns.value.find((item) => item.name === columnName);

        if (!column || column.isPrimary) {
            return;
        }

        columns.value = columns.value.filter(
            (item) => item.name !== columnName,
        );

        if (sync.saving.value) {
            void sync.deleteColumn(columnName);
            history.clear();
        }
    };

    const duplicateColumn = (columnName: string): void => {
        const column = columns.value.find((item) => item.name === columnName);

        if (column) {
            // The column is copied; the values in it are not
            void addColumn(
                `${column.name}_copy`,
                `${column.label} (Copy)`,
                column.type,
                column.options,
                column,
            );
        }
    };

    const toggleColumnVisibility = (columnName: string): void => {
        colModule.toggleColumnVisibility(columnName);

        const column = columns.value.find((item) => item.name === columnName);

        if (column) {
            void sync.updateColumn(columnName, { hidden: column.hidden });
        }
    };

    const showAllColumns = (): void => {
        const hidden = columns.value.filter((column) => column.hidden);

        colModule.showAllColumns();
        hidden.forEach(
            (column) => void sync.updateColumn(column.name, { hidden: false }),
        );
    };

    const resizeColumnWidth = (columnName: string, width: number): void => {
        colModule.resizeColumnWidth(columnName, width);
        sync.width(columnName, width);
    };

    const saveOptions = (columnName: string): void => {
        const column = columns.value.find((item) => item.name === columnName);

        if (column) {
            void sync.updateColumn(columnName, {
                options: column.options ?? [],
            });
        }
    };

    const addOptionToColumn = (
        columnName: string,
        option: ColumnOption,
    ): void => {
        colModule.addOptionToColumn(columnName, option);
        saveOptions(columnName);
    };

    const deleteOptionFromColumn = (
        columnName: string,
        optionId: string,
    ): void => {
        colModule.deleteOptionFromColumn(columnName, optionId);
        saveOptions(columnName);
    };

    // ---------------------------------------------------------- undo / redo
    // Undo restores a snapshot; any cell it changes back is saved again, so
    // what is on screen is what is stored.
    const resaveChangedCells = (before: RowData[]) => {
        const earlier = new Map(before.map((row) => [row.id, row]));

        rows.value.forEach((row) => {
            const was = earlier.get(row.id);

            if (!was) {
                return;
            }

            Object.keys(row).forEach((name) => {
                if (
                    name !== 'id' &&
                    JSON.stringify(row[name]) !== JSON.stringify(was[name])
                ) {
                    sync.cell(row.id, name, row[name]);
                }
            });
        });
    };

    const undo = (): void => {
        const before = rows.value.map((row) => ({ ...row }));
        history.undo();
        resaveChangedCells(before);
    };

    const redo = (): void => {
        const before = rows.value.map((row) => ({ ...row }));
        history.redo();
        resaveChangedCells(before);
    };

    return {
        activeTable,
        isLoading,
        searchQuery,
        filters,
        sort,
        density,
        columns,
        rows,
        selectedRowIds,
        saveStatus: sync.status,
        flush: sync.flush,
        updateTable: sync.updateTable,
        setInertiaStateData,
        patchCellValue,
        addRow,
        duplicateRow,
        deleteSelectedRows,
        deleteRow,
        addColumn,
        updateColumn,
        deleteColumn,
        duplicateColumn,
        visibleColumns: colModule.visibleColumns,
        toggleColumnVisibility,
        showAllColumns,
        resizeColumnWidth,
        addOptionToColumn,
        deleteOptionFromColumn,
        undo,
        redo,
        canUndo: history.canUndo,
        canRedo: history.canRedo,
        filteredRows,
        isAllSelected: rowModule.isAllSelected,
        isPartiallySelected: rowModule.isPartiallySelected,
        toggleRowSelection: rowModule.toggleRowSelection,
        handleSelectAll: rowModule.selectAllRows,
        setSort: sortModule.setSort,
        clearSort: sortModule.clearSort,
        addFilter: filterModule.addFilter,
        updateFilter: filterModule.updateFilter,
        removeFilter: filterModule.removeFilter,
        clearFilters: filterModule.clearFilters,
        exportToCsv: () =>
            exporter.download(
                activeTable.value,
                colModule.visibleColumns.value,
                filteredRows.value,
            ),
    };
}
