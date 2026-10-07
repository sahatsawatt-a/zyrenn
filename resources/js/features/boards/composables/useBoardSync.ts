import type { HocuspocusProvider } from '@hocuspocus/provider';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import * as Y from 'yjs';
import { hydrate } from './items';
import type { Item } from './items';
import type { useBoard } from './useBoard';

type Board = ReturnType<typeof useBoard>;

/**
 * A shared board's items in the order they are painted, as the collaboration
 * server lays them out (collab/kinds.mjs): each under its id in the "items"
 * map, their order as ids in "order". One missing from the order -- two
 * people added at once -- still belongs on the board, so it goes on top.
 */
export function sharedItems(document: Y.Doc): Item[] {
    const items = document.getMap<Item>('items');
    const seen = new Set<string>();
    const ordered: Item[] = [];

    for (const id of document.getArray<string>('order').toArray()) {
        const item = items.get(id);

        if (item && !seen.has(id)) {
            seen.add(id);
            ordered.push(item);
        }
    }

    for (const [id, item] of items) {
        if (!seen.has(id)) {
            ordered.push(item);
        }
    }

    return ordered;
}

/**
 * Keeps a board and its shared document the same, both ways.
 *
 * What changes here is written to the document at most once a frame, and only
 * the items that did change, so two people moving two things never collide;
 * the last change to one item wins. What the others change is put into the
 * board's own item objects, so something being dragged here keeps being
 * dragged. Undo takes back only your own changes.
 */
export function useBoardSync(
    board: Board,
    document: Y.Doc,
    synced: Ref<boolean>,
) {
    const items = document.getMap<Item>('items');
    const order = document.getArray<string>('order');

    // Marks the writes made from here, for undo and for not reading them back
    const local = { from: 'board' };

    // What the document holds, as this board last saw it: id => JSON
    let known = new Map<string, string>();
    let knownOrder: string[] = [];
    let ready = false;

    // ------------------------------------------------ this board → everyone
    const push = () => {
        if (!ready) {
            return;
        }

        const now = board.items.value;
        const ids = now.map((item) => item.id);
        const present = new Set(ids);

        document.transact(() => {
            for (const item of now) {
                const json = JSON.stringify(item);

                if (known.get(item.id) !== json) {
                    items.set(item.id, JSON.parse(json));
                    known.set(item.id, json);
                }
            }

            for (const id of known.keys()) {
                if (!present.has(id)) {
                    items.delete(id);
                    known.delete(id);
                }
            }

            if (ids.join() !== knownOrder.join()) {
                const shared = order.toArray();

                for (let at = shared.length - 1; at >= 0; at--) {
                    if (!present.has(shared[at])) {
                        order.delete(at, 1);
                    }
                }

                const kept = order.toArray();

                // Only added on top, the usual case: add them, rather than
                // rewrite an order someone else may be adding to as well
                if (kept.every((id, at) => ids[at] === id)) {
                    order.push(ids.slice(kept.length));
                } else {
                    order.delete(0, order.length);
                    order.insert(0, ids);
                }

                knownOrder = ids;
            }
        }, local);
    };

    let frame = 0;

    const pushSoon = () => {
        if (!frame) {
            frame = requestAnimationFrame(() => {
                frame = 0;
                push();
            });
        }
    };

    // What is waiting for the next frame goes now: before theirs is read in,
    // before an undo step ends, before undoing
    const pushNow = () => {
        if (frame) {
            cancelAnimationFrame(frame);
            frame = 0;
        }

        push();
    };

    // ------------------------------------------------ everyone → this board
    const pull = () => {
        pushNow();

        const mine = new Map(board.items.value.map((item) => [item.id, item]));
        const next: Item[] = [];

        known = new Map();

        for (const saved of sharedItems(document)) {
            const theirs = hydrate(saved);
            const json = JSON.stringify(theirs);
            const current = mine.get(theirs.id);

            if (current && JSON.stringify(current) !== json) {
                Object.assign(current, theirs);
            }

            next.push(current ?? theirs);
            known.set(theirs.id, json);
        }

        knownOrder = next.map((item) => item.id);
        board.items.value = next;
        board.selection.value = board.selection.value.filter((id) =>
            known.has(id),
        );
    };

    let pulling = false;

    const heard = (_event: unknown, transaction: Y.Transaction) => {
        if (transaction.origin === local || pulling) {
            return;
        }

        pulling = true;
        queueMicrotask(() => {
            pulling = false;
            pull();
        });
    };

    items.observe(heard);
    order.observe(heard);

    watch(board.revision, pushSoon);

    // The board shows what the page was given until the shared one arrives,
    // and then is the shared one: what the page was given can be older than
    // what everyone has drawn since, so it is never written over it. (Pulled
    // before writing is allowed: the board is taken as it is, and nothing of
    // the page's own goes out with it -- which mattered when this started up
    // against a document already open, as a remounted canvas does.)
    watch(
        synced,
        (isSynced) => {
            if (isSynced && !ready) {
                pull();
                ready = true;
            }
        },
        { immediate: true },
    );

    // ------------------------------------------------------------ your undo
    const undoManager = new Y.UndoManager([items, order], {
        trackedOrigins: new Set([local]),
        // One step is everything between two commits, however long a drag takes
        captureTimeout: Number.MAX_SAFE_INTEGER,
    });

    const canUndo = ref(false);
    const canRedo = ref(false);

    const refresh = () => {
        canUndo.value = undoManager.undoStack.length > 0;
        canRedo.value = undoManager.redoStack.length > 0;
    };

    undoManager.on('stack-item-added', refresh);
    undoManager.on('stack-item-popped', refresh);
    undoManager.on('stack-cleared', refresh);

    board.shareHistory({
        commit: () => {
            pushNow();
            undoManager.stopCapturing();
        },
        undo: () => {
            pushNow();
            undoManager.undo();
        },
        redo: () => {
            pushNow();
            undoManager.redo();
        },
        canUndo,
        canRedo,
    });

    onBeforeUnmount(() => {
        pushNow();
        items.unobserve(heard);
        order.unobserve(heard);
        undoManager.destroy();
    });
}

/**
 * A shared board's items as they change, for reading it without editing: a
 * project's viewer watching others draw.
 */
export function useSharedItems(document: Y.Doc, initial: Item[]) {
    const items = ref<Item[]>(initial);

    const follow = () => (items.value = sharedItems(document));
    const shared = document.getMap('items');
    const order = document.getArray('order');

    shared.observe(follow);
    order.observe(follow);

    onBeforeUnmount(() => {
        shared.unobserve(follow);
        order.unobserve(follow);
    });

    return items;
}

// Someone else's pointer on the board, in board units
export type Pointer = {
    id: number;
    name: string;
    color: string;
    x: number;
    y: number;
};

/**
 * Where everyone else's pointer is on the board, and telling them where yours
 * is -- in board units, so each sees it in the right place at their own zoom.
 */
export function useBoardPointers(
    provider: HocuspocusProvider,
    me: { name: string; color: string },
) {
    const awareness = provider.awareness;
    const pointers = ref<Pointer[]>([]);

    awareness?.setLocalStateField('user', me);

    const follow = () => {
        if (!awareness) {
            return;
        }

        const others: Pointer[] = [];

        awareness.getStates().forEach((state, id) => {
            if (id !== awareness.clientID && state.user && state.pointer) {
                others.push({
                    id,
                    name: state.user.name,
                    color: state.user.color,
                    x: state.pointer.x,
                    y: state.pointer.y,
                });
            }
        });

        pointers.value = others;
    };

    awareness?.on('change', follow);

    let last = 0;

    /** Where yours is now, or null when it has left the board. */
    const point = (at: { x: number; y: number } | null) => {
        const now = performance.now();

        // A few times a second is plenty to follow a hand
        if (at && now - last < 50) {
            return;
        }

        last = now;
        awareness?.setLocalStateField('pointer', at);
    };

    onBeforeUnmount(() => awareness?.off('change', follow));

    return { pointers, point };
}
