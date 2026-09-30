import type { RowData, TableDensity } from '@/types';
import { rows } from './useTableState';

// What someone else changed in the table (App\Events\TableChanged)
export type TableChange =
    | { change: 'row'; row: RowData }
    | { change: 'rows.deleted'; ids: number[] }
    | { change: 'table'; title: string; density: TableDensity }
    | { change: 'reload' }
    | { change: 'deleted' };

/**
 * Puts a row someone else added or changed where it belongs: over the row it
 * was, or at the end when it is new. Last write wins, cell by cell, as in any
 * spreadsheet shared live.
 */
export function upsertRow(row: RowData): void {
    const at = rows.value.findIndex((item) => item.id === row.id);

    if (at === -1) {
        rows.value.push(row);
    } else {
        rows.value.splice(at, 1, row);
    }
}

/** Takes out rows someone else deleted. */
export function removeRows(ids: number[]): void {
    const gone = new Set(ids);
    rows.value = rows.value.filter((row) => !gone.has(row.id));
}
