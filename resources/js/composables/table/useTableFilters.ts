import { filters, searchQuery } from './useTableState';
import { useColumns } from './useColumns';
import type { TableFilter, RowData } from '../../types';

export function useTableFilters() {
    const { visibleColumns } = useColumns();

    const addFilter = (filter?: Partial<TableFilter>): void => {
        const firstCol = visibleColumns.value[0]?.name || 'id';
        filters.value.push({
            id: String(Date.now() + Math.random()),
            column: filter?.column || firstCol,
            operator: filter?.operator || 'contains',
            value: filter?.value || '',
        });
    };

    const updateFilter = (id: string, updates: Partial<TableFilter>): void => {
        const f = filters.value.find((item) => item.id === id);
        if (f) Object.assign(f, updates);
    };

    const removeFilter = (id: string): void => {
        filters.value = filters.value.filter((f) => f.id !== id);
    };

    const applyFiltering = (rawRows: RowData[]): RowData[] => {
        let result = [...rawRows];

        // 1. Fuzzy Text Query Scanner Loop
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

        // 2. Structured Operator Matrix Evaluation Loop
        if (filters.value.length > 0) {
            result = result.filter((row) => {
                return filters.value.every((f) => {
                    if (!f.column) return true;
                    const val = row[f.column];
                    const filterVal = f.value.toLowerCase().trim();

                    switch (f.operator) {
                        case 'contains':
                            return Array.isArray(val)
                                ? val.some((v) =>
                                      String(v)
                                          .toLowerCase()
                                          .includes(filterVal),
                                  )
                                : String(val ?? '')
                                      .toLowerCase()
                                      .includes(filterVal);
                        case 'not_contains':
                            return Array.isArray(val)
                                ? !val.some((v) =>
                                      String(v)
                                          .toLowerCase()
                                          .includes(filterVal),
                                  )
                                : !String(val ?? '')
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
        return result;
    };

    return {
        filters,
        addFilter,
        updateFilter,
        removeFilter,
        applyFiltering,
        clearFilters: () => {
            filters.value = [];
        },
    };
}
