import { computed, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import {
    isSymbol,
    nextDesignator,
    symbolOf,
    withWords,
    wordsOf,
} from '@/features/boards/circuit/symbols';
import {
    STICKY_COLOURS,
    bumpIdsTo,
    hydrate,
    makeItem,
    newId,
} from '../model/items';
import { fittedHeight, labelHeight } from '../drawing/labels';
import { groupKeys, keepOnFrames } from '../model/layers';
import { relinked, unlinkedFrom } from '../model/links';
import type { Item, ItemKind } from '../model/items';

/**
 * The board's items, what is selected, and undo/redo.
 *
 * History is whole-board snapshots rather than a list of reversible commands:
 * a board this size is a few kilobytes of JSON, and it means a new tool cannot
 * forget to write its own undo step.
 */
/**
 * Undo that lives somewhere else: a shared board's, which takes back only
 * your own changes (see useBoardSync).
 */
export type BoardHistory = {
    commit: () => void;
    undo: () => void;
    redo: () => void;
    canUndo: Ref<boolean>;
    canRedo: Ref<boolean>;
};

export function useBoard(initial: Item[] | null = null) {
    const items = ref<Item[]>([]);
    const selection = ref<string[]>([]);

    // Counts changes to the board, for whatever has to follow them -- saving,
    // the shared copy -- to watch instead of each walking every item on every
    // move of a drag. Two levels, the list and each item's fields, is all
    // there is to see: nothing nested is changed in place (a connector's end,
    // a stroke's points are replaced whole), and going further would walk
    // every point of every stroke.
    const revision = ref(0);

    watch(items, () => revision.value++, { deep: 2 });

    const past: Item[][] = [];
    const future: Item[][] = [];

    // Not structuredClone: items.value holds Vue reactive proxies, which it
    // refuses to clone. Items are flat, so a shallow copy plus the point list
    // is the whole job.
    const copy = (item: Item): Item => ({
        ...item,
        points: [...item.points],
        ...(item.pressures ? { pressures: [...item.pressures] } : {}),
        // (a table's rows are its own, not shared with a copy of it)
        ...(item.rows ? { rows: item.rows.map((row) => ({ ...row })) } : {}),
        from: item.from ? { ...item.from } : null,
        to: item.to ? { ...item.to } : null,
    });

    const snapshot = (): Item[] => items.value.map(copy);

    // Set once the board is shared; everything below then asks it instead
    const shared = shallowRef<BoardHistory | null>(null);

    /** Call before any change that should be undoable. */
    const commit = () => {
        if (shared.value) {
            shared.value.commit();

            return;
        }

        past.push(snapshot());

        if (past.length > 60) {
            past.shift();
        }

        future.length = 0;
    };

    const undo = () => {
        if (shared.value) {
            shared.value.undo();

            return;
        }

        const previous = past.pop();

        if (!previous) {
            return;
        }

        future.push(snapshot());
        items.value = previous;
        selection.value = selection.value.filter((id) =>
            previous.some((item) => item.id === id),
        );
    };

    const redo = () => {
        if (shared.value) {
            shared.value.redo();

            return;
        }

        const next = future.pop();

        if (!next) {
            return;
        }

        past.push(snapshot());
        items.value = next;
    };

    const canUndo = computed(() =>
        shared.value ? shared.value.canUndo.value : past.length > 0,
    );
    const canRedo = computed(() =>
        shared.value ? shared.value.canRedo.value : future.length > 0,
    );

    /** Hands undo and redo to a shared board's own history. */
    const shareHistory = (history: BoardHistory) => {
        shared.value = history;
        past.length = 0;
        future.length = 0;
    };

    // ----------------------------------------------------------- selection
    const selected = computed(() =>
        items.value.filter((item) => selection.value.includes(item.id)),
    );

    const byId = computed(
        () => new Map(items.value.map((item) => [item.id, item])),
    );

    // A group is picked whole: whatever names one of its items names them all
    const withGroups = (ids: string[]): string[] => {
        const groups = new Set(
            ids.map((id) => byId.value.get(id)?.group).filter(Boolean),
        );

        if (!groups.size) {
            return ids;
        }

        return [
            ...new Set([
                ...ids,
                ...items.value
                    .filter((item) => item.group && groups.has(item.group))
                    .map((item) => item.id),
            ]),
        ];
    };

    const select = (ids: string[]) => {
        selection.value = withGroups(ids);
    };

    const toggleInSelection = (id: string) => {
        const together = withGroups([id]);

        selection.value = selection.value.includes(id)
            ? selection.value.filter((other) => !together.includes(other))
            : [...new Set([...selection.value, ...together])];
    };

    /** The group the selection is, whole and alone; null when it is not one. */
    const selectionGroup = computed(() => {
        const group = selected.value[0]?.group;

        return group &&
            selected.value.every((item) => item.group === group) &&
            items.value.filter((item) => item.group === group).length ===
                selected.value.length
            ? group
            : null;
    });

    /** What is selected, made one group: picked, moved and sized together. */
    const group = () => {
        if (selected.value.length < 2) {
            return;
        }

        commit();
        const id = `g${newId()}`;

        for (const item of selected.value) {
            item.group = id;
        }
    };

    /** The selection's groups undone: each of its items on its own again. */
    const ungroup = () => {
        if (!selected.value.some((item) => item.group)) {
            return;
        }

        commit();

        for (const item of selected.value) {
            delete item.group;
        }
    };

    // ------------------------------------------------------------ mutation
    /**
     * Brings back up anything a frame has come to cover, and puts whatever was
     * just `dropped` onto a different frame on top of it (see keepOnFrames).
     * Part of the change that called for it, so no undo step of its own.
     */
    const settle = (dropped: string[] = []) => {
        const next = keepOnFrames(items.value, dropped);

        if (next !== items.value) {
            items.value = next;
        }
    };

    const add = (item: Item, { keepSelection = false } = {}) => {
        commit();
        items.value.push(item);
        settle();

        if (!keepSelection) {
            selection.value = [item.id];
        }
    };

    /** Puts several things on at once, as one step: a circuit, say. */
    const place = (placed: Item[]) => {
        if (!placed.length) {
            return;
        }

        commit();
        items.value.push(...placed);
        settle();
        selection.value = placed
            .filter((item) => item.kind !== 'arrow')
            .map((item) => item.id);
    };

    /**
     * Takes things off the board, with any line left hanging off them and any
     * link to a screen among them. Answers how many links went, to say so.
     */
    const remove = (ids: string[]): number => {
        if (!ids.length) {
            return 0;
        }

        commit();
        const { items: unlinked, cleared } = unlinkedFrom(
            items.value,
            new Set(ids),
        );
        items.value = unlinked.filter(
            (item) =>
                !ids.includes(item.id) &&
                // A connector with nothing left to hang off goes too
                !(
                    item.kind === 'arrow' &&
                    ((item.from?.item && ids.includes(item.from.item)) ||
                        (item.to?.item && ids.includes(item.to.item)))
                ),
        );
        selection.value = [];

        return cleared;
    };

    const duplicate = () => {
        if (!selected.value.length) {
            return;
        }

        commit();

        const copies = selected.value.map((item) => ({
            ...copy(item),
            id: newId(),
            // 40 keeps a part on the grid its pins are on
            x: item.x + (isSymbol(item) ? 40 : 32),
            y: item.y + (isSymbol(item) ? 40 : 32),
        }));

        // A line copied along with what it joins joins the copies
        const copyOf = new Map(
            selected.value.map((item, index) => [item.id, copies[index].id]),
        );

        for (const made of copies) {
            // A link to a screen copied too goes to that screen's copy
            made.link = relinked(made, copyOf).link;

            for (const end of [made.from, made.to]) {
                if (end?.item && copyOf.has(end.item)) {
                    end.item = copyOf.get(end.item)!;
                }
            }

            // Two parts cannot both be R1: the copy takes the next number
            const prefix = isSymbol(made) && symbolOf(made.symbol)?.prefix;

            if (prefix) {
                made.text = withWords(
                    nextDesignator(
                        [...items.value, ...copies.filter((c) => c !== made)],
                        prefix,
                    ),
                    wordsOf(made).value,
                );
            }
        }

        items.value.push(...copies);
        settle();
        selection.value = copies.map((copy) => copy.id);
    };

    /**
     * Move the selection through the paint order. Front and back jump the whole
     * way; forward and backward step past one neighbour at a time, which is
     * what you want when a shape is buried under two others.
     */
    const reorder = (direction: 'front' | 'back' | 'forward' | 'backward') => {
        if (!selected.value.length) {
            return;
        }

        commit();

        const moving = selected.value;
        const rest = items.value.filter(
            (item) => !selection.value.includes(item.id),
        );

        if (direction === 'front' || direction === 'back') {
            items.value =
                direction === 'front'
                    ? [...rest, ...moving]
                    : [...moving, ...rest];
            // The back of a frame is the bottom of what is on it, not under it
            settle();

            return;
        }

        const step = direction === 'forward' ? 1 : -1;
        const next = [...items.value];

        // Walk from the end when moving up, so two selected neighbours keep
        // their order instead of swapping past each other
        const order = step > 0 ? [...moving].reverse() : moving;

        // Stepping is by what the layers list shows, which is grouped by frame:
        // stepping past a shape on another frame looks like nothing happening.
        const groups = groupKeys(items.value);

        order.forEach((item) => {
            const at = next.indexOf(item);

            if (at < 0) {
                return;
            }

            const home = groups.get(item.id);
            let to = at + step;

            // Over anything on another frame, and over what is moving too
            while (
                to >= 0 &&
                to < next.length &&
                (groups.get(next[to].id) !== home ||
                    selection.value.includes(next[to].id))
            ) {
                to += step;
            }

            if (to < 0 || to >= next.length) {
                return;
            }

            next.splice(at, 1);
            next.splice(to, 0, item);
        });

        items.value = next;
        settle();
    };

    /** Drop an item straight into a place in the list. */
    const moveTo = (id: string, index: number) => {
        const from = items.value.findIndex((item) => item.id === id);

        if (from < 0 || from === index) {
            return;
        }

        commit();

        const next = [...items.value];
        const [item] = next.splice(from, 1);
        next.splice(Math.max(0, Math.min(next.length, index)), 0, item);
        items.value = next;
        settle();
    };

    const toggle = (id: string, field: 'hidden' | 'locked' | 'pdfHidden') => {
        const item = byId.value.get(id);

        if (!item) {
            return;
        }

        commit();
        item[field] = !item[field];

        // Neither can stay selected: one cannot be seen, the other touched.
        // Left out of the PDF, a frame is still there to work on.
        if (item[field] && field !== 'pdfHidden') {
            selection.value = selection.value.filter((other) => other !== id);
        }
    };

    const setText = (id: string, text: string) => {
        const item = byId.value.get(id);

        if (!item || item.text === text) {
            return;
        }

        commit();
        item.text = text;

        // A text item is only its words: it grows to hold them all
        if (item.kind === 'text') {
            item.height = Math.max(item.height, Math.ceil(labelHeight(item)));
        }

        hug(item);
    };

    // An item that hugs its words is as tall as they are, whatever changed,
    // and a sticky grows to hold them
    const hug = (item: Item) => {
        const height = fittedHeight(item);

        if (height !== item.height) {
            item.height = height;
        }
    };

    /** Apply a partial change to everything selected, as one undo step. */
    /** Changes that differ from item to item, made as one step to undo. */
    const updateEach = (changes: Map<string, Partial<Item>>) => {
        if (!changes.size) {
            return;
        }

        commit();
        items.value.forEach((item) => {
            const change = changes.get(item.id);

            if (change) {
                Object.assign(item, change);
                hug(item);
            }
        });
    };

    const updateSelected = (patch: Partial<Item>) => {
        if (!selected.value.length) {
            return;
        }

        commit();
        selected.value.forEach((item) => {
            Object.assign(item, patch);
            hug(item);
        });
    };

    const paintStroke = (colour: string) => {
        if (!selected.value.length) {
            return;
        }

        commit();
        selected.value.forEach((item) => {
            item.stroke = colour;
        });
    };

    const paint = (colour: string) => {
        if (!selected.value.length) {
            return;
        }

        commit();
        selected.value.forEach((item) => {
            // A stroke has no fill worth changing, nor has a symbol, which is
            // all line; colour that instead
            if (
                item.kind === 'arrow' ||
                item.kind === 'draw' ||
                item.kind === 'symbol'
            ) {
                item.stroke = colour;
            } else {
                item.fill = colour;
                // Its words were coloured for the fill it had: on a fill
                // picked by hand they go back to what reads best on it
                delete item.color;
            }
        });
    };

    // ------------------------------------------------------------- content
    const frames = computed(() =>
        items.value.filter((item) => item.kind === 'frame'),
    );

    const reset = () => {
        past.length = 0;
        future.length = 0;
        selection.value = [];

        const frameOne = makeItem('frame', 0, 0);
        frameOne.text = 'What we shipped';

        const frameTwo = makeItem('frame', 1120, 0);
        frameTwo.text = 'What is next';

        const title = makeItem('text', 60, 60);
        title.text = 'Zyrenn, this quarter';
        title.fontSize = 44;
        title.width = 640;
        title.height = 60;

        const notes: Item[] = ['Notes', 'Drive', 'MCP tools'].map(
            (label, index) => {
                const sticky = makeItem('sticky', 80 + index * 220, 200);
                sticky.text = label;
                sticky.fill = STICKY_COLOURS[index];

                return sticky;
            },
        );

        const heading = makeItem('text', 1180, 60);
        heading.text = 'Next up';
        heading.fontSize = 44;
        heading.width = 400;
        heading.height = 60;

        const box = makeItem('rect', 1180, 200);
        box.width = 420;
        box.height = 180;

        const boxLabel = makeItem('text', 1210, 250);
        boxLabel.text = 'Pick a canvas library';
        boxLabel.fontSize = 24;

        items.value = [
            frameOne,
            frameTwo,
            title,
            ...notes,
            heading,
            box,
            boxLabel,
        ];
    };

    const addMany = (count: number) => {
        commit();

        const start = items.value.length;

        for (let index = 0; index < count; index++) {
            const sticky = makeItem(
                'sticky',
                -1400 + (index % 20) * 200,
                700 + Math.floor(index / 20) * 200,
            );
            sticky.text = `Note ${start + index}`;
            items.value.push(sticky);
        }
    };

    /** Open a board that was saved earlier. */
    const load = (saved: Item[]) => {
        past.length = 0;
        future.length = 0;
        selection.value = [];
        items.value = saved.map((item) => copy(hydrate(item)));

        // Ids carry on from the highest one already used, so a new item cannot
        // take the id of one that is already on the board
        const highest = saved.reduce((top, item) => {
            const number = Number(item.id.replace(/\D/g, ''));

            return Number.isFinite(number) && number > top ? number : top;
        }, 0);

        bumpIdsTo(highest);
    };

    if (initial === null) {
        reset();
    } else {
        load(initial);
    }

    return {
        load,
        items,
        revision,
        byId,
        frames,
        selection,
        selected,
        select,
        toggleInSelection,
        selectionGroup,
        group,
        ungroup,
        add,
        place,
        remove,
        duplicate,
        reorder,
        settle,
        moveTo,
        toggle,
        setText,
        paint,
        paintStroke,
        updateEach,
        updateSelected,
        commit,
        undo,
        redo,
        canUndo,
        canRedo,
        shareHistory,
        reset,
        addMany,
        makeItem: (
            kind: ItemKind,
            x: number,
            y: number,
            colours?: { fill?: string; stroke?: string; symbol?: string },
        ) => makeItem(kind, x, y, colours),
    };
}
