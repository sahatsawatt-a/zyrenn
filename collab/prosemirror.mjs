// A note's document in both of its forms: the Tiptap JSON Laravel keeps (and
// search, MCP and Markdown read), and the Yjs XML the editors share live.
//
// Written the way the editor's own binding (@tiptap/y-tiptap) writes it -- a
// node is an XmlElement with its non-null attributes, a run of text is one
// XmlText with each mark as a formatting attribute -- so a document seeded
// here is the one an editor would have made. Reading back is y-tiptap's own.
import * as Y from 'yjs';
import { yXmlFragmentToProsemirrorJSON } from '@tiptap/y-tiptap';
import blocks from '../resources/js/features/notes/lib/note-blocks.json' with { type: 'json' };

/**
 * Fills an empty fragment with a Tiptap document ({type: 'doc', content}).
 *
 * @param {{content?: object[]}|null} doc
 * @param {Y.XmlFragment} fragment
 */
export function writeDoc(doc, fragment) {
    const children = toYChildren(doc?.content ?? []);

    if (children.length) {
        fragment.insert(0, children);
    }
}

/**
 * Swaps what a fragment holds for another document, in one change.
 *
 * @param {object|null} doc
 * @param {Y.XmlFragment} fragment
 */
export function replaceDoc(doc, fragment) {
    if (fragment.length) {
        fragment.delete(0, fragment.length);
    }

    writeDoc(doc, fragment);
}

/**
 * The Tiptap document a fragment holds.
 *
 * @param {Y.XmlFragment} fragment
 */
export function readDoc(fragment) {
    return yXmlFragmentToProsemirrorJSON(fragment);
}

/**
 * Gives every block that should carry an id (resources/js/features/notes/lib/note-blocks.json)
 * one it doesn't share: blocks written before ids, or by an editor that
 * hasn't named them yet. Answers whether it gave any.
 *
 * @param {Y.XmlFragment} fragment
 */
export function giveBlockIds(fragment) {
    const seen = new Set();
    let gave = false;

    const visit = (element) => {
        for (const child of element.toArray()) {
            if (!(child instanceof Y.XmlElement)) {
                continue;
            }

            if (blocks.types.includes(child.nodeName)) {
                let id = child.getAttribute(blocks.attribute);

                if (typeof id !== 'string' || id === '' || seen.has(id)) {
                    do {
                        id = newBlockId();
                    } while (seen.has(id));

                    child.setAttribute(blocks.attribute, id);
                    gave = true;
                }

                seen.add(id);
            }

            visit(child);
        }
    };

    visit(fragment);

    return gave;
}

/** A new block id, the way the editor makes them. */
export function newBlockId() {
    let id = '';

    while (id.length < blocks.idLength) {
        id += Math.random().toString(36).slice(2);
    }

    return id.slice(0, blocks.idLength);
}

/**
 * Makes block changes in a live note, each naming blocks by id (see
 * NoteBlocks::apply in the app, which works them out):
 *
 *   {do: 'replace', id, nodes}   {do: 'delete', id}
 *   {do: 'insert', after|before: id, nodes}   {do: 'insert', at: 'start'|'end', nodes}
 *
 * Only the blocks named change, so whoever is typing elsewhere in the note
 * carries on undisturbed. Answers with the ids it couldn't find -- deleted
 * live in the moment between -- whose changes are left out.
 *
 * @param {Y.XmlFragment} fragment
 * @param {object[]} edits
 */
export function applyBlockEdits(fragment, edits) {
    const missing = [];

    for (const edit of edits) {
        if (edit.do === 'insert' && edit.at) {
            fragment.insert(
                edit.at === 'end' ? fragment.length : 0,
                toYChildren(edit.nodes ?? []),
            );
            continue;
        }

        const id = edit.do === 'insert' ? (edit.after ?? edit.before) : edit.id;
        const found = findBlock(fragment, id);

        if (!found) {
            missing.push(id);
            continue;
        }

        const { parent, index } = found;

        if (edit.do === 'delete' || edit.do === 'replace') {
            parent.delete(index, 1);
        }

        if (edit.do === 'replace') {
            parent.insert(index, toYChildren(edit.nodes ?? []));
        }

        if (edit.do === 'insert') {
            parent.insert(
                edit.after ? index + 1 : index,
                toYChildren(edit.nodes ?? []),
            );
        }
    }

    return missing;
}

/**
 * Where the block with this id is, at any depth.
 *
 * @param {Y.XmlFragment|Y.XmlElement} parent
 * @param {string} id
 * @returns {{parent: Y.XmlFragment|Y.XmlElement, index: number}|null}
 */
function findBlock(parent, id) {
    const children = parent.toArray();

    for (let index = 0; index < children.length; index++) {
        const child = children[index];

        if (!(child instanceof Y.XmlElement)) {
            continue;
        }

        if (child.getAttribute(blocks.attribute) === id) {
            return { parent, index };
        }

        const below = findBlock(child, id);

        if (below) {
            return below;
        }
    }

    return null;
}

/**
 * Nodes as Yjs types; neighbouring text nodes share one XmlText.
 *
 * @param {object[]} nodes
 * @returns {Array<Y.XmlElement|Y.XmlText>}
 */
function toYChildren(nodes) {
    const children = [];
    let text = [];

    const flushText = () => {
        if (text.length) {
            const type = new Y.XmlText();
            type.applyDelta(
                text.map((node) => ({
                    insert: node.text ?? '',
                    attributes: marksOf(node),
                })),
            );
            children.push(type);
            text = [];
        }
    };

    for (const node of nodes) {
        if (node.type === 'text') {
            text.push(node);
            continue;
        }

        flushText();

        const element = new Y.XmlElement(node.type);

        for (const [key, value] of Object.entries(node.attrs ?? {})) {
            if (value !== null && value !== undefined) {
                element.setAttribute(key, value);
            }
        }

        const inner = toYChildren(node.content ?? []);

        if (inner.length) {
            element.insert(0, inner);
        }

        children.push(element);
    }

    flushText();

    return children;
}

/**
 * A text node's marks as formatting attributes: {bold: {}, link: {href}}.
 *
 * @param {{marks?: {type: string, attrs?: object}[]}} node
 */
function marksOf(node) {
    const attributes = {};

    for (const mark of node.marks ?? []) {
        attributes[mark.type] = mark.attrs ?? {};
    }

    return attributes;
}
