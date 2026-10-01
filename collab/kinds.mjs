// How each kind of shared document is laid out in Yjs, and read back out.
//
// A kind is named by the first part of a document's name ("notes.k3x9m2p7qa")
// and says three things: how to fill a new document from what Laravel keeps,
// what to hand Laravel to keep, and how to take in a change made elsewhere.
import {
    applyBlockEdits,
    giveBlockIds,
    readDoc,
    replaceDoc,
    writeDoc,
} from './prosemirror.mjs';

/**
 * A note: its body is the editor's XML fragment ("default", where Tiptap's
 * Collaboration extension looks), its title and width a small map beside it.
 */
const notes = {
    seed(document, saved) {
        writeDoc(saved.content, document.getXmlFragment('default'));
        notes.writeMeta(document, saved);
    },

    read(document) {
        const meta = document.getMap('meta');

        // Blocks written before they had ids, or not yet named by an editor,
        // are named here -- in the live copy too, so everyone's agrees
        document.transact(() =>
            giveBlockIds(document.getXmlFragment('default')),
        );

        return {
            content: readDoc(document.getXmlFragment('default')),
            title: meta.get('title') ?? '',
            is_wide: meta.get('is_wide') ?? false,
        };
    },

    replace(document, changed) {
        if ('content' in changed) {
            replaceDoc(changed.content, document.getXmlFragment('default'));
        }

        notes.writeMeta(document, changed);
    },

    /** Block changes by id (NoteBlocks::apply); answers the ids not found. */
    edit(document, edits) {
        return applyBlockEdits(document.getXmlFragment('default'), edits);
    },

    writeMeta(document, values) {
        const meta = document.getMap('meta');

        if ('title' in values) {
            meta.set('title', values.title ?? '');
        }

        if ('is_wide' in values) {
            meta.set('is_wide', !!values.is_wide);
        }
    },
};

/**
 * A board: each item under its id in a map, so two people changing two
 * items never touch the same entry, and the order they are painted in as a
 * list of ids beside it. The last change to one item wins.
 */
const boards = {
    seed(document, saved) {
        boards.replace(document, saved);
    },

    read(document) {
        return {
            items: orderedItems(document),
            title: document.getMap('meta').get('title') ?? '',
        };
    },

    replace(document, changed) {
        if ('items' in changed) {
            const items = document.getMap('items');
            const order = document.getArray('order');
            const given = (changed.items ?? []).filter(
                (item) => item && typeof item.id === 'string',
            );

            items.clear();
            order.delete(0, order.length);

            for (const item of given) {
                items.set(item.id, item);
            }

            order.insert(
                0,
                given.map((item) => item.id),
            );
        }

        if ('title' in changed) {
            document.getMap('meta').set('title', changed.title ?? '');
        }
    },

    /**
     * Changes by item (BoardItems::change): {do: 'set', items} puts each item
     * under its id -- a new one on top -- and {do: 'delete', ids} takes them
     * off. {do: 'order', ids} draws the board in that order; anything drawn
     * meanwhile that it doesn't name stays on top. Nothing else on the board
     * is touched. Answers the ids not found.
     */
    edit(document, edits) {
        const items = document.getMap('items');
        const order = document.getArray('order');
        const missing = [];

        for (const edit of edits) {
            if (edit.do === 'set') {
                for (const item of edit.items ?? []) {
                    const isNew = !items.has(item.id);
                    items.set(item.id, item);

                    if (isNew && !order.toArray().includes(item.id)) {
                        order.push([item.id]);
                    }
                }
            }

            if (edit.do === 'order') {
                const named = (edit.ids ?? []).filter((id) => items.has(id));
                const rest = order
                    .toArray()
                    .filter((id) => !named.includes(id));

                order.delete(0, order.length);
                order.insert(0, [...named, ...rest]);
            }

            if (edit.do === 'delete') {
                for (const id of edit.ids ?? []) {
                    if (!items.has(id)) {
                        missing.push(id);
                        continue;
                    }

                    items.delete(id);
                    const at = order.toArray().indexOf(id);

                    if (at !== -1) {
                        order.delete(at, 1);
                    }
                }
            }
        }

        return missing;
    },
};

/**
 * A board's items in the order they are painted. Two people adding at once
 * can each rewrite the order without the other's new item; an item missing
 * from it still belongs on the board, so it goes on top rather than away.
 *
 * @param {import('yjs').Doc} document
 */
export function orderedItems(document) {
    const items = document.getMap('items');
    const seen = new Set();
    const ordered = [];

    for (const id of document.getArray('order').toArray()) {
        if (items.has(id) && !seen.has(id)) {
            seen.add(id);
            ordered.push(items.get(id));
        }
    }

    for (const [id, item] of items) {
        if (!seen.has(id)) {
            ordered.push(item);
        }
    }

    return ordered;
}

export const documentKinds = { notes, boards };
