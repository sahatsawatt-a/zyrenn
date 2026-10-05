import { computed } from 'vue';
import type { ColumnMeta, ColumnOption, ColumnType } from '../../types';
import { columns } from './useTableState';
import { useTableHistory } from './useTableHistory';

export function useColumns() {
    const history = useTableHistory();
    const visibleColumns = computed(() =>
        columns.value.filter((c) => !c.hidden),
    );

    const addColumn = (
        name: string,
        label: string,
        type: ColumnType,
        customOptions?: ColumnOption[],
        extra?: Partial<ColumnMeta>,
    ): void => {
        history.commit();

        let sanitizedName = name.toLowerCase().trim().replace(/\s+/g, '_');
        if (!sanitizedName) {
            sanitizedName = `col_${Date.now()}`;
        }

        let finalName = sanitizedName;
        let counter = 1;
        while (columns.value.some((c) => c.name === finalName)) {
            finalName = `${sanitizedName}_${counter++}`;
        }

        const defaultWidths: Record<ColumnType, number> = {
            varchar: 180,
            text: 220,
            integer: 120,
            numeric: 120,
            boolean: 85,
            select: 160,
            multi_select: 220,
            date: 140,
            email: 190,
            url: 180,
            phone: 150,
            currency: 140,
            percent: 120,
            rating: 130,
            user: 160,
            location: 240,
            formula: 150,
        };

        const newColumn: ColumnMeta = {
            name: finalName,
            label: label || name,
            type: type,
            isPrimary: false,
            width: extra?.width || defaultWidths[type] || 180,
            options: customOptions || [],
            currencySymbol: extra?.currencySymbol || '$',
            maxRating: extra?.maxRating || 5,
            defaultValue: extra?.defaultValue,
            hidden: false,
        };

        columns.value.push(newColumn);
    };

    const updateColumn = (
        columnName: string,
        updates: Partial<ColumnMeta>,
    ): void => {
        history.commit();
        const col = columns.value.find((c) => c.name === columnName);
        if (col) Object.assign(col, updates);
    };

    const toggleColumnVisibility = (columnName: string): void => {
        history.commit();
        const col = columns.value.find((c) => c.name === columnName);
        if (col && !col.isPrimary) col.hidden = !col.hidden;
    };

    const showAllColumns = (): void => {
        history.commit();
        columns.value.forEach((c) => (c.hidden = false));
    };

    const resizeColumnWidth = (
        columnName: string,
        targetWidth: number,
    ): void => {
        const targetCol = columns.value.find((c) => c.name === columnName);
        if (targetCol) {
            targetCol.width = targetWidth;
        }
    };

    const addOptionToColumn = (
        columnName: string,
        option: ColumnOption,
    ): void => {
        history.commit();
        const targetCol = columns.value.find((c) => c.name === columnName);
        if (targetCol) {
            if (!targetCol.options) targetCol.options = [];
            targetCol.options.push(option);
        }
    };

    const deleteOptionFromColumn = (
        columnName: string,
        optionId: string,
    ): void => {
        history.commit();
        const targetCol = columns.value.find((c) => c.name === columnName);
        if (targetCol && targetCol.options) {
            targetCol.options = targetCol.options.filter(
                (o) => o.id !== optionId,
            );
        }
    };

    return {
        visibleColumns,
        addColumn,
        updateColumn,
        toggleColumnVisibility,
        showAllColumns,
        resizeColumnWidth,
        addOptionToColumn,
        deleteOptionFromColumn,
    };
}
