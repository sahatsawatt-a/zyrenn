import { useDebounceFn } from '@vueuse/core';
import { computed, ref } from 'vue';
import * as api from '@/features/tables/lib/api';
import type { ColumnChanges } from '@/features/tables/lib/api';
import { applyComputed } from './useTableLive';
import { activeTable } from './useTableState';

export type SaveStatus = 'saved' | 'saving' | 'unsaved' | 'error';

/**
 * Telling the server what the grid has already done.
 *
 * The grid changes itself first, so nothing waits on a round trip; each change
 * is then sent here. Typing is gathered up and sent once it stops, one entry per
 * cell -- a single debounced call would keep only the last cell's arguments and
 * lose the rest. A table with no ref_id is the demo, and saves nowhere.
 *
 * Like the table's state, there is one of these however many components ask
 * for it: the toolbar and a cell must see the same pending edits and status.
 */
let shared: ReturnType<typeof createSync> | null = null;

export function useTableSync() {
    shared ??= createSync();

    return shared;
}

function createSync() {
    const status = ref<SaveStatus>('saved');
    const saving = computed(() => activeTable.value !== '');

    // Cells waiting to be sent, by "row:column", so a second edit replaces the first
    const pendingCells = new Map<
        string,
        { row: number; column: string; value: unknown }
    >();
    const pendingWidths = new Map<string, number>();

    /** Runs one request, keeping the status honest either way. */
    const run = async <T>(request: () => Promise<T>): Promise<T | null> => {
        if (!saving.value) {
            return null;
        }

        status.value = 'saving';

        try {
            const result = await request();
            status.value =
                pendingCells.size || pendingWidths.size ? 'unsaved' : 'saved';

            return result;
        } catch (error) {
            console.error('[table] could not save:', error);
            status.value = 'error';

            return null;
        }
    };

    const flushCells = async () => {
        const cells = [...pendingCells.values()];
        pendingCells.clear();

        await run(() =>
            Promise.all(
                cells.map((cell) =>
                    api
                        .saveCell(
                            activeTable.value,
                            cell.row,
                            cell.column,
                            cell.value,
                        )
                        .then((saved) => applyComputed(saved.row)),
                ),
            ),
        );
    };

    const flushWidths = async () => {
        const widths = [...pendingWidths.entries()];
        pendingWidths.clear();

        await run(() =>
            Promise.all(
                widths.map(([column, width]) =>
                    api.updateColumn(activeTable.value, column, { width }),
                ),
            ),
        );
    };

    const cellsLater = useDebounceFn(flushCells, 800);
    const widthsLater = useDebounceFn(flushWidths, 600);

    /** A cell changed; it is sent once the typing stops. */
    const cell = (row: number, column: string, value: unknown) => {
        if (!saving.value) {
            return;
        }

        pendingCells.set(`${row}:${column}`, { row, column, value });
        status.value = 'unsaved';
        void cellsLater();
    };

    /** A column was dragged wider or narrower; sent once the drag settles. */
    const width = (column: string, value: number) => {
        if (!saving.value) {
            return;
        }

        pendingWidths.set(column, value);
        void widthsLater();
    };

    /**
     * A change the server may refuse with something to say -- a formula it
     * can't work out. The refusal comes back to be shown where it was typed,
     * and leaves the page's status as it was: nothing went wrong in saving.
     */
    const attempt = async <T>(request: () => Promise<T>): Promise<T> => {
        const before = status.value;
        status.value = 'saving';

        try {
            const result = await request();
            status.value =
                pendingCells.size || pendingWidths.size ? 'unsaved' : 'saved';

            return result;
        } catch (error) {
            status.value = before;

            throw error;
        }
    };

    /** Anything waiting is sent now, as the page is left. */
    const flush = async () => {
        await Promise.all([flushCells(), flushWidths()]);
    };

    return {
        status,
        saving,
        cell,
        width,
        flush,
        createRow: () => run(() => api.createRow(activeTable.value)),
        duplicateRow: (row: number) =>
            run(() => api.duplicateRow(activeTable.value, row)),
        deleteRows: (ids: number[]) =>
            run(() => api.deleteRows(activeTable.value, ids)),
        createColumn: (column: Parameters<typeof api.createColumn>[1]) =>
            run(() => api.createColumn(activeTable.value, column)),
        updateColumn: (column: string, changes: ColumnChanges) =>
            run(() => api.updateColumn(activeTable.value, column, changes)),
        deleteColumn: (column: string) =>
            run(() => api.deleteColumn(activeTable.value, column)),
        updateTable: (changes: Parameters<typeof api.updateTable>[1]) =>
            run(() => api.updateTable(activeTable.value, changes)),
        attemptColumn: (
            column: string | null,
            changes: Parameters<typeof api.createColumn>[1],
        ) =>
            attempt(() =>
                column === null
                    ? api.createColumn(activeTable.value, changes)
                    : api.updateColumn(activeTable.value, column, changes),
            ),
        attemptTable: (changes: Parameters<typeof api.updateTable>[1]) =>
            attempt(() => api.updateTable(activeTable.value, changes)),
    };
}
