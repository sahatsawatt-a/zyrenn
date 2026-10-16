// Shapes of one's own: what was drawn on a board -- a vector drawing, a group
// of shapes and words -- kept to put on any board as one group. The server
// keeps each set at the top left of its own box (App\Support\Board\BoardShapes)
// and does the same sums when MCP places one.
import type { Item } from './items';
import { hydrate, isConnector, newId } from './items';

export type MyShape = {
    ref_id: string;
    name: string;
    items: Item[];
    width: number;
    height: number;
    // A small picture of it, for the library
    preview: string | null;
    // Kept for the project, rather than for whoever made it
    shared: boolean;
    can_change: boolean;
};

/**
 * What a shape is made of when the selection is saved: what is picked, and
 * any line that runs only between picked things, though it was not picked.
 */
export const savedOf = (picked: Item[], all: Item[]): Item[] => {
    const ids = new Set(picked.map((item) => item.id));
    const lines = all.filter(
        (item) =>
            isConnector(item) &&
            !ids.has(item.id) &&
            !!item.from?.item &&
            !!item.to?.item &&
            ids.has(item.from.item) &&
            ids.has(item.to.item),
    );

    return [...picked, ...lines];
};

/**
 * A shape's items, made new for a board: each with an id nothing on it has,
 * every line joined to the new copies of what it joined, and all of them one
 * new group. Where they stand is the shape's own box; the board moves them.
 */
export const itemsOfShape = (shape: MyShape): Item[] => {
    const ids = new Map(shape.items.map((item) => [item.id, newId()]));
    const group = `g${newId()}`;
    const end = (point: Item['from']) =>
        point
            ? {
                  ...point,
                  item: point.item ? (ids.get(point.item) ?? null) : null,
              }
            : null;

    return shape.items.map((item) =>
        hydrate({
            ...item,
            id: ids.get(item.id) ?? newId(),
            group,
            ...(isConnector(item)
                ? { from: end(item.from), to: end(item.to) }
                : {}),
        }),
    );
};
