import { Extension } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * Each code block's id on the element it is drawn as, `data-block-id`, so the
 * renderer can find a diagram and take its picture: a note exported as
 * Markdown carries its circuits, graphs and boards as pictures
 * (App\Support\Note\NoteExport). A decoration, as in PdfHidden, because these
 * blocks are drawn by views of their own, which Tiptap renders no attributes
 * onto.
 */
export const BlockAnchors = Extension.create({
    name: 'blockAnchors',

    addProseMirrorPlugins() {
        const marked = (doc: ProseMirrorNode) => {
            const decorations: Decoration[] = [];

            doc.descendants((node, pos) => {
                if (node.type.name === 'codeBlock' && node.attrs.id) {
                    decorations.push(
                        Decoration.node(pos, pos + node.nodeSize, {
                            'data-block-id': String(node.attrs.id),
                        }),
                    );

                    return false;
                }
            });

            return DecorationSet.create(doc, decorations);
        };

        const key = new PluginKey<DecorationSet>('blockAnchors');

        return [
            new Plugin({
                key,
                state: {
                    init: (_, { doc }) => marked(doc),
                    apply: (tr, set) => (tr.docChanged ? marked(tr.doc) : set),
                },
                props: {
                    decorations: (state) => key.getState(state),
                },
            }),
        ];
    },
});
