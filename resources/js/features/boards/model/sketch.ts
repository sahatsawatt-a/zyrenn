// The hand-drawn look: which items can be drawn as by hand, and what putting
// the whole look on -- wobbly lines and a handwritten font -- changes about
// each of them. How the lines are drawn is drawing/sketch.ts.
import { groupKeys } from './layers';
import type { FontFamily, Item, ItemKind } from './items';
import { HAND_FONTS, POLYGONS, hasText, isPath } from './items';

/** Kinds drawn from lines that can wobble; a picture, a part or ink cannot. */
const SKETCHED: ItemKind[] = [
    'frame',
    'sticky',
    'rect',
    'pill',
    'ellipse',
    'star',
    'path',
    'arrow',
    ...POLYGONS,
];

export const isSketchable = (item: Pick<Item, 'kind'>): boolean =>
    SKETCHED.includes(item.kind) || isPath(item.kind);

/** Whether it is drawn as by hand just now. */
export const isSketched = (item: Item): boolean =>
    !!item.sketch && isSketchable(item);

/** The font a label goes back to when the look comes off: the board's own. */
const CRISP_FONT: FontFamily = 'sans';

/**
 * What the sketch look changes about these items -- or, with none named,
 * every item on the board: lines drawn as by hand (as rough as each already
 * is, else a little) and words in the handwritten font; or, taken off, crisp
 * lines and a written-by-hand font back to the plain one. A frame named takes
 * everything on it along, so a slide is sketched in one go.
 */
export const sketchLook = (
    items: Item[],
    chosen: Item[] | null,
    on: boolean,
): Map<string, Partial<Item>> => {
    let targets = chosen ?? items;

    const frames = new Set(
        targets.filter((item) => item.kind === 'frame').map((item) => item.id),
    );

    if (chosen && frames.size) {
        const homes = groupKeys(items);
        const picked = new Set(chosen.map((item) => item.id));

        targets = items.filter(
            (item) =>
                picked.has(item.id) || frames.has(homes.get(item.id) ?? ''),
        );
    }

    const changes = new Map<string, Partial<Item>>();

    for (const item of targets) {
        const change: Partial<Item> = {};

        if (isSketchable(item) && on !== isSketched(item)) {
            change.sketch = on ? 1 : 0;
        }

        // A connector's label, and a frame's title, are words too
        const worded =
            hasText(item) || item.kind === 'arrow' || item.kind === 'frame';

        if (worded && on && !HAND_FONTS.includes(item.fontFamily)) {
            change.fontFamily = 'hand';
        }

        if (worded && !on && HAND_FONTS.includes(item.fontFamily)) {
            change.fontFamily = CRISP_FONT;
        }

        if (Object.keys(change).length) {
            changes.set(item.id, change);
        }
    }

    return changes;
};
