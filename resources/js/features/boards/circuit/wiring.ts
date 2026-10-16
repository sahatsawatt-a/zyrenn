// Wires met part-way along: where a point lands on one, so a new wire can be
// joined to it with a dot.
import type { Item } from '@/features/boards/model/items';
import { GRID } from './symbols';

/**
 * The spot on one of these wires nearest a point, if the point is within
 * reach of one. It is held to the grid along the wire, and kept off the
 * wire's own ends, where there is a pin to join to instead.
 */
export const spotOnWire = (
    wires: Item[],
    pathOf: (item: Item) => number[],
    point: { x: number; y: number },
    reach: number,
): { wire: Item; x: number; y: number } | null => {
    let best: { wire: Item; x: number; y: number } | null = null;
    let nearest = reach;

    for (const wire of wires) {
        const path = pathOf(wire);

        for (let index = 0; index + 3 < path.length; index += 2) {
            const [x1, y1, x2, y2] = path.slice(index, index + 4);
            const length = Math.hypot(x2 - x1, y2 - y1);

            // Too short to hold a dot clear of both its ends
            if (length < GRID * 2) {
                continue;
            }

            const along =
                ((point.x - x1) * (x2 - x1) + (point.y - y1) * (y2 - y1)) /
                length;
            const held = Math.min(
                length - GRID,
                Math.max(GRID, Math.round(along / GRID) * GRID),
            );
            const x = x1 + ((x2 - x1) * held) / length;
            const y = y1 + ((y2 - y1) * held) / length;
            const away = Math.hypot(point.x - x, point.y - y);

            if (away <= nearest) {
                nearest = away;
                best = { wire, x, y };
            }
        }
    }

    return best;
};
