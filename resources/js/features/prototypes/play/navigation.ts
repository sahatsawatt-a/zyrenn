import { boundsOf } from '@/features/boards/model/geometry';
import type { Item } from '@/features/boards/model/items';
import { groupKeys } from '@/features/boards/model/layers';
import { fieldOf, isLive } from './engine';

// Going through a prototype in Play: the screens in order, the one Play
// opens on, the one in the address, and the parts of a screen that answer a
// touch. Kept apart from the page so it can be tested on its own.

/** A screen of the prototype: one of its frames, in the order Play gives. */
export type Screen = { id: string; title: string };

/**
 * A part of a screen Play lays something over: a button for what answers a
 * touch, or an input for a field. Where it is, in board units from the
 * screen's top left -- or, for one fixed to the top or bottom, from that edge.
 */
export type Part = {
    item: Item;
    kind: 'live' | 'field';
    fixed: 'top' | 'bottom' | null;
    x: number;
    y: number;
    width: number;
    height: number;
};

/** The screen Play opens on: the one asked for, if there is one, else the first. */
export const startOf = (
    screens: Screen[],
    start: string | null | undefined,
): string | null =>
    screens.find((screen) => screen.id === start)?.id ?? screens[0]?.id ?? null;

/** The screen before or after one, in the prototype's order; none at either end. */
export const screenBeside = (
    screens: Screen[],
    id: string,
    by: 1 | -1,
): string | null => {
    const at = screens.findIndex((screen) => screen.id === id);

    return at < 0 ? null : (screens[at + by]?.id ?? null);
};

/** The screen an address names after its #, when the prototype has it. */
export const screenInHash = (
    hash: string,
    screens: Screen[],
): string | null => {
    const id = decodeURIComponent(hash.replace(/^#/, ''));

    return screens.some((screen) => screen.id === id) ? id : null;
};

/** Which screen each item is on: the frame that holds it (the board's rule). */
export const screensOf = (items: Item[]): Map<string, string> =>
    groupKeys(items);

/**
 * The parts of a screen to lay something over, bottom first as they are
 * drawn: what answers a touch, and the fields typed into. Hidden ones are
 * left out -- `items` are as played now.
 */
export const partsOn = (
    items: Item[],
    screenId: string,
    homes: Map<string, string> = screensOf(items),
): Part[] => {
    const screen = items.find((item) => item.id === screenId);

    if (!screen) {
        return [];
    }

    const parts: Part[] = [];

    for (const item of items) {
        if (
            item.hidden ||
            item.id === screenId ||
            homes.get(item.id) !== screenId
        ) {
            continue;
        }

        const kind = fieldOf(item) ? 'field' : isLive(item) ? 'live' : null;

        if (!kind) {
            continue;
        }

        const box = boundsOf(item);
        const fixed =
            item.fixed === 'top' || item.fixed === 'bottom' ? item.fixed : null;

        parts.push({
            item,
            kind,
            fixed,
            x: box.x - screen.x,
            y:
                fixed === 'bottom'
                    ? screen.y + screen.height - (box.y + box.height)
                    : box.y - screen.y,
            width: box.width,
            height: box.height,
        });
    }

    return parts;
};
