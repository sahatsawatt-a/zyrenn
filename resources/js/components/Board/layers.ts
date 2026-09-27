// How the stack is grouped: a board is usually a handful of frames with a
// dozen things on each, and one flat list of forty rows says nothing about
// which frame anything belongs to.
import { boundsOf } from './geometry';
import type { Item } from './items';
import { isConnector } from './items';

export type LayerGroup = {
    /** The frame's id, or "board" for whatever no frame holds. */
    key: string;
    frame: Item | null;
    /** Top of the stack first, the way the list reads. */
    items: Item[];
};

/** Whether the middle of an item falls inside a frame. */
const sitsIn = (item: Item, frame: Item) => {
    const box = boundsOf(item);
    const slide = boundsOf(frame);
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;

    return (
        x >= slide.x &&
        x <= slide.x + slide.width &&
        y >= slide.y &&
        y <= slide.y + slide.height
    );
};

/**
 * Which group each item belongs to, by id.
 *
 * A connector has no box of its own -- it is wherever its ends are -- so it is
 * filed with whatever it joins rather than by where its box claims to be.
 */
export const groupKeys = (items: Item[]): Map<string, string> => {
    const frames = items.filter((item) => item.kind === 'frame');
    const keys = new Map<string, string>();

    for (const item of items) {
        if (item.kind === 'frame' || isConnector(item)) {
            continue;
        }

        const home = frames.find((frame) => sitsIn(item, frame));

        keys.set(item.id, home ? home.id : 'board');
    }

    for (const item of items) {
        if (!isConnector(item)) {
            continue;
        }

        keys.set(
            item.id,
            keys.get(item.from?.item ?? '') ??
                keys.get(item.to?.item ?? '') ??
                'board',
        );
    }

    return keys;
};

/**
 * The stack, under the frame each thing sits on, with whatever no frame holds
 * gathered at the end.
 */
export const groupItems = (items: Item[]): LayerGroup[] => {
    const frames = items.filter((item) => item.kind === 'frame');
    const keys = groupKeys(items);
    const byKey = new Map<string, Item[]>([
        ...frames.map((frame): [string, Item[]] => [frame.id, []]),
        ['board', []],
    ]);

    for (const item of items) {
        if (item.kind === 'frame') {
            continue;
        }

        byKey.get(keys.get(item.id) ?? 'board')?.push(item);
    }

    const loose = byKey.get('board') ?? [];

    const groups: LayerGroup[] = frames.map((frame) => ({
        key: frame.id,
        frame,
        // Each group runs top of the stack first, as a flat list would
        items: [...(byKey.get(frame.id) ?? [])].reverse(),
    }));

    if (loose.length || frames.length === 0) {
        groups.push({ key: 'board', frame: null, items: [...loose].reverse() });
    }

    return groups;
};
