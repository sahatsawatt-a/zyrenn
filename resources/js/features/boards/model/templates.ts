// Diagrams a board can be started from: a flowchart, an org chart, a data
// model. One list (lib/templates.json) holds each as an MCP client would
// write it -- a kind, words, a box, and for a connector the ids it joins --
// and is read here and by the server (App\Support\Board\Templates), so a
// template is the same diagram wherever it is asked for.
import library from '@/features/boards/lib/templates.json';
import type { Endpoint, Item, Side } from './items';
import { hydrate, newId } from './items';
import { relinked } from './links';
import { makeRow, rowsHeight, rowsKind } from './rows';

/**
 * One end of a connector as a template writes it: on an item -- on one of
 * its rows, or part way along it -- or at a point.
 */
type EndSpec = {
    item?: string;
    side?: string;
    pin?: string;
    along?: number;
    x?: number;
    y?: number;
};

/** A row as a client writes it: a column's key, a member's visibility. */
type RowSpec = {
    id?: string;
    name: string;
    type?: string;
    key?: string;
    visibility?: string;
    nullable?: boolean;
    method?: boolean;
};

type ItemSpec = Partial<Omit<Item, 'from' | 'to' | 'rows'>> & {
    kind: Item['kind'];
    from?: EndSpec;
    to?: EndSpec;
    rows?: RowSpec[];
};

export type BoardTemplate = {
    id: string;
    label: string;
    about: string;
    items: ItemSpec[];
};

export const BOARD_TEMPLATES = library.templates as BoardTemplate[];

/**
 * A template's items, made new: each with an id nothing on the board has,
 * every connector joined to the new copies of what it joined. Called again,
 * it makes them all again, so a template can go on a board twice.
 */
export const itemsOfTemplate = (template: BoardTemplate): Item[] => {
    const ids = new Map<string, string>();

    for (const spec of template.items) {
        if (spec.id) {
            ids.set(spec.id, newId());
        }
    }

    const boxes = new Map(
        template.items
            .filter((spec) => spec.id && spec.kind !== 'arrow')
            .map((spec) => [spec.id!, spec]),
    );

    /** An end on the copy of what it names, or at the point it gives. */
    const endOf = (end?: EndSpec): Endpoint | null => {
        if (!end) {
            return null;
        }

        const host = end.item ? boxes.get(end.item) : undefined;

        if (!host) {
            return { item: null, side: null, x: end.x ?? 0, y: end.y ?? 0 };
        }

        // Its place is worked out from the shape as it is drawn; the middle
        // of the shape is somewhere to be until then
        return {
            item: ids.get(end.item!)!,
            side: (end.side as Side | undefined) ?? null,
            // A row is named by its own name, which is how the rows below
            // are found; how far along a lifeline is kept as it is written
            ...(end.pin ? { pin: end.pin } : {}),
            ...(typeof end.along === 'number' ? { along: end.along } : {}),
            x: (host.x ?? 0) + (host.width ?? 0) / 2,
            y: (host.y ?? 0) + (host.height ?? 0) / 2,
        };
    };

    return template.items.map((spec) => {
        const { from, to, rows, ...rest } = spec;
        const kind = rowsKind(spec);

        const made = hydrate({
            // A link to one of the template's screens goes to its copy
            ...relinked(rest, ids),
            id: (spec.id && ids.get(spec.id)) || newId(),
            ...(spec.kind === 'arrow'
                ? { from: endOf(from), to: endOf(to) }
                : {}),
            // A table's columns, a class's members: each a row of its own
            ...(kind && rows
                ? {
                      rows: rows.map((row) =>
                          makeRow(kind, {
                              name: row.name,
                              type: row.type,
                              mark: row.key ?? row.visibility,
                              nullable: row.nullable,
                              method: row.method,
                          }),
                      ),
                  }
                : {}),
        });

        // ...and the shape as tall as they need, unless it says how tall
        if (kind && rows && spec.height === undefined) {
            made.height = rowsHeight(made);
        }

        return made;
    });
};
