// Shapes with a structure inside them: a table's columns, a class's fields
// and methods, the titled band along the top or side of a swimlane. The
// structure is kept as data -- a list of rows, each with its own parts -- so
// it is edited a part at a time (RowsEditor), a connector can hold on to one
// row, and a schema can be written out as SQL. The server keeps the same
// (App\Support\Board\BoardRows).
import type { Item, Side } from './items';
import { shapeOf } from './shapes';

/** One line of a table or a class. */
export type Row = {
    id: string;
    name: string;
    // What it holds: "int", "varchar(255)", "Shape[]"
    type: string;
    // A column's key ("pk", "fk", "uk"), or a member's visibility
    // ("+", "-", "#", "~"); empty for neither
    mark: string;
    // A column that may be left empty
    nullable: boolean;
    // A class's operation, listed under its fields
    method: boolean;
};

export type RowsKind = 'table' | 'class';

type Shaped = Pick<Item, 'kind'>;
type Structured = Pick<
    Item,
    'kind' | 'x' | 'y' | 'width' | 'height' | 'fontSize' | 'text'
> & { rows?: Row[]; variant?: string };

/** Which kind of rows a shape holds, if it holds any. */
export const rowsKind = (item: Shaped): RowsKind | undefined =>
    shapeOf(item.kind)?.rows;

export const hasRows = (item: Shaped): boolean => !!rowsKind(item);

/** Whether the shape has a titled band: a table, a class, a lane. */
export const hasHeader = (item: Shaped): boolean =>
    !!shapeOf(item.kind)?.header;

/** Which edge the band runs along: a lane's can be turned to its side. */
export const headerSide = (item: Shaped & { variant?: string }) =>
    hasHeader(item) && item.variant === 'left' && !hasRows(item)
        ? ('left' as const)
        : ('top' as const);

export const KEYS = [
    { value: '', label: 'No key', short: '' },
    { value: 'pk', label: 'Primary key', short: 'PK' },
    { value: 'fk', label: 'Foreign key', short: 'FK' },
    { value: 'uk', label: 'Unique', short: 'UK' },
] as const;

export const VISIBILITIES = [
    { value: '+', label: 'Public' },
    { value: '-', label: 'Private' },
    { value: '#', label: 'Protected' },
    { value: '~', label: 'Package' },
] as const;

/** What a mark is drawn as: a key's two letters, a visibility's sign. */
export const markShown = (kind: RowsKind, mark: string): string =>
    kind === 'table'
        ? (KEYS.find((key) => key.value === mark)?.short ?? '')
        : mark;

export const newRowId = (): string =>
    `r${Math.random().toString(36).slice(2, 8)}`;

/** A row made whole: whatever was left out of it is the plainest answer. */
export const makeRow = (kind: RowsKind, from: Partial<Row> = {}): Row => ({
    id: from.id || newRowId(),
    name: from.name ?? '',
    type: from.type ?? '',
    mark: from.mark ?? (kind === 'class' ? '+' : ''),
    nullable: from.nullable ?? false,
    method: kind === 'class' && (from.method ?? false),
});

// ------------------------------------------------------------------ Layout

/** Room left under the last row, and either side of a class's second rule. */
const FOOT = 6;
const SECTION = 8;

/** How deep the titled band is: a line of the title, or as many as it has. */
export const headerDepth = (item: Structured): number => {
    const lines = Math.max(1, item.text.split('\n').length);

    return Math.round(Math.max(30, (item.fontSize + 1) * 1.3 * lines + 14));
};

export const rowDepth = (item: Structured): number =>
    Math.round(item.fontSize * 1.8);

/** A class's fields come first and its methods under a rule; a table's rows are as listed. */
const ordered = (item: Structured): Row[] => {
    const rows = item.rows ?? [];

    return rowsKind(item) === 'class'
        ? [
              ...rows.filter((row) => !row.method),
              ...rows.filter((row) => row.method),
          ]
        : rows;
};

/** Where the rule between a class's fields and its methods is drawn. */
export const sectionRule = (item: Structured): number | null => {
    if (rowsKind(item) !== 'class') {
        return null;
    }

    const fields = (item.rows ?? []).filter((row) => !row.method).length;

    return headerDepth(item) + fields * rowDepth(item) + SECTION / 2 + 2;
};

/** Each row and where it is drawn, down from the item's top. */
export const laidRows = (
    item: Structured,
): { row: Row; y: number; height: number }[] => {
    const depth = rowDepth(item);
    const top = headerDepth(item) + 2;
    let methods = 0;

    return ordered(item).map((row, index) => {
        // Methods start under the rule
        if (row.method && rowsKind(item) === 'class') {
            methods = SECTION;
        }

        return { row, y: top + index * depth + methods, height: depth };
    });
};

/** How tall the shape has to be to show its title and every row. */
export const rowsHeight = (item: Structured): number => {
    const rows = (item.rows ?? []).length;
    const rule = rowsKind(item) === 'class' ? SECTION : 0;
    // A class with no methods still shows the room they would go in
    const empty =
        rowsKind(item) === 'class' &&
        !(item.rows ?? []).some((row) => row.method)
            ? rowDepth(item) * 0.6
            : 0;

    return Math.round(
        headerDepth(item) + 2 + rows * rowDepth(item) + rule + empty + FOOT,
    );
};

/** A row by what a connector's end calls it: its id, or failing that its name. */
export const rowNamed = (item: Structured, pin: string): Row | undefined => {
    const rows = item.rows ?? [];
    const wanted = pin.trim().toLowerCase();

    return (
        rows.find((row) => row.id === pin) ??
        rows.find((row) => row.name.trim().toLowerCase() === wanted)
    );
};

/** Where a connector holds on to a row: at the row's height, on either edge. */
export const rowAnchor = (
    item: Structured,
    row: Row,
    side: 'left' | 'right',
) => {
    const placed = laidRows(item).find((each) => each.row.id === row.id);
    const y =
        item.y + (placed ? placed.y + placed.height / 2 : item.height / 2);

    return {
        pin: row.id,
        side: side as Side,
        x: side === 'left' ? item.x : item.x + item.width,
        // Never off the bottom of a shape drawn shorter than its rows
        y: Math.min(y, item.y + item.height),
    };
};

/** Every place a connector can hold on to a row: both edges of each. */
export const rowAnchors = (item: Structured) =>
    hasRows(item)
        ? (item.rows ?? []).flatMap((row) => [
              rowAnchor(item, row, 'left'),
              rowAnchor(item, row, 'right'),
          ])
        : [];

// ------------------------------------------------------------------ As text

/**
 * Rows as lines of text, to write many at once: a column is "name type" with
 * "pk", "fk" or "uk" after it and a "?" on a type that may be empty; a
 * member is its sign, its name and ": type", and is a method when it has
 * brackets.
 */
export const rowsToText = (kind: RowsKind, rows: Row[]): string =>
    rows
        .map((row) =>
            kind === 'table'
                ? [
                      row.name,
                      row.type ? `${row.type}${row.nullable ? '?' : ''}` : '',
                      row.mark,
                  ]
                      .filter(Boolean)
                      .join(' ')
                : `${row.mark ? `${row.mark} ` : ''}${row.name}${row.type ? `: ${row.type}` : ''}`,
        )
        .join('\n');

/**
 * The rows a text says, keeping the id of any row already there under the
 * same name -- so a connector on a column stays on it when the list is
 * rewritten.
 */
export const rowsFromText = (
    kind: RowsKind,
    text: string,
    before: Row[] = [],
): Row[] => {
    const kept = new Map(
        before.map((row) => [row.name.trim().toLowerCase(), row.id]),
    );
    const used = new Set<string>();

    return text
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => {
            let read: Partial<Row>;

            if (kind === 'table') {
                const words = line.split(/\s+/);
                const marks = words.filter(
                    (word, index) => index > 0 && /^(pk|fk|uk)$/i.test(word),
                );
                const rest = words.filter(
                    (word, index) => index === 0 || !/^(pk|fk|uk)$/i.test(word),
                );
                const type = rest.slice(1).join(' ');

                read = {
                    name: rest[0],
                    type: type.replace(/\?$/, ''),
                    nullable: type.endsWith('?'),
                    mark: (marks[0] ?? '').toLowerCase(),
                };
            } else {
                const sign = line.match(/^([+\-#~])\s*/);
                const body = sign ? line.slice(sign[0].length) : line;
                // The type is what follows the last colon outside brackets
                const split = body.match(/^(.*?\)|[^:(]*)\s*(?::\s*(.*))?$/);
                const name = (split?.[1] ?? body).trim();

                read = {
                    name,
                    type: (split?.[2] ?? '').trim(),
                    mark: sign ? sign[1] : '',
                    method: /\(.*\)/.test(name),
                };
            }

            const known = kept.get((read.name ?? '').trim().toLowerCase());
            const id = known && !used.has(known) ? known : newRowId();

            used.add(id);

            return makeRow(kind, { ...read, id });
        });
};
