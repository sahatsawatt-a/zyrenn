import { cellText } from './cellText';
import { sort } from './useTableState';
import type { RowData } from '../../types';

export function useTableSort() {
    const setSort = (column: string, direction?: 'asc' | 'desc'): void => {
        if (!direction) {
            if (sort.value && sort.value.column === column) {
                sort.value =
                    sort.value.direction === 'asc'
                        ? { column, direction: 'desc' }
                        : null;
            } else {
                sort.value = { column, direction: 'asc' };
            }
        } else {
            sort.value = { column, direction };
        }
    };

    const applySorting = (rowsToSort: RowData[]): RowData[] => {
        if (!sort.value || !sort.value.column) return rowsToSort;
        const { column, direction } = sort.value;

        return [...rowsToSort].sort((a, b) => {
            const valA = a[column];
            const valB = b[column];

            if (valA === null || valA === undefined || valA === '') return 1;
            if (valB === null || valB === undefined || valB === '') return -1;

            if (typeof valA === 'number' && typeof valB === 'number')
                return direction === 'asc' ? valA - valB : valB - valA;
            if (typeof valA === 'boolean' && typeof valB === 'boolean')
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

            return direction === 'asc'
                ? cellText(valA)
                      .toLowerCase()
                      .localeCompare(cellText(valB).toLowerCase())
                : cellText(valB)
                      .toLowerCase()
                      .localeCompare(cellText(valA).toLowerCase());
        });
    };

    return {
        sort,
        setSort,
        applySorting,
        clearSort: () => {
            sort.value = null;
        },
    };
}
