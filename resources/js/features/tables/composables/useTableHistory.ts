import { computed } from 'vue';
import {
    columns,
    rows,
    pastStack,
    futureStack,
    selectedRowIds,
} from './useTableState';
import type { TableHistorySnapshot } from './useTableState';

export function useTableHistory() {
    // MATCH: Mirrors the board 'copy' and 'snapshot' methodology safely [8]
    const captureSnapshot = (): TableHistorySnapshot => {
        return {
            columns: JSON.parse(JSON.stringify(columns.value)),
            rows: JSON.parse(JSON.stringify(rows.value)),
        };
    };

    // MATCH: Same transactional history commitment tracking bounds as the board [8]
    const commit = () => {
        pastStack.value.push(captureSnapshot());

        if (pastStack.value.length > 60) {
            pastStack.value.shift();
        }

        futureStack.value.length = 0;
    };

    // MATCH: Purely local reference replacement mapping block [8]
    const undo = () => {
        const previous = pastStack.value.pop();

        if (!previous) {
            return;
        }

        futureStack.value.push(captureSnapshot());
        columns.value = previous.columns;
        rows.value = previous.rows;

        // Sanitizes selection checked items arrays natively [8]
        selectedRowIds.value = selectedRowIds.value.filter((id) =>
            previous.rows.some((row) => row.id === id),
        );
    };

    // MATCH: Purely local redo reference replacement tracking block [8]
    const redo = () => {
        const next = futureStack.value.pop();

        if (!next) {
            return;
        }

        pastStack.value.push(captureSnapshot());
        columns.value = next.columns;
        rows.value = next.rows;
    };

    // A change the server has already made -- a row added or deleted, a column
    // removed -- cannot be taken back by restoring a snapshot, so history forgets
    // everything before it rather than offer an undo that would not stick
    const clear = () => {
        pastStack.value = [];
        futureStack.value = [];
    };

    const canUndo = computed(() => pastStack.value.length > 0);
    const declineRedo = computed(() => futureStack.value.length > 0);

    return {
        commit,
        clear,
        undo,
        redo,
        canUndo,
        canRedo: declineRedo,
    };
}
