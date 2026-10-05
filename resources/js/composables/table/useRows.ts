import { computed } from 'vue';
import type { RowData } from '../../types';
import {
    rows,
    columns,
    selectedRowIds,
    searchQuery,
    filters,
    sort,
} from './useTableState';
import { useColumns } from './useColumns';
import { useTableHistory } from './useTableHistory';

export function useRows() {
    const { visibleColumns } = useColumns();
    const history = useTableHistory();

    const isAllSelected = computed(() => {
        return (
            rows.value.length > 0 &&
            selectedRowIds.value.length === filteredRows.value.length
        );
    });

    const isPartiallySelected = computed(() => {
        return (
            selectedRowIds.value.length > 0 &&
            selectedRowIds.value.length < filteredRows.value.length
        );
    });

    const filteredRows = computed(() => {
        let result = [...rows.value];

        // 1. Structural Global Search Query Input Analyzer Loop
        if (searchQuery.value.trim()) {
            const query = searchQuery.value.trim().toLowerCase();
            result = result.filter((row) => {
                return visibleColumns.value.some((col) => {
                    const val = row[col.name];
                    if (val === null || val === undefined) return false;
                    if (Array.isArray(val))
                        return val.some((v) =>
                            String(v).toLowerCase().includes(query),
                        );
                    return String(val).toLowerCase().includes(query);
                });
            });
        }

        // 2. Query Condition Operator Parsers
        if (filters.value.length > 0) {
            result = result.filter((row) => {
                return filters.value.every((f) => {
                    if (!f.column) return true;
                    const val = row[f.column];
                    const filterVal = f.value.toLowerCase().trim();

                    switch (f.operator) {
                        case 'contains':
                            if (Array.isArray(val))
                                return val.some((v) =>
                                    String(v).toLowerCase().includes(filterVal),
                                );
                            return String(val ?? '')
                                .toLowerCase()
                                .includes(filterVal);
                        case 'not_contains':
                            if (Array.isArray(val))
                                return !val.some((v) =>
                                    String(v).toLowerCase().includes(filterVal),
                                );
                            return !String(val ?? '')
                                .toLowerCase()
                                .includes(filterVal);
                        case 'equals':
                            return typeof val === 'boolean'
                                ? String(val) === filterVal
                                : String(val ?? '').toLowerCase() === filterVal;
                        case 'not_equals':
                            return typeof val === 'boolean'
                                ? String(val) !== filterVal
                                : String(val ?? '').toLowerCase() !== filterVal;
                        case 'is_empty':
                            return (
                                val === null ||
                                val === undefined ||
                                val === '' ||
                                (Array.isArray(val) && val.length === 0)
                            );
                        case 'is_not_empty':
                            return (
                                val !== null &&
                                val !== undefined &&
                                val !== '' &&
                                (!Array.isArray(val) || val.length > 0)
                            );
                        case 'gt':
                            return Number(val) > Number(f.value);
                        case 'lt':
                            return Number(val) < Number(f.value);
                        default:
                            return true;
                    }
                });
            });
        }

        // 3. Sorting Execution Block
        if (sort.value && sort.value.column) {
            const { column, direction } = sort.value;
            result.sort((a, b) => {
                const valA = a[column];
                const valB = b[column];

                if (valA === null || valA === undefined || valA === '')
                    return 1;
                if (valB === null || valB === undefined || valB === '')
                    return -1;

                if (typeof valA === 'number' && typeof valB === 'number') {
                    return direction === 'asc' ? valA - valB : valB - valA;
                }
                if (typeof valA === 'boolean' && typeof valB === 'boolean') {
                    return direction === 'asc'
                        ? valA === valB
                            ? 0
                            : valA
                              ? -1
                              : 1
                        : valA === valB
                          ? 0
                          : valA
                            ? 1
                            : -1;
                }

                const strA = String(valA).toLowerCase();
                const strB = String(valB).toLowerCase();
                return direction === 'asc'
                    ? strA.localeCompare(strB)
                    : strB.localeCompare(strA);
            });
        }

        return result;
    });

    const addRow = (customData?: Partial<RowData>): RowData => {
        history.commit();

        const nextId = rows.value.length
            ? Math.max(...rows.value.map((r) => r.id)) + 1
            : 1;
        const newRow: RowData = { id: nextId, ...customData };

        columns.value.forEach((col) => {
            if (!col.isPrimary && newRow[col.name] === undefined) {
                if (col.defaultValue !== undefined)
                    newRow[col.name] = col.defaultValue;
                else if (col.type === 'boolean') newRow[col.name] = false;
                else if (
                    [
                        'integer',
                        'numeric',
                        'currency',
                        'percent',
                        'rating',
                    ].includes(col.type)
                )
                    newRow[col.name] = null;
                else if (col.type === 'multi_select') newRow[col.name] = [];
                else if (col.type === 'location') newRow[col.name] = null;
                else newRow[col.name] = '';
            }
        });

        rows.value.push(newRow);
        return newRow;
    };

    const patchCellValue = (
        rowId: number,
        columnName: string,
        newValue: any,
        rowIndex?: number,
    ): void => {
        const targetIdx =
            rowIndex !== undefined
                ? rowIndex
                : rows.value.findIndex((r) => r.id === rowId);
        if (targetIdx === -1) return;

        // Avoid pushing redundant history snapshots if old text state aligns perfectly
        if (rows.value[targetIdx][columnName] === newValue) return;

        history.commit();
        rows.value[targetIdx][columnName] = newValue;
    };

    const duplicateRow = (rowId: number): void => {
        const targetRow = rows.value.find((r) => r.id === rowId);
        if (!targetRow) return;

        history.commit();
        const nextId = rows.value.length
            ? Math.max(...rows.value.map((r) => r.id)) + 1
            : 1;
        const clonedRow: RowData = {
            ...JSON.parse(JSON.stringify(targetRow)),
            id: nextId,
        };
        if (clonedRow.full_name)
            clonedRow.full_name = `${clonedRow.full_name} (Copy)`;

        const index = rows.value.findIndex((r) => r.id === rowId);
        rows.value.splice(index + 1, 0, clonedRow);
    };

    const deleteRow = (rowId: number, rowIndex?: number): void => {
        history.commit();
        if (rowIndex !== undefined && rows.value[rowIndex]?.id === rowId) {
            rows.value.splice(rowIndex, 1);
        } else {
            const idx = rows.value.findIndex((r) => r.id === rowId);
            if (idx !== -1) rows.value.splice(idx, 1);
        }
        selectedRowIds.value = selectedRowIds.value.filter(
            (id) => id !== rowId,
        );
    };

    const deleteSelectedRows = (): void => {
        if (selectedRowIds.value.length === 0) return;

        history.commit();
        const idsToDelete = new Set(selectedRowIds.value);
        rows.value = rows.value.filter((r) => !idsToDelete.has(r.id));
        selectedRowIds.value = [];
    };

    const toggleRowSelection = (rowId: number): void => {
        const idx = selectedRowIds.value.indexOf(rowId);
        if (idx === -1) {
            selectedRowIds.value.push(rowId);
        } else {
            selectedRowIds.value.splice(idx, 1);
        }
    };

    const selectAllRows = (selected: boolean): void => {
        selectedRowIds.value = selected
            ? filteredRows.value.map((r) => r.id)
            : [];
    };

    return {
        filteredRows,
        isAllSelected,
        isPartiallySelected,
        addRow,
        patchCellValue,
        duplicateRow,
        deleteRow,
        deleteSelectedRows,
        toggleRowSelection,
        selectAllRows,
    };
}
