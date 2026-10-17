import { boundsOf } from '@/features/boards/model/geometry';
import type { Item } from '@/features/boards/model/items';
import { groupKeys } from '@/features/boards/model/layers';
import { cleanRepeat, rowContents } from '@/features/boards/model/links';
import type { Repeat, Value } from '@/features/prototypes/interactions';

// A prototype's database while it is played: its project's tables, each
// play with a copy of its own (or, saving to the tables, kept in step with
// them), the row of each that is current -- the one tapped or saved last --
// and the row cards drawn once on the board and shown for each row. Worked
// out apart from the page, so it can be tested on its own.

export type Column = { label: string; type: string };

/** A row as Play is given it: its id, and its values by column label. */
export type Row = { id: string; [label: string]: unknown };

export type PlayTable = { title: string; columns: Column[]; rows: Row[] };

/** The tables a prototype uses, by ref_id. */
export type Tables = Record<string, PlayTable>;

/** What the tables are now, in a play. */
export type DataState = {
    tables: Tables;
    // The current row of each table, by its id
    current: Record<string, string>;
    // The table whose row was made current last: {Column} reads it first
    latest: string | null;
};

/** The row an item drawn in a row card is a copy for. */
export type InRow = { table: string; row: string };

/** A play's own copy of the tables, so changing it changes nothing else. */
export const copyOf = (tables: Tables | null | undefined): Tables =>
    Object.fromEntries(
        Object.entries(tables ?? {}).map(([ref, table]) => [
            ref,
            {
                title: table.title,
                columns: table.columns.map((column) => ({ ...column })),
                rows: table.rows.map((row) => ({ ...row, id: String(row.id) })),
            },
        ]),
    );

export const freshData = (tables?: Tables | null): DataState => ({
    tables: copyOf(tables),
    current: {},
    latest: null,
});

// ------------------------------------------------------------------- Words
const same = (a: string, b: string) =>
    a.trim().toLowerCase() === b.trim().toLowerCase();

/** A column's label as the table has it, by a name in any case. */
export const columnNamed = (
    table: PlayTable | undefined,
    name: string,
): string | null =>
    table?.columns.find((column) => same(column.label, name))?.label ?? null;

/** How a value reads in words: a list joined, yes or no, nothing for none. */
export const said = (value: unknown): string => {
    if (value === null || value === undefined) {
        return '';
    }

    if (Array.isArray(value)) {
        return value.map(said).filter(Boolean).join(', ');
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    // A place: by its name
    if (typeof value === 'object') {
        const label = (value as { label?: unknown }).label;

        return typeof label === 'string' ? label : '';
    }

    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
};

const rowOf = (data: DataState, table: string, id: string | undefined) =>
    id === undefined
        ? undefined
        : data.tables[table]?.rows.find((row) => row.id === id);

/** A column of a row, in words; null when the table has no such column. */
const cellOf = (
    data: DataState,
    table: string,
    row: Row | undefined,
    name: string,
) => {
    const label = columnNamed(data.tables[table], name);

    return label && row ? said(row[label]) : null;
};

/**
 * What a {name} says: inside a row card, the row in hand -- a list shows
 * its rows whatever a form has typed under a column's name; a value typed or
 * set; {Table.Column}; the current row of the latest table, then of any
 * other. Null, when nothing has that name.
 */
export const resolve = (
    name: string,
    values: Record<string, Value>,
    data: DataState,
    inRow?: InRow | null,
): string | null => {
    const key = name.trim();

    if (inRow) {
        const cell = cellOf(
            data,
            inRow.table,
            rowOf(data, inRow.table, inRow.row),
            key,
        );

        if (cell !== null) {
            return cell;
        }
    }

    const value = values[key];

    if (typeof value === 'string') {
        return value;
    }

    // {Table.Column}: a table by its title
    const dot = key.indexOf('.');

    if (dot > 0) {
        const ref = Object.keys(data.tables).find((one) =>
            same(data.tables[one].title, key.slice(0, dot)),
        );

        if (ref) {
            const cell = cellOf(
                data,
                ref,
                rowOf(data, ref, data.current[ref]),
                key.slice(dot + 1),
            );

            if (cell !== null) {
                return cell;
            }
        }
    }

    const order = [
        ...(data.latest ? [data.latest] : []),
        ...Object.keys(data.current).filter((ref) => ref !== data.latest),
    ];

    for (const ref of order) {
        const cell = cellOf(
            data,
            ref,
            rowOf(data, ref, data.current[ref]),
            key,
        );

        if (cell !== null) {
            return cell;
        }
    }

    return typeof value === 'boolean' ? '' : null;
};

/** Words with each {name} filled in; one with nothing by that name, nothing. */
export const fill = (
    text: string,
    values: Record<string, Value>,
    data: DataState,
    inRow?: InRow | null,
): string =>
    text.includes('{')
        ? text.replace(
              /\{([^{}\n]{1,64})\}/g,
              (_, name: string) => resolve(name, values, data, inRow) ?? '',
          )
        : text;

// -------------------------------------------------------------------- Rows
/** The rows a repeat shows: those that match, in its order, so many. */
export const rowsFor = (
    repeat: Repeat,
    values: Record<string, Value>,
    data: DataState,
): Row[] => {
    const table = data.tables[repeat.table];

    if (!table) {
        return [];
    }

    const where = (repeat.where ?? []).map((one) => ({
        label: columnNamed(table, one.column),
        is: fill(one.is, values, data),
    }));

    let rows = table.rows.filter((row) =>
        where.every(
            (one) => one.label !== null && same(said(row[one.label]), one.is),
        ),
    );

    const sortBy = repeat.sort ? columnNamed(table, repeat.sort.column) : null;

    if (sortBy) {
        const by = (row: Row) => row[sortBy];
        const order = repeat.sort!.desc ? -1 : 1;

        rows = [...rows].sort((a, b) => {
            const [x, y] = [by(a), by(b)];

            if (typeof x === 'number' && typeof y === 'number') {
                return (x - y) * order;
            }

            return (
                said(x).localeCompare(said(y), undefined, { numeric: true }) *
                order
            );
        });
    }

    return rows.slice(0, Math.min(200, Math.max(1, repeat.limit ?? 50)));
};

/** A copy's id: the card's item, for one row. */
export const copyId = (id: string, row: string) => `${id}::${row}`;

/**
 * The board as it is played: each row card drawn once for each of its rows,
 * a gap apart, and -- going down -- what lies below it on its screen moved
 * down to make room, the screen growing with it. Answers with the items, and
 * for each copy, its row and the item it copies.
 */
export const withRows = (
    items: Item[],
    values: Record<string, Value>,
    data: DataState,
): {
    items: Item[];
    rows: Map<string, InRow>;
    copies: Map<string, string>;
} => {
    const cards = items
        .map((item) => ({ item, repeat: cleanRepeat(item.repeat) }))
        .filter(
            (card): card is { item: Item; repeat: Repeat } =>
                !!card.repeat &&
                !card.item.hidden &&
                card.item.kind !== 'frame',
        );

    if (!cards.length) {
        return { items, rows: new Map(), copies: new Map() };
    }

    const homes = groupKeys(items);
    const rows = new Map<string, InRow>();
    const copies = new Map<string, string>();
    // Where each item is now, as cards above it push it down
    const moved = new Map<string, number>();
    const grown = new Map<string, number>();
    // What each card's copies are, put in where the card was
    const drawnFor = new Map<string, Item[]>();
    const inCards = new Set<string>();

    // A card inside another's row is drawn once, as a plain item
    for (const { item } of cards) {
        for (const inner of rowContents(item, items)) {
            inCards.add(inner.id);
        }
    }

    // From the top of each screen down, so each card is pushed by those above
    const ordered = cards
        .filter(({ item }) => !inCards.has(item.id))
        .sort((a, b) => boundsOf(a.item).y - boundsOf(b.item).y);

    for (const { item: card, repeat } of ordered) {
        const contents = rowContents(card, items);
        const shown = rowsFor(repeat, values, data);
        const box = boundsOf(card);
        const gap = repeat.gap ?? 0;
        const step = repeat.across ? box.width + gap : box.height + gap;
        const shift = moved.get(card.id) ?? 0;
        const drawn: Item[] = [];

        shown.forEach((row, at) => {
            for (const one of [card, ...contents]) {
                const id = copyId(one.id, row.id);

                rows.set(id, { table: repeat.table, row: row.id });
                copies.set(id, one.id);
                drawn.push({
                    ...one,
                    id,
                    x: one.x + (repeat.across ? at * step : 0),
                    y: one.y + shift + (repeat.across ? 0 : at * step),
                    // Drawn for its row: the card's repeat is done
                    repeat: null,
                });
            }
        });

        drawnFor.set(card.id, drawn);

        for (const one of contents) {
            drawnFor.set(one.id, []);
        }

        if (repeat.across) {
            continue;
        }

        // What lies below the card on its screen makes room for its rows
        const extra = (shown.length - 1) * step;
        const home = homes.get(card.id);
        const inside = new Set(contents.map((one) => one.id));

        for (const other of items) {
            if (
                other.id === card.id ||
                inside.has(other.id) ||
                other.fixed === 'top' ||
                homes.get(other.id) !== home
            ) {
                continue;
            }

            // Fixed to the foot: it goes down as far as the screen grows, so
            // it stays at the foot (Play keeps it there while the screen scrolls)
            if (other.fixed === 'bottom') {
                if (extra > 0) {
                    moved.set(other.id, (moved.get(other.id) ?? 0) + extra);
                }

                continue;
            }

            if (boundsOf(other).y >= box.y + box.height - 0.5) {
                moved.set(other.id, (moved.get(other.id) ?? 0) + extra);
            }
        }

        if (home && extra > 0) {
            grown.set(home, (grown.get(home) ?? 0) + extra);
        }
    }

    const out: Item[] = [];

    for (const item of items) {
        const drawn = drawnFor.get(item.id);

        if (drawn) {
            out.push(...drawn);
            continue;
        }

        const by = moved.get(item.id) ?? 0;
        const more = grown.get(item.id) ?? 0;

        out.push(
            by || more
                ? { ...item, y: item.y + by, height: item.height + more }
                : item,
        );
    }

    return { items: out, rows, copies };
};

// ------------------------------------------------------------------ Saving
/** A change Play made to a table, for the page to keep in the table too. */
export type Write =
    | { kind: 'save'; table: string; row: Row; isNew: boolean }
    | { kind: 'remove'; table: string; row: string };

let made = 0;

/** An id for a row Play made, until the table gives it its own. */
export const madeId = () => `new-${++made}`;

/**
 * A row saved: added, or the current one changed -- each column from what
 * `values` says for it, or else from the field named like it, among the
 * fields `reach` names (those on the screen it is saved from: a form never
 * changes a column it does not show). The row saved is current after.
 */
export const saveRow = (
    data: DataState,
    table: string,
    row: 'new' | 'current' | undefined,
    given: Record<string, string> | undefined,
    values: Record<string, Value>,
    reach?: Set<string>,
): { data: DataState; write: Write | null } => {
    const kept = data.tables[table];

    if (!kept) {
        return { data, write: null };
    }

    const cells: Record<string, unknown> = {};

    // Worked out by the table itself: nothing to save into
    for (const column of kept.columns.filter((one) => one.type !== 'formula')) {
        const say = Object.entries(given ?? {}).find(([name]) =>
            same(name, column.label),
        )?.[1];

        if (say !== undefined) {
            cells[column.label] = fill(say, values, data);
            continue;
        }

        const typed = Object.entries(values).find(
            ([name]) => (!reach || reach.has(name)) && same(name, column.label),
        )?.[1];

        if (typeof typed === 'string') {
            cells[column.label] = typed;
        }
    }

    const currentId = data.current[table];
    const changing =
        row === 'current' && currentId !== undefined
            ? kept.rows.find((one) => one.id === currentId)
            : undefined;

    const saved: Row = changing
        ? { ...changing, ...cells }
        : { ...cells, id: madeId() };

    const rows = changing
        ? kept.rows.map((one) => (one.id === saved.id ? saved : one))
        : [...kept.rows, saved];

    return {
        data: {
            tables: { ...data.tables, [table]: { ...kept, rows } },
            current: { ...data.current, [table]: saved.id },
            latest: table,
        },
        write: { kind: 'save', table, row: saved, isNew: !changing },
    };
};

/** The current row deleted: none is current after. */
export const removeRow = (
    data: DataState,
    table: string,
): { data: DataState; write: Write | null } => {
    const kept = data.tables[table];
    const id = data.current[table];

    if (!kept || id === undefined) {
        return { data, write: null };
    }

    const current = { ...data.current };
    delete current[table];

    return {
        data: {
            tables: {
                ...data.tables,
                [table]: {
                    ...kept,
                    rows: kept.rows.filter((one) => one.id !== id),
                },
            },
            current,
            latest: data.latest === table ? null : data.latest,
        },
        write: id.startsWith('new-')
            ? null
            : { kind: 'remove', table, row: id },
    };
};

/** A row made current: tapped in its card. */
export const pick = (data: DataState, at: InRow): DataState => ({
    ...data,
    current: { ...data.current, [at.table]: at.row },
    latest: at.table,
});

/** A row the table gave back for one Play made or changed, in its place. */
export const settle = (
    data: DataState,
    table: string,
    was: string,
    row: Row,
): DataState => {
    const kept = data.tables[table];

    if (!kept) {
        return data;
    }

    const now = { ...row, id: String(row.id) };

    return {
        ...data,
        tables: {
            ...data.tables,
            [table]: {
                ...kept,
                rows: kept.rows.map((one) => (one.id === was ? now : one)),
            },
        },
        current:
            data.current[table] === was
                ? { ...data.current, [table]: now.id }
                : data.current,
    };
};
