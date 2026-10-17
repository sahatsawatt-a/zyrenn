/* oxlint-disable unicorn/no-thenable -- an Action's `if` has a `then`, as the
   contract (features/prototypes/interactions.ts) names it; nothing awaits one */
// What a prototype's items and screens do in Play: a tap's action (`link`),
// the other triggers' (`when`), and a screen's own after so many seconds
// (`screen.after`). Each is an Action (features/prototypes/interactions.ts)
// that may name screens -- frames, by id -- and items on the same screen. Kept
// sound here as items are copied and deleted; the server does the same
// (App\Support\Board\BoardActions).
import type {
    Action,
    ItemOn,
    OverlayPlace,
    Repeat,
    ScreenSettings,
    Transition,
    Trigger,
    Value,
} from '@/features/prototypes/interactions';
import { boundsOf } from './geometry';
import type { Item } from './items';
import { groupKeys } from './layers';

export type { Action, ItemOn, Repeat, ScreenSettings, Trigger };

/** Phase 1's link, which every Action still reads as. */
export type ItemLink = { to: string } | { back: true };

export const TRANSITIONS: Transition[] = [
    'slide',
    'push',
    'fade',
    'up',
    'none',
];

export const OVERLAY_PLACES: OverlayPlace[] = [
    'center',
    'bottom',
    'top',
    'left',
    'right',
];

export const TRIGGERS: Trigger[] = [
    'long-press',
    'hover',
    'swipe-left',
    'swipe-right',
    'swipe-up',
    'swipe-down',
];

/** How deep an `if` or `all` may nest: deeper is cut off, not followed. */
const DEEPEST = 6;

/** The most steps an `all` holds, and items a show, hide or toggle names. */
const MOST = 20;

const isRecord = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

const named = (value: unknown): string | null =>
    typeof value === 'string' && value.trim() ? value.trim() : null;

const ids = (value: unknown): string[] | null => {
    if (!Array.isArray(value)) {
        return null;
    }

    const kept = [...new Set(value.map(named).filter((id) => id !== null))];

    return kept.length ? kept.slice(0, MOST) : null;
};

const valueOf = (value: unknown): Value | null =>
    typeof value === 'string' || typeof value === 'boolean' ? value : null;

/**
 * An Action as it should be, from whatever was kept: anything unsound in it
 * left out, and nothing at all when nothing sound is left. A phase 1 link is
 * an Action already.
 */
export const cleanAction = (value: unknown, depth = 0): Action | null => {
    if (!isRecord(value) || depth > DEEPEST) {
        return null;
    }

    const transition = TRANSITIONS.includes(value.transition as Transition)
        ? { transition: value.transition as Transition }
        : {};

    if (named(value.to)) {
        return {
            to: named(value.to)!,
            ...transition,
            ...(OVERLAY_PLACES.includes(value.overlay as OverlayPlace)
                ? { overlay: value.overlay as OverlayPlace }
                : {}),
        };
    }

    if (value.back === true) {
        return { back: true, ...transition };
    }

    if (value.close === true) {
        return { close: true };
    }

    for (const kind of ['toggle', 'show', 'hide'] as const) {
        const found = ids(value[kind]);

        if (found) {
            return { [kind]: found } as Action;
        }
    }

    if (named(value.set) && valueOf(value.value) !== null) {
        return { set: named(value.set)!, value: valueOf(value.value)! };
    }

    // A project table as the prototype's database: a row saved -- new, or
    // the current one changed -- or the current row deleted
    if (named(value.save)) {
        const values = isRecord(value.values)
            ? Object.fromEntries(
                  Object.entries(value.values)
                      .filter(
                          ([column, words]) =>
                              named(column) && typeof words === 'string',
                      )
                      .slice(0, MOST)
                      .map(([column, words]) => [
                          column.trim(),
                          words as string,
                      ]),
              )
            : {};

        return {
            save: named(value.save)!,
            ...(value.row === 'current' ? { row: 'current' as const } : {}),
            ...(Object.keys(values).length ? { values } : {}),
        };
    }

    if (named(value.remove)) {
        return { remove: named(value.remove)! };
    }

    if (named(value.if)) {
        const then = cleanAction(value.then, depth + 1);
        const otherwise = cleanAction(value.else, depth + 1);

        if (!then) {
            return null;
        }

        return {
            if: named(value.if)!,
            ...(valueOf(value.equals) !== null
                ? { equals: valueOf(value.equals)! }
                : {}),
            then,
            ...(otherwise ? { else: otherwise } : {}),
        };
    }

    if (Array.isArray(value.all)) {
        const steps = value.all
            .slice(0, MOST)
            .map((step) => cleanAction(step, depth + 1))
            .filter((step) => step !== null);

        return steps.length === 1
            ? steps[0]
            : steps.length
              ? { all: steps }
              : null;
    }

    return null;
};

/** What tapping an item does, when it does something sound. */
export const linkOf = (item: Pick<Item, 'link'>): Action | null =>
    cleanAction(item.link);

/** What tapping an item does (its `link`), by the name Play reads it by. */
export const actionOf = linkOf;

/** Its other triggers' actions, each sound. */
export const whenOf = (item: Pick<Item, 'when'>): ItemOn => {
    const kept: ItemOn = {};

    if (isRecord(item.when)) {
        for (const trigger of TRIGGERS) {
            const action = cleanAction(item.when[trigger]);

            if (action) {
                kept[trigger] = action;
            }
        }
    }

    return kept;
};

/** A screen's own settings: what it does after so many seconds. */
export const screenOf = (item: Pick<Item, 'screen'>): ScreenSettings => {
    const after = isRecord(item.screen) ? item.screen.after : null;

    if (!isRecord(after)) {
        return {};
    }

    const seconds = Number(after.seconds);
    const action = cleanAction(after.do);

    return Number.isFinite(seconds) && action
        ? {
              after: {
                  seconds: Math.min(30, Math.max(0.5, seconds)),
                  do: action,
              },
          }
        : {};
};

/** Everything an item sets off: a tap first, then its other triggers. */
export const triggersOf = (
    item: Pick<Item, 'link' | 'when'>,
): { trigger: Trigger | 'tap'; action: Action }[] => {
    const tap = linkOf(item);

    return [
        ...(tap ? [{ trigger: 'tap' as const, action: tap }] : []),
        ...Object.entries(whenOf(item)).map(([trigger, action]) => ({
            trigger: trigger as Trigger,
            action: action as Action,
        })),
    ];
};

/** Every screen an Action names, in order, each once. */
export const screensNamed = (action: Action | null | undefined): string[] => {
    if (!action) {
        return [];
    }

    if ('to' in action) {
        return [action.to];
    }

    if ('if' in action) {
        return [
            ...new Set([
                ...screensNamed(action.then),
                ...screensNamed(action.else),
            ]),
        ];
    }

    if ('all' in action) {
        return [...new Set(action.all.flatMap(screensNamed))];
    }

    return [];
};

/** Every item an Action shows, hides or flips, each once. */
export const itemsNamed = (action: Action | null | undefined): string[] => {
    if (!action) {
        return [];
    }

    for (const kind of ['toggle', 'show', 'hide'] as const) {
        if (kind in action) {
            return (action as Record<typeof kind, string[]>)[kind];
        }
    }

    if ('if' in action) {
        return [
            ...new Set([
                ...itemsNamed(action.then),
                ...itemsNamed(action.else),
            ]),
        ];
    }

    if ('all' in action) {
        return [...new Set(action.all.flatMap(itemsNamed))];
    }

    return [];
};

/**
 * An Action with each screen and item it names put through `rename`: given
 * back as another id, kept, or -- null -- left out. A step left with nothing
 * to do goes, and an `if` whose `then` goes, goes with it.
 */
export const renamed = (
    action: Action,
    rename: (id: string, kind: 'screen' | 'item') => string | null,
): Action | null => {
    if ('to' in action) {
        const to = rename(action.to, 'screen');

        return to ? { ...action, to } : null;
    }

    for (const kind of ['toggle', 'show', 'hide'] as const) {
        if (kind in action) {
            const kept = (action as Record<typeof kind, string[]>)[kind]
                .map((id) => rename(id, 'item'))
                .filter((id): id is string => !!id);

            return kept.length ? ({ [kind]: kept } as Action) : null;
        }
    }

    if ('if' in action) {
        const then = renamed(action.then, rename);
        const otherwise = action.else ? renamed(action.else, rename) : null;

        if (!then) {
            return null;
        }

        const { else: _gone, ...rest } = action;

        return { ...rest, then, ...(otherwise ? { else: otherwise } : {}) };
    }

    if ('all' in action) {
        const steps = action.all
            .map((step) => renamed(step, rename))
            .filter((step): step is Action => !!step);

        return steps.length ? { all: steps } : null;
    }

    return action;
};

type Acting = Pick<Item, 'link' | 'when' | 'screen'>;

/**
 * An item's actions, each put through `rename` (renamed): the tap, every
 * other trigger and a screen's own. Answers the item as it was when nothing
 * changed, so a list of them can be told apart from a changed one.
 */
const withActions = <T extends Acting>(
    item: T,
    rename: (id: string, kind: 'screen' | 'item') => string | null,
): T => {
    let changed = false;
    const redo = (action: Action | null) => {
        if (!action) {
            return null;
        }

        const after = renamed(action, rename);

        if (JSON.stringify(after) !== JSON.stringify(action)) {
            changed = true;
        }

        return after;
    };

    const link = redo(linkOf(item));
    const when = Object.fromEntries(
        Object.entries(whenOf(item))
            .map(([trigger, action]) => [trigger, redo(action as Action)])
            .filter(([, action]) => action),
    ) as ItemOn;
    const after = screenOf(item).after;
    const afterDo = after ? redo(after.do) : null;

    if (!changed) {
        return item;
    }

    return {
        ...item,
        link,
        when: Object.keys(when).length ? when : null,
        ...(item.screen !== undefined || after
            ? {
                  screen:
                      after && afterDo
                          ? { ...item.screen, after: { ...after, do: afterDo } }
                          : item.screen
                            ? { ...item.screen, after: null }
                            : item.screen,
              }
            : {}),
    };
};

/**
 * Items copied together, each action naming a screen or an item copied with
 * them pointed at its copy -- and one left behind kept as it is, so a copied
 * screen's buttons still go where the original's did.
 */
export const relinked = <T extends Acting>(
    item: T,
    copyOf: Map<string, string>,
): T => withActions(item, (id) => copyOf.get(id) ?? id);

/**
 * The board once the items in `gone` are taken away -- screens or the
 * things on them: everything any action said of them taken off too, with
 * how many items lost something, to say so.
 */
export const unlinkedFrom = (
    items: Item[],
    gone: Set<string>,
): { items: Item[]; cleared: number } => {
    let cleared = 0;

    const kept = items.map((item) => {
        if (gone.has(item.id)) {
            return item;
        }

        const after = withActions(item, (id) => (gone.has(id) ? null : id));

        if (after !== item) {
            cleared++;
        }

        return after;
    });

    return { items: cleared ? kept : items, cleared };
};

/** The screen an item goes to by a tap, if it goes to one that is there. */
export const targetOf = (item: Item, byId: Map<string, Item>): Item | null => {
    const [first] = screensNamed(linkOf(item));
    const frame = first ? byId.get(first) : undefined;

    return frame?.kind === 'frame' ? frame : null;
};

/** What a trigger is called, as a person does it. */
export const TRIGGER_WORDS: Record<Trigger | 'tap', string> = {
    tap: 'Tap',
    'long-press': 'Long press',
    hover: 'Hover',
    'swipe-left': 'Swipe left',
    'swipe-right': 'Swipe right',
    'swipe-up': 'Swipe up',
    'swipe-down': 'Swipe down',
};

/** How an Action reads, short: "Cart", "Back", "Open Menu over", "Show 2". */
export const actionWords = (
    action: Action,
    byId: Map<string, Item>,
): string => {
    const screen = (id: string) =>
        byId.get(id)?.text.trim().split('\n')[0] || 'a screen';

    if ('to' in action) {
        return action.overlay
            ? `Open ${screen(action.to)} over`
            : `Go to ${screen(action.to)}`;
    }

    if ('back' in action) {
        return 'Back';
    }

    if ('close' in action) {
        return 'Close the overlay';
    }

    for (const kind of ['toggle', 'show', 'hide'] as const) {
        if (kind in action) {
            const count = (action as Record<typeof kind, string[]>)[kind]
                .length;
            const verb = { toggle: 'Flip', show: 'Show', hide: 'Hide' }[kind];

            return count === 1 ? `${verb} 1 thing` : `${verb} ${count} things`;
        }
    }

    if ('set' in action) {
        return `Set ${action.set}`;
    }

    if ('save' in action) {
        return action.row === 'current' ? 'Save the row' : 'Add a row';
    }

    if ('remove' in action) {
        return 'Delete the row';
    }

    if ('if' in action) {
        return `If ${action.if}…`;
    }

    return 'all' in action ? `${action.all.length} steps` : 'Something';
};

/** How an item's actions read, one line each: "Tap: Go to Cart". */
export const linkWords = (
    item: Item,
    byId: Map<string, Item>,
): string | null => {
    const lines = triggersOf(item).map(
        ({ trigger, action }) =>
            `${TRIGGER_WORDS[trigger]}: ${actionWords(action, byId)}`,
    );

    return lines.length ? lines.join('\n') : null;
};

/** Every value an Action sets or asks about, each once. */
const valuesNamed = (action: Action | null | undefined): string[] => {
    if (!action) {
        return [];
    }

    if ('set' in action) {
        return [action.set];
    }

    if ('if' in action) {
        return [
            action.if,
            ...valuesNamed(action.then),
            ...valuesNamed(action.else),
        ];
    }

    if ('all' in action) {
        return action.all.flatMap(valuesNamed);
    }

    return [];
};

/**
 * The values named on a board, A to Z: what each text field is typed in
 * as, and what any action sets or asks about -- to pick from rather than
 * type again.
 */
export const valueNames = (items: Item[]): string[] =>
    [
        ...new Set(
            items.flatMap((item) => [
                ...(item.field ? [item.field] : []),
                ...triggersOf(item).flatMap(({ action }) =>
                    valuesNamed(action),
                ),
                ...valuesNamed(screenOf(item).after?.do),
            ]),
        ),
    ].sort((a, b) => a.localeCompare(b));

/** The most rows a repeat shows, and the gap between them at the most. */
export const REPEAT_ROWS = { least: 1, most: 200, usual: 50 };
const MOST_GAP = 400;
const MOST_WHERE = 10;

/**
 * A row card's repeat as it should be: a table named, and of the rest only
 * what is sound -- what to filter by, how to sort, how many, how far apart
 * and which way. Nothing, when no table is named.
 */
export const cleanRepeat = (value: unknown): Repeat | null => {
    if (!isRecord(value) || !named(value.table)) {
        return null;
    }

    const where = Array.isArray(value.where)
        ? value.where
              .filter(
                  (each): each is { column: string; is: string } =>
                      isRecord(each) &&
                      !!named(each.column) &&
                      typeof each.is === 'string',
              )
              .slice(0, MOST_WHERE)
              .map((each) => ({ column: each.column.trim(), is: each.is }))
        : [];
    const sort =
        isRecord(value.sort) && named(value.sort.column)
            ? {
                  column: named(value.sort.column)!,
                  ...(value.sort.desc === true ? { desc: true } : {}),
              }
            : null;
    const limit = Number(value.limit);
    const gap = Number(value.gap);

    return {
        table: named(value.table)!,
        ...(where.length ? { where } : {}),
        ...(sort ? { sort } : {}),
        ...(Number.isFinite(limit) && value.limit !== undefined
            ? {
                  limit: Math.round(
                      Math.min(
                          REPEAT_ROWS.most,
                          Math.max(REPEAT_ROWS.least, limit),
                      ),
                  ),
              }
            : {}),
        ...(Number.isFinite(gap) && value.gap !== undefined
            ? { gap: Math.min(MOST_GAP, Math.max(0, gap)) }
            : {}),
        ...(value.across === true ? { across: true } : {}),
    };
};

/** Every table an Action saves to or deletes from, each once. */
const tablesActed = (action: Action | null | undefined): string[] => {
    if (!action) {
        return [];
    }

    if ('save' in action) {
        return [action.save];
    }

    if ('remove' in action) {
        return [action.remove];
    }

    if ('if' in action) {
        return [...tablesActed(action.then), ...tablesActed(action.else)];
    }

    if ('all' in action) {
        return action.all.flatMap(tablesActed);
    }

    return [];
};

/**
 * Every table a prototype uses, by ref_id, each once: those its row cards
 * repeat, and those its actions save to or delete from -- what Play needs
 * sent to it.
 */
export const tablesNamed = (items: Item[]): string[] => [
    ...new Set(
        items.flatMap((item) => [
            ...(cleanRepeat(item.repeat)
                ? [cleanRepeat(item.repeat)!.table]
                : []),
            ...triggersOf(item).flatMap(({ action }) => tablesActed(action)),
            ...tablesActed(screenOf(item).after?.do),
        ]),
    ),
];

/** A row card's repeat, read the way Play reads it (cleanRepeat). */
export const repeatOf = (item: Pick<Item, 'repeat'>): Repeat | null =>
    cleanRepeat(item.repeat);

/**
 * What a row card holds: the items whose whole box lies inside its box, on
 * the same screen -- drawn once on the board, shown for each row in Play.
 */
export const rowContents = (card: Item, items: Item[]): Item[] => {
    const keys = groupKeys(items);
    const home = keys.get(card.id);
    const box = boundsOf(card);

    return items.filter((item) => {
        if (
            item.id === card.id ||
            item.kind === 'frame' ||
            keys.get(item.id) !== home
        ) {
            return false;
        }

        const inner = boundsOf(item);

        return (
            inner.x >= box.x &&
            inner.y >= box.y &&
            inner.x + inner.width <= box.x + box.width &&
            inner.y + inner.height <= box.y + box.height
        );
    });
};

/** The row card an item is drawn in, if it is inside one. */
export const rowCardOf = (item: Item, items: Item[]): Item | null =>
    items.find(
        (card) =>
            card.id !== item.id &&
            cleanRepeat(card.repeat) &&
            rowContents(card, items).some((inside) => inside.id === item.id),
    ) ?? null;
