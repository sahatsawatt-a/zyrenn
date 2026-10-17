import type { Item } from '@/features/boards/model/items';
import { actionOf, screenOf, whenOf } from '@/features/boards/model/links';
import { DEVICES } from '@/features/prototypes/devices';
import type { Device } from '@/features/prototypes/devices';
import type {
    Action,
    OverlayPlace,
    Transition,
    Trigger,
    Value,
} from '@/features/prototypes/interactions';
import { fill, freshData, removeRow, resolve, saveRow, withRows } from './data';
import type { DataState, InRow, Tables, Write } from './data';

// What Play does with what a prototype's items say they do: the actions, the
// switches that flip, what is shown or hidden, the values typed and set --
// worked out apart from the page, so it can be tested on its own. The page
// keeps a PlayState and draws the items as it says; the moves an action
// makes (to a screen, back, an overlay opened or closed) are handed back to
// the page to make, as each needs the browser's history or an animation.

/** What changes as a prototype is played, and is gone when it starts again. */
export type PlayState = {
    // Typed into fields, or set by an action
    values: Record<string, Value>;
    // Switches, checkboxes, radios and tabs flipped from how they were drawn
    on: Record<string, boolean>;
    // Shown or hidden by an action, over how each starts
    hidden: Record<string, boolean>;
    // The tables as they are in this play, and the row of each in hand
    data: DataState;
};

/** A play starting: nothing typed, flipped or shown -- and the tables as given. */
export const freshState = (tables?: Tables | null): PlayState => ({
    values: {},
    on: {},
    hidden: {},
    data: freshData(tables),
});

/** A move for the page to make, with how it looks. */
export type Move =
    | { kind: 'to'; screen: string; transition: Transition }
    | { kind: 'back'; transition: Transition }
    | {
          kind: 'overlay';
          screen: string;
          place: OverlayPlace;
          transition: Transition;
      }
    | { kind: 'close' };

// --------------------------------------------------------------- The parts
/** The kit parts a tap flips, when they lead nowhere. */
export const SWITCHES = ['toggle', 'checkbox', 'radio', 'tab'];

/** The kit parts typed into, when they are given a field's name. */
export const FIELDS = ['text_field', 'search_field'];

// Of the switches, those that are one of several: tapping one on turns the
// rest of its choice off, and tapping it again leaves it on
const ONE_OF = ['radio', 'tab'];

/** What tapping an item does, as it says (the board reads it): any action, or nothing. */
export const tapOf = (item: Item): Action | null => actionOf(item);

/** What a trigger other than a tap does on an item, if anything. */
export const triggerOf = (item: Item, trigger: Trigger): Action | null =>
    whenOf(item)[trigger] ?? null;

/** The triggers other than a tap an item answers to. */
export const gesturesOf = (item: Item): Trigger[] =>
    Object.keys(whenOf(item)) as Trigger[];

/** A switch that flips when tapped: one of the kit's, leading nowhere. */
export const isSwitch = (item: Item): boolean =>
    SWITCHES.includes(item.kind) && !tapOf(item);

/** A field typed into in Play, and the name what is typed is kept under. */
export const fieldOf = (item: Item): string | null => {
    const name = item.field?.trim();

    return FIELDS.includes(item.kind) && name ? name : null;
};

/** Whether anything happens when the item is touched in Play. */
export const isLive = (item: Item): boolean =>
    !!tapOf(item) || gesturesOf(item).length > 0 || isSwitch(item);

// ------------------------------------------------------------------- State
/** Whether an item is hidden now: kept hidden on the board, or in Play. */
export const isHidden = (
    item: Item,
    state: PlayState,
    copies?: Map<string, string>,
): boolean =>
    item.hidden ||
    (state.hidden[item.id] ??
        state.hidden[copies?.get(item.id) ?? ''] ??
        item.startsHidden === true);

/** Whether a switch is on now. */
export const isOn = (item: Item, state: PlayState): boolean =>
    state.on[item.id] ?? item.on;

/** Words with each {name} filled in: from the values, the tables' rows. */
export const filled = (
    text: string,
    state: Pick<PlayState, 'values'> & Partial<Pick<PlayState, 'data'>>,
    inRow?: InRow | null,
): string => fill(text, state.values, state.data ?? freshData(), inRow);

/** The board as drawn now, and which row each copy in a row card is for. */
export type Drawn = {
    items: Item[];
    rows: Map<string, InRow>;
    copies: Map<string, string>;
};

/**
 * The items as they are drawn now: each row card shown for its rows,
 * switches as flipped, hidden ones hidden, {name}s filled in. A field keeps
 * its words, for its input's hint.
 */
export const drawnOf = (items: Item[], state: PlayState): Drawn => {
    const expanded = withRows(items, state.values, state.data);

    return {
        ...expanded,
        items: expanded.items.map((item) => {
            const hidden = isHidden(item, state, expanded.copies);
            const on = state.on[item.id] ?? item.on;
            const text = filled(item.text, state, expanded.rows.get(item.id));

            return hidden === item.hidden &&
                on === item.on &&
                text === item.text
                ? item
                : { ...item, hidden, on, text };
        }),
    };
};

/** The items as they are drawn now (drawnOf, without its rows). */
export const playedItems = (items: Item[], state: PlayState): Item[] =>
    drawnOf(items, state).items;

/** Whether a value says yes: set, and not false or nothing. */
const yes = (value: Value | undefined) =>
    value !== undefined && value !== false && value !== '';

/** Whether an `if` holds, by the values now. */
export const holds = (
    action: Extract<Action, { if: string }>,
    values: PlayState['values'],
    data?: DataState,
): boolean => {
    // A value by that name; or else a column of a row in hand
    const value =
        values[action.if] ??
        (data ? (resolve(action.if, values, data) ?? undefined) : undefined);

    return action.equals === undefined
        ? yes(value)
        : String(value ?? '') === String(action.equals);
};

/**
 * What an action does: the state it leaves, and the moves for the page to
 * make, in order. `items` are the prototype's, for the switches and
 * choices; `screens`, the frames it may move to.
 */
export const perform = (
    action: Action,
    state: PlayState,
    items: Item[],
    screens: Set<string>,
    // The fields on the screens it is done from, by name: what a save reads
    // for the columns it does not say; left out, any field
    reach?: Set<string>,
): { state: PlayState; moves: Move[]; writes: Write[] } => {
    let now = state;
    const moves: Move[] = [];
    // Changes to the tables, for the page to keep in them too when it saves there
    const writes: Write[] = [];
    // An `if` inside an `if` inside an `all`, and so on: far enough
    let depth = 0;

    const run = (one: Action) => {
        if (++depth > 50) {
            return;
        }

        if ('to' in one) {
            if (!screens.has(one.to)) {
                return;
            }

            moves.push(
                one.overlay
                    ? {
                          kind: 'overlay',
                          screen: one.to,
                          place: one.overlay,
                          transition: one.transition ?? 'slide',
                      }
                    : {
                          kind: 'to',
                          screen: one.to,
                          transition: one.transition ?? 'slide',
                      },
            );
        } else if ('back' in one) {
            moves.push({ kind: 'back', transition: one.transition ?? 'slide' });
        } else if ('close' in one) {
            moves.push({ kind: 'close' });
        } else if ('toggle' in one) {
            const byId = new Map(items.map((item) => [item.id, item]));
            const hidden = { ...now.hidden };

            for (const id of one.toggle) {
                const item = byId.get(id);

                if (item) {
                    hidden[id] = !isHidden({ ...item, hidden: false }, now);
                }
            }

            now = { ...now, hidden };
        } else if ('show' in one || 'hide' in one) {
            const ids = 'show' in one ? one.show : one.hide;
            const hidden = { ...now.hidden };

            for (const id of ids) {
                hidden[id] = 'hide' in one;
            }

            now = { ...now, hidden };
        } else if ('set' in one) {
            now = { ...now, values: { ...now.values, [one.set]: one.value } };
        } else if ('if' in one) {
            const next = holds(one, now.values, now.data) ? one.then : one.else;

            if (next) {
                run(next);
            }
        } else if ('save' in one || 'remove' in one) {
            const done =
                'save' in one
                    ? saveRow(
                          now.data,
                          one.save,
                          one.row,
                          one.values,
                          now.values,
                          reach,
                      )
                    : removeRow(now.data, one.remove);

            now = { ...now, data: done.data };

            if (done.write) {
                writes.push(done.write);
            }
        } else if ('all' in one) {
            for (const each of one.all) {
                run(each);
            }
        }
    };

    run(action);

    return { state: now, moves, writes };
};

/**
 * A switch tapped: flipped -- or, one of a choice, turned on and the rest of
 * its choice on the same screen turned off.
 */
export const flip = (
    item: Item,
    state: PlayState,
    items: Item[],
    screenOf: (item: Item) => string | undefined,
): PlayState => {
    const choice = item.choice?.trim();

    if (!choice || !ONE_OF.includes(item.kind)) {
        return {
            ...state,
            on: { ...state.on, [item.id]: !isOn(item, state) },
        };
    }

    const home = screenOf(item);
    const on = { ...state.on };

    for (const other of items) {
        if (
            ONE_OF.includes(other.kind) &&
            other.choice?.trim() === choice &&
            screenOf(other) === home
        ) {
            on[other.id] = other.id === item.id;
        }
    }

    return { ...state, on };
};

/** A field typed into: what it says now kept under its name. */
export const typed = (
    state: PlayState,
    name: string,
    value: string,
): PlayState => ({ ...state, values: { ...state.values, [name]: value } });

// ----------------------------------------------------------------- Screens
/** What a screen does by itself after a while, if anything. */
export const afterOf = (
    frame: Item | undefined,
): { ms: number; action: Action } | null => {
    const after = frame ? screenOf(frame).after : null;

    if (!after || !(after.seconds > 0)) {
        return null;
    }

    return {
        ms: Math.min(30, Math.max(0.5, after.seconds)) * 1000,
        action: after.do,
    };
};

/**
 * How much of a screen shows at once, in board units: its device's height at
 * the screen's width. A screen taller than that scrolls.
 */
export const viewOf = (
    frame: { width: number; height: number },
    device: Device,
): { height: number; scrolls: boolean } => {
    const shape = DEVICES[device];
    const height = (frame.width * shape.height) / shape.width;

    // A pixel or two over is a screen drawn by hand, not one that scrolls
    return frame.height > height + 2
        ? { height, scrolls: true }
        : { height: frame.height, scrolls: false };
};

// ------------------------------------------------------------------ Swipes
/** How far, in pixels, and how quick, in ms, a finger moves to swipe. */
export const SWIPE = { distance: 40, ms: 700 };

/** The swipe a finger made, if it made one. */
export const swipeOf = (
    dx: number,
    dy: number,
    ms: number,
): Extract<Trigger, `swipe-${string}`> | null => {
    if (
        ms > SWIPE.ms ||
        Math.max(Math.abs(dx), Math.abs(dy)) < SWIPE.distance
    ) {
        return null;
    }

    if (Math.abs(dx) >= Math.abs(dy)) {
        return dx < 0 ? 'swipe-left' : 'swipe-right';
    }

    return dy < 0 ? 'swipe-up' : 'swipe-down';
};
