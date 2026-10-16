// A schema drawn on a board -- tables with their columns, lines from each
// foreign key to the column it points at -- read back as text: a Mermaid
// erDiagram, and through the note's own writer (notes/lib/erToSql) as SQL.
// Nothing is kept: it is read from the drawing each time.
import { erDiagramToSql } from '@/features/notes/lib/erToSql';
import type { SqlDialect } from '@/features/notes/lib/erToSql';
import type { HeadType, Item } from './items';
import { isConnector } from './items';
import { rowNamed, rowsKind } from './rows';

export const isTable = (item: Item): boolean => rowsKind(item) === 'table';

/** A name Mermaid and SQL both take: letters, digits and underscores. */
const named = (text: string, fallback: string) =>
    text
        .trim()
        .split('\n')[0]
        .replace(/[^A-Za-z0-9_]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^(\d)/, '_$1') || fallback;

/** A type as one word: "decimal(10, 2)" keeps its first number. */
const typed = (text: string) =>
    text
        .trim()
        .replace(/\s*,[^)]*/, '')
        .replace(/\s+/g, '_')
        .replace(/[^\w\-[\]()]/g, '')
        .replace(/^([^A-Za-z_])/, '_$1') || 'string';

// How many of a table are at an end of a line, as Mermaid writes it on the
// left and on the right of "--"
const CARDS: Partial<Record<HeadType, [string, string]>> = {
    one_only: ['||', '||'],
    bar: ['||', '||'],
    zero_one: ['|o', 'o|'],
    one_many: ['}|', '|{'],
    zero_many: ['}o', 'o{'],
    many: ['}o', 'o{'],
};

/**
 * The board's tables as a Mermaid erDiagram. A column says what it points
 * at where a line runs from it to a column of another table.
 */
export const schemaAsMermaid = (items: Item[]): string => {
    const tables = items.filter(isTable);
    const names = new Map<string, string>();
    const taken = new Set<string>();

    for (const [index, table] of tables.entries()) {
        let name = named(table.text, `table_${index + 1}`);

        for (let n = 2; taken.has(name.toLowerCase()); n++) {
            name = `${named(table.text, `table_${index + 1}`)}_${n}`;
        }

        taken.add(name.toLowerCase());
        names.set(table.id, name);
    }

    const byId = new Map(tables.map((table) => [table.id, table]));
    // What each foreign key points at, by "table id:row id"
    const points = new Map<string, string>();
    const relations: string[] = [];

    for (const line of items.filter(isConnector)) {
        const from = line.from?.item ? byId.get(line.from.item) : undefined;
        const to = line.to?.item ? byId.get(line.to.item) : undefined;

        if (!from || !to || from === to) {
            continue;
        }

        const fromRow = line.from?.pin
            ? rowNamed(from, line.from.pin)
            : undefined;
        const toRow = line.to?.pin ? rowNamed(to, line.to.pin) : undefined;

        // The end on a foreign key is the one that points
        for (const [row, table, other, far] of [
            [fromRow, from, toRow, to],
            [toRow, to, fromRow, from],
        ] as const) {
            if (row?.mark === 'fk' && other && other.mark !== 'fk') {
                points.set(
                    `${table.id}:${row.id}`,
                    `references ${names.get(far.id)}(${named(other.name, 'id')})`,
                );
            }
        }

        // A line from a foreign key says which table has the many, whatever
        // its caps were set to: the key's end, and the other has the one.
        // Otherwise the caps say, and unsaid both ends are a "one"
        const keyed =
            fromRow?.mark === 'fk' && toRow?.mark !== 'fk'
                ? 'from'
                : toRow?.mark === 'fk' && fromRow?.mark !== 'fk'
                  ? 'to'
                  : null;
        const card = (head: HeadType, end: 'from' | 'to', side: 0 | 1) => {
            const many = ['many', 'one_many', 'zero_many'].includes(head);

            if (keyed === end) {
                return (many ? CARDS[head]! : CARDS.zero_many!)[side];
            }

            if (keyed) {
                return (
                    many ? CARDS.one_only! : (CARDS[head] ?? CARDS.one_only!)
                )[side];
            }

            return (CARDS[head] ?? CARDS.one_only!)[side];
        };

        relations.push(
            `  ${names.get(from.id)} ${card(line.startHead, 'from', 0)}--${card(line.endHead, 'to', 1)} ${names.get(to.id)} : "${line.text.replace(/"/g, '')}"`,
        );
    }

    const blocks = tables.map((table) => {
        const columns = (table.rows ?? [])
            .filter((row) => row.name.trim())
            .map((row) => {
                const notes = [
                    row.mark === 'pk'
                        ? ''
                        : row.nullable
                          ? 'nullable'
                          : 'not null',
                    points.get(`${table.id}:${row.id}`) ?? '',
                ].filter(Boolean);

                return `    ${typed(row.type)} ${named(row.name, 'column')}${row.mark ? ` ${row.mark.toUpperCase()}` : ''}${notes.length ? ` "${notes.join(', ')}"` : ''}`;
            });

        return `  ${names.get(table.id)} {\n${columns.join('\n')}\n  }`;
    });

    return ['erDiagram', ...blocks, ...relations].join('\n');
};

/** The board's tables as CREATE TABLE statements, in the dialect asked for. */
export const schemaAsSql = (items: Item[], dialect: SqlDialect): string =>
    erDiagramToSql(schemaAsMermaid(items), dialect).replace(
        /^-- Generated from a Mermaid erDiagram/,
        '-- Generated from a board',
    );
