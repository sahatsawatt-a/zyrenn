import type { InjectionKey, Ref } from 'vue';
import { heaviest } from './ink';
import type { Item } from './items';

// The eraser: which pencil strokes a sweep of it touches. Only freehand ink
// ("draw") is ever erased -- shapes, text, pictures, frames, connectors and
// vector-pen paths are taken away by selecting them.

type Point = { x: number; y: number };

/** The pencil strokes the eraser is passing over, for each to show it is going. */
export const ERASING: InjectionKey<Ref<Set<string>>> = Symbol('erasing');

/** How far a point is from the segment a-b. */
const fromSegment = (p: Point, a: Point, b: Point) => {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const length = dx * dx + dy * dy;
    const t =
        length === 0
            ? 0
            : Math.max(
                  0,
                  Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / length),
              );

    return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
};

/** A board point in a stroke's own frame: its points start at its x, y, turned by its rotation. */
const inStroke = (item: Item, p: Point): Point => {
    const dx = p.x - item.x;
    const dy = p.y - item.y;
    const turn = ((item.rotation ?? 0) * Math.PI) / 180;
    const cos = Math.cos(turn);
    const sin = Math.sin(turn);

    return { x: dx * cos + dy * sin, y: -dx * sin + dy * cos };
};

/** Whether a stroke passes within `reach` of point `p`. */
const touches = (item: Item, p: Point, near: number) => {
    const at = inStroke(item, p);
    // Heavy ink is reached sooner: from its edge, not its middle
    const reach = near + heaviest(item) / 2;
    const points = item.points ?? [];

    if (points.length === 2) {
        return Math.hypot(at.x - points[0], at.y - points[1]) <= reach;
    }

    for (let i = 0; i + 3 < points.length; i += 2) {
        const a = { x: points[i], y: points[i + 1] };
        const b = { x: points[i + 2], y: points[i + 3] };

        if (fromSegment(at, a, b) <= reach) {
            return true;
        }
    }

    return false;
};

/** Whether an item is one the eraser takes: pencil ink, shown and not locked. */
export const erasable = (item: Item) =>
    item.kind === 'draw' && !item.hidden && !item.locked;

/**
 * The ids of the pencil strokes a sweep of the eraser from `from` to `to`
 * touches, `reach` round it (board units): looked for every half-reach along
 * the way, so a quick sweep misses nothing in between.
 */
export function strokesTouched(
    items: Item[],
    from: Point,
    to: Point,
    reach: number,
): string[] {
    const steps = Math.max(
        1,
        Math.ceil(Math.hypot(to.x - from.x, to.y - from.y) / (reach / 2)),
    );
    const along = Array.from({ length: steps + 1 }, (_, n) => ({
        x: from.x + ((to.x - from.x) * n) / steps,
        y: from.y + ((to.y - from.y) * n) / steps,
    }));

    return items
        .filter(
            (item) =>
                erasable(item) &&
                along.some((point) => touches(item, point, reach)),
        )
        .map((item) => item.id);
}
