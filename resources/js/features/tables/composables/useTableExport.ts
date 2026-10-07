import type { ColumnMeta, RowData } from '@/types';

/** One value as a CSV cell: quoted when it has to be, lists joined with commas. */
const cell = (value: unknown): string => {
    const text = Array.isArray(value)
        ? value.join(', ')
        : value === null || value === undefined
          ? ''
          : typeof value === 'object'
            ? JSON.stringify(value)
            : String(value as string | number | boolean);

    return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
};

/**
 * The table as a CSV file: the columns that are showing, and the rows that
 * pass the current search and filters, in the order they are shown.
 */
export function useTableExport() {
    const toCsv = (columns: ColumnMeta[], rows: RowData[]): string =>
        [
            columns.map((column) => cell(column.label)).join(','),
            ...rows.map((row) =>
                columns.map((column) => cell(row[column.name])).join(','),
            ),
        ].join('\r\n');

    const download = (
        name: string,
        columns: ColumnMeta[],
        rows: RowData[],
    ): void => {
        // A byte-order mark, so a spreadsheet opens anything beyond ASCII correctly
        const blob = new Blob(['﻿', toCsv(columns, rows)], {
            type: 'text/csv;charset=utf-8',
        });
        const link = document.createElement('a');

        link.href = URL.createObjectURL(blob);
        link.download = `${name || 'table'}.csv`;
        link.click();
        URL.revokeObjectURL(link.href);
    };

    return { toCsv, download };
}
