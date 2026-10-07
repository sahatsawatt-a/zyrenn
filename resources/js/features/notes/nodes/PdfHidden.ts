import { Extension } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import noteBlocks from '@/features/notes/lib/note-blocks.json';

/** The block attribute that leaves a block out of the PDF. */
export const PDF_HIDDEN = 'pdfHidden';

/** Whether the block can be left out of the PDF at all. */
export const canHideInPdf = (node: ProseMirrorNode): boolean =>
    PDF_HIDDEN in (node.type.spec.attrs ?? {});

/**
 * Blocks kept in the note but left out when it is printed: the PDF export
 * and Ctrl+P alike (print.css hides `[data-pdf-hidden]`).
 *
 * The mark is a decoration rather than only the rendered attribute, so it
 * reaches the blocks drawn by a view of their own -- code, diagrams, boards,
 * pictures -- whose outer element Tiptap doesn't render attributes onto.
 */
export const PdfHidden = Extension.create({
    name: 'pdfHidden',

    addGlobalAttributes() {
        return [
            {
                types: noteBlocks.types,
                attributes: {
                    [PDF_HIDDEN]: {
                        default: false,
                        // Enter in a hidden block carries on hidden
                        keepOnSplit: true,
                        parseHTML: (element) =>
                            element.getAttribute('data-pdf-hidden') === 'true',
                        renderHTML: (attributes) =>
                            attributes[PDF_HIDDEN]
                                ? { 'data-pdf-hidden': 'true' }
                                : {},
                    },
                },
            },
        ];
    },

    addProseMirrorPlugins() {
        const marked = (doc: ProseMirrorNode) => {
            const decorations: Decoration[] = [];

            doc.descendants((node, pos) => {
                if (node.attrs[PDF_HIDDEN]) {
                    decorations.push(
                        Decoration.node(pos, pos + node.nodeSize, {
                            class: 'is-pdf-hidden',
                            'data-pdf-hidden': 'true',
                        }),
                    );

                    // Everything inside goes with it
                    return false;
                }
            });

            return DecorationSet.create(doc, decorations);
        };

        const key = new PluginKey<DecorationSet>('pdfHidden');

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
