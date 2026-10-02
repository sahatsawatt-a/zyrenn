import { Node, mergeAttributes } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { PDF_HIDDEN } from './PdfHidden';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        pageBreak: {
            /** A page break where the cursor is, with a line after it to carry on writing. */
            setPageBreak: () => ReturnType;
        };
    }
}

/**
 * Where the printed note starts a new page: a labelled dashed line on screen,
 * nothing but the break on paper (print.css). Written in Markdown as
 * "<!-- pagebreak -->" (App\Support\Markdown).
 *
 * A break with nothing to print after it -- before the next break, or at the
 * end -- would only print a blank page, so it is marked `is-idle` and skipped.
 */
export const PageBreak = Node.create({
    name: 'pageBreak',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    parseHTML() {
        return [{ tag: 'div[data-type="page-break"]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-type': 'page-break',
                class: 'page-break',
                contenteditable: 'false',
            }),
        ];
    },

    addProseMirrorPlugins() {
        // What takes room on paper: not a break, a hidden block or an empty line
        const prints = (node: ProseMirrorNode) =>
            node.type.name !== this.name &&
            !node.attrs[PDF_HIDDEN] &&
            !(node.type.name === 'paragraph' && node.content.size === 0);

        const idle = (doc: ProseMirrorNode) => {
            const decorations: Decoration[] = [];
            let pending: { pos: number; size: number } | null = null;

            const settle = (printsAfter: boolean) => {
                if (pending && !printsAfter) {
                    decorations.push(
                        Decoration.node(
                            pending.pos,
                            pending.pos + pending.size,
                            { class: 'is-idle' },
                        ),
                    );
                }
                pending = null;
            };

            doc.forEach((node, pos) => {
                if (node.type.name === this.name) {
                    // Another break before anything printed: the first is idle
                    settle(false);
                    if (!node.attrs[PDF_HIDDEN]) {
                        pending = { pos, size: node.nodeSize };
                    }
                } else if (prints(node)) {
                    settle(true);
                }
            });
            settle(false);

            return DecorationSet.create(doc, decorations);
        };

        const key = new PluginKey<DecorationSet>('pageBreakIdle');

        return [
            new Plugin({
                key,
                state: {
                    init: (_, { doc }) => idle(doc),
                    apply: (tr, set) => (tr.docChanged ? idle(tr.doc) : set),
                },
                props: {
                    decorations: (state) => key.getState(state),
                },
            }),
        ];
    },

    addCommands() {
        return {
            setPageBreak:
                () =>
                ({ commands }) =>
                    commands.insertContent([
                        { type: this.name },
                        { type: 'paragraph' },
                    ]),
        };
    },
});
