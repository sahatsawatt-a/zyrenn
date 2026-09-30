// How each kind of shared document is laid out in Yjs, and read back out.
//
// A kind is named by the first part of a document's name ("notes.k3x9m2p7qa")
// and says three things: how to fill a new document from what Laravel keeps,
// what to hand Laravel to keep, and how to take in a change made elsewhere.
import { readDoc, replaceDoc, writeDoc } from './prosemirror.mjs';

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

export const documentKinds = { notes };
