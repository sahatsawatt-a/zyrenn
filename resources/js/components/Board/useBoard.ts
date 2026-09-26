import { computed, ref } from 'vue';
import type { Item, ItemKind } from './board';
import { STICKY_COLOURS, bumpIdsTo, makeItem, newId } from './board';

/**
 * The board's items, what is selected, and undo/redo.
 *
 * History is whole-board snapshots rather than a list of reversible commands:
 * a board this size is a few kilobytes of JSON, and it means a new tool cannot
 * forget to write its own undo step.
 */
export function useBoard(initial: Item[] | null = null) {
    const items = ref<Item[]>([]);
    const selection = ref<string[]>([]);

    const past: Item[][] = [];
    const future: Item[][] = [];

    // Not structuredClone: items.value holds Vue reactive proxies, which it
    // refuses to clone. Items are flat, so a shallow copy plus the point list
    // is the whole job.
    const copy = (item: Item): Item => ({
        ...item,
        points: [...item.points],
        from: item.from ? { ...item.from } : null,
        to: item.to ? { ...item.to } : null,
    });

    const snapshot = (): Item[] => items.value.map(copy);

    /** Call before any change that should be undoable. */
    const commit = () => {
        past.push(snapshot());

        if (past.length > 60) {
            past.shift();
        }

        future.length = 0;
    };

    const undo = () => {
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
        const next = future.pop();

        if (!next) {
            return;
        }

        past.push(snapshot());
        items.value = next;
    };

    const canUndo = computed(() => past.length > 0);
    const canRedo = computed(() => future.length > 0);

    // ----------------------------------------------------------- selection
    const selected = computed(() =>
        items.value.filter((item) => selection.value.includes(item.id)),
    );

    const byId = computed(
        () => new Map(items.value.map((item) => [item.id, item])),
    );

    const select = (ids: string[]) => {
        selection.value = ids;
    };

    const toggleInSelection = (id: string) => {
        selection.value = selection.value.includes(id)
            ? selection.value.filter((other) => other !== id)
            : [...selection.value, id];
    };

    // ------------------------------------------------------------ mutation
    const add = (item: Item, { keepSelection = false } = {}) => {
        commit();
        items.value.push(item);

        if (!keepSelection) {
            selection.value = [item.id];
        }
    };

    const remove = (ids: string[]) => {
        if (!ids.length) {
            return;
        }

        commit();
        items.value = items.value.filter(
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
    };

    const duplicate = () => {
        if (!selected.value.length) {
            return;
        }

        commit();

        const copies = selected.value.map((item) => ({
            ...copy(item),
            id: newId(),
            x: item.x + 32,
            y: item.y + 32,
        }));

        items.value.push(...copies);
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

            return;
        }

        const step = direction === 'forward' ? 1 : -1;
        const next = [...items.value];

        // Walk from the end when moving up, so two selected neighbours keep
        // their order instead of swapping past each other
        const order = step > 0 ? [...moving].reverse() : moving;

        order.forEach((item) => {
            const at = next.indexOf(item);
            const to = at + step;

            if (at < 0 || to < 0 || to >= next.length) {
                return;
            }

            // Do not swap with something that is moving too
            if (selection.value.includes(next[to].id)) {
                return;
            }

            next.splice(at, 1);
            next.splice(to, 0, item);
        });

        items.value = next;
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
    };

    const toggle = (id: string, field: 'hidden' | 'locked') => {
        const item = byId.value.get(id);

        if (!item) {
            return;
        }

        commit();
        item[field] = !item[field];

        // Neither can stay selected: one cannot be seen, the other touched
        if (item[field]) {
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
    };

    /** Apply a partial change to everything selected, as one undo step. */
    const updateSelected = (patch: Partial<Item>) => {
        if (!selected.value.length) {
            return;
        }

        commit();
        selected.value.forEach((item) => Object.assign(item, patch));
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
            // A stroke has no fill worth changing; colour its line instead
            if (item.kind === 'arrow' || item.kind === 'draw') {
                item.stroke = colour;
            } else {
                item.fill = colour;
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
        items.value = saved.map(copy);

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
        byId,
        frames,
        selection,
        selected,
        select,
        toggleInSelection,
        add,
        remove,
        duplicate,
        reorder,
        moveTo,
        toggle,
        setText,
        paint,
        paintStroke,
        updateSelected,
        commit,
        undo,
        redo,
        canUndo,
        canRedo,
        reset,
        addMany,
        makeItem: (
            kind: ItemKind,
            x: number,
            y: number,
            colours?: { fill?: string; stroke?: string },
        ) => makeItem(kind, x, y, colours),
    };
}
