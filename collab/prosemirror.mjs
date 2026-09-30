// A note's document in both of its forms: the Tiptap JSON Laravel keeps (and
// search, MCP and Markdown read), and the Yjs XML the editors share live.
//
// Written the way the editor's own binding (@tiptap/y-tiptap) writes it -- a
// node is an XmlElement with its non-null attributes, a run of text is one
// XmlText with each mark as a formatting attribute -- so a document seeded
// here is the one an editor would have made. Reading back is y-tiptap's own.
import * as Y from 'yjs';
import { yXmlFragmentToProsemirrorJSON } from '@tiptap/y-tiptap';

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
