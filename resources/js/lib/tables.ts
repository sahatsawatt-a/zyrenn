// Saving a table as it is edited. The grid changes itself first and tells the
// server afterwards, so every call here answers with JSON rather than a page.
import { xsrfToken } from '@/lib/utils';
import { update as updateTableRoute } from '@/routes/tables';
import * as columnRoutes from '@/routes/tables/columns';
import * as rowRoutes from '@/routes/tables/rows';
import type { ColumnMeta, RowData, TableDensity } from '@/types';

/** What a column can be told to become, beyond its name, which the server chooses. */
export type ColumnChanges = Partial<
    Pick<
        ColumnMeta,
        | 'label'
        | 'type'
        | 'width'
        | 'hidden'
        | 'options'
        | 'currencySymbol'
        | 'maxRating'
    >
> & { sort_order?: number };

async function send<T>(
    url: string,
    method: 'POST' | 'PATCH' | 'DELETE',
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        const reason = await response.json().catch(() => null);

        throw new Error(
            reason?.message ?? `Saving failed with status ${response.status}`,
        );
    }

    return response.json();
}

export const saveCell = (
    table: string,
    row: number,
    column: string,
    value: unknown,
) =>
    send<{ updated_at: string }>(
        rowRoutes.update.url({ table, row }),
        'PATCH',
        { column, value },
    );

export const createRow = (table: string) =>
    send<{ row: RowData }>(rowRoutes.store.url(table), 'POST');

export const duplicateRow = (table: string, row: number) =>
    send<{ row: RowData }>(rowRoutes.duplicate.url({ table, row }), 'POST');

export const deleteRows = (table: string, ids: number[]) =>
    send<{ deleted: number }>(rowRoutes.destroy.url(table), 'DELETE', {
        ids,
    });

export const createColumn = (
    table: string,
    column: ColumnChanges & Pick<ColumnMeta, 'label' | 'type'>,
) =>
    send<{ column: ColumnMeta }>(columnRoutes.store.url(table), 'POST', column);

export const updateColumn = (
    table: string,
    column: string,
    changes: ColumnChanges,
) =>
    send<{ column: ColumnMeta }>(
        columnRoutes.update.url({ table, column }),
        'PATCH',
        changes,
    );

export const deleteColumn = (table: string, column: string) =>
    send<{ deleted: string }>(
        columnRoutes.destroy.url({ table, column }),
        'DELETE',
    );

export const updateTable = (
    table: string,
    changes: { title?: string; density?: TableDensity },
) =>
    send<{ updated_at: string }>(updateTableRoute.url(table), 'PATCH', changes);
