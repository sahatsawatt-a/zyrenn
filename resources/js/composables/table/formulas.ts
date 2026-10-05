import type { ColumnMeta, ColumnSummary } from '@/types';
import { formatCurrency } from './useCellCell';

/** What a formula that can't be worked out for a row comes back as. */
export interface FormulaError {
    error: string;
}

export const isFormulaError = (value: unknown): value is FormulaError =>
    typeof value === 'object' &&
    value !== null &&
    typeof (value as FormulaError).error === 'string';

export const SUMMARIES: { id: ColumnSummary; label: string }[] = [
    { id: 'sum', label: 'Sum' },
    { id: 'avg', label: 'Average' },
    { id: 'min', label: 'Min' },
    { id: 'max', label: 'Max' },
    { id: 'count', label: 'Count' },
];

/**
 * A number as the column shows it: as money with its symbol, as a
 * percentage, or plainly, to as many places as it has (up to four).
 */
export function numberText(value: number, column: ColumnMeta): string {
    if (column.currencySymbol && column.type !== 'percent') {
        return formatCurrency(value, column.currencySymbol);
    }

    const plain = value.toLocaleString('en-US', { maximumFractionDigits: 4 });

    return column.type === 'percent' ? `${plain}%` : plain;
}

/** A formula's answer as its cell shows it. */
export function formulaText(value: unknown, column: ColumnMeta): string {
    if (value === null || value === undefined || isFormulaError(value)) {
        return '';
    }

    if (typeof value === 'number') {
        return numberText(value, column);
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value as string);
}

/**
 * What the footer shows under a column, over these rows -- the same sums the
 * server makes for get-table (TableFormulas::totals): empty cells and
 * formulas that failed are left out.
 */
export function summarize(
    values: unknown[],
    summary: ColumnSummary,
): number | null {
    const filled = values.filter(
        (value) =>
            value !== null &&
            value !== undefined &&
            value !== '' &&
            !isFormulaError(value),
    );

    // Anything there counts, as on the server; the rest add up numbers
    if (summary === 'count') {
        return filled.length;
    }

    const numbers = filled.map(Number).filter((value) => !Number.isNaN(value));

    if (!numbers.length) {
        return null;
    }

    switch (summary) {
        case 'sum':
            return numbers.reduce((total, value) => total + value, 0);
        case 'avg':
            return (
                numbers.reduce((total, value) => total + value, 0) /
                numbers.length
            );
        case 'min':
            return Math.min(...numbers);
        case 'max':
            return Math.max(...numbers);
    }
}
