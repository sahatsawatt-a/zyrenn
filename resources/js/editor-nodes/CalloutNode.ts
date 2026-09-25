import { Node, mergeAttributes } from '@tiptap/core';
import type { CommandProps } from '@tiptap/core';
import { Selection, TextSelection } from '@tiptap/pm/state';

export const CalloutNode = Node.create({
    name: 'callout',
    group: 'block',
    content: 'block+',
    defining: true,
    isolating: true,

    addAttributes() {
        return {
            icon: {
                default: '💡',
                parseHTML: (element: HTMLElement) =>
                    element.getAttribute('data-icon') ?? '💡',
                renderHTML: (attributes: Record<string, any>) => ({
                    'data-icon': attributes.icon,
                }),
            },
        };
    },

    parseHTML() {
        // Only parse the content wrapper; otherwise the icon <span> text is parsed as an extra paragraph
        return [
            {
                tag: 'div[data-type="callout"]',
                contentElement: '.callout-content',
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-type': 'callout',
                class: 'callout-block',
            }),
            [
                'span',
                { class: 'callout-icon', contenteditable: 'false' },
                node.attrs.icon,
            ],
            ['div', { class: 'callout-content' }, 0],
        ];
    },

    addCommands() {
        return {
            toggleCallout:
                () =>
                ({ chain }: CommandProps) => {
                    return chain().wrapIn(this.name).run();
                },
            // 💡 THE DEFINITIVE BREAKOUT MACRO ENGINE
            escapeCallout:
                () =>
                ({ state, dispatch }) => {
                    const { selection, tr } = state;
                    const { $from } = selection;

                    const currentDepth = $from.depth;
                    const calloutEndPos = $from.after(currentDepth);

                    if (dispatch) {
                        // 1. Instantly spawn a fresh new paragraph below the terminal callout boundary </div>
                        const newParagraph =
                            state.schema.nodes.paragraph.createAndFill();
                        if (!newParagraph) return false;

                        tr.insert(calloutEndPos, newParagraph);

                        // 2. Clear out the empty child paragraph row left inside the callout
                        tr.delete($from.before(), $from.after());

                        // 3. Move document caret state past the code structure walls using ProseMirror node matching
                        const resolvedTargetPos = tr.doc.resolve(
                            tr.mapping.map(calloutEndPos),
                        );
                        const targetSelection = Selection.findFrom(
                            resolvedTargetPos,
                            1,
                            true,
                        );

                        if (targetSelection) {
                            tr.setSelection(targetSelection);
                        } else {
                            // Fallback cursor selection assignment mechanism if search loops clear out early
                            tr.setSelection(
                                TextSelection.create(
                                    tr.doc,
                                    tr.mapping.map(calloutEndPos) + 1,
                                ),
                            );
                        }

                        dispatch(tr);
                        return true;
                    }
                    return false;
                },
        };
    },

    addKeyboardShortcuts() {
        return {
            Enter: () => {
                const { state, view } = this.editor;
                const { selection, tr } = state;
                const { $from } = selection;

                // Only handle paragraphs sitting directly inside a top-level callout
                if ($from.depth !== 2) return false;

                if ($from.parent.content.size === 0) {
                    const calloutPos = $from.before(1);
                    const calloutNode = state.doc.nodeAt(calloutPos);

                    if (calloutNode && calloutNode.type.name === 'callout') {
                        const hasOnlyOneChild = calloutNode.childCount === 1;

                        if (hasOnlyOneChild) {
                            return this.editor.chain().lift('callout').run();
                        }

                        const endOfCalloutPos = $from.after(1);
                        const newParagraph =
                            state.schema.nodes.paragraph.createAndFill();

                        if (newParagraph) {
                            tr.delete($from.before(), $from.after());
                            const adjustedInsertPos =
                                endOfCalloutPos -
                                ($from.after() - $from.before());
                            tr.insert(adjustedInsertPos, newParagraph);
                            tr.setSelection(
                                TextSelection.create(
                                    tr.doc,
                                    adjustedInsertPos + 1,
                                ),
                            );
                            view.dispatch(tr);
                            return true;
                        }
                    }
                }
                return false;
            },

            Backspace: () => {
                const { state, view } = this.editor;
                const { selection, tr } = state;
                const { $from, empty } = selection;

                // Only intercept backspace if the cursor is at the absolute start of the row
                if (!empty || $from.parentOffset !== 0) return false;

                // Only handle paragraphs sitting directly inside a top-level callout
                // (also avoids $from.before(1) throwing for a top-level gap cursor)
                if ($from.depth !== 2) return false;

                const calloutPos = $from.before(1);
                const calloutNode = state.doc.nodeAt(calloutPos);

                if (calloutNode && calloutNode.type.name === 'callout') {
                    const isFirstChild = $from.index(1) === 0;
                    const hasOnlyOneChild = calloutNode.childCount === 1;
                    const isCurrentLineEmpty = $from.parent.content.size === 0;

                    // 💡 SCENARIO A: The callout is completely empty (0 characters)
                    // Wipes the entire callout box off the screen instantly.
                    if (hasOnlyOneChild && isCurrentLineEmpty) {
                        const calloutEnd = $from.after(1);

                        tr.delete(calloutPos, calloutEnd); // Delete the entire <div> layout block

                        // Fallback: If deleting leaves the document completely blank, insert an empty paragraph
                        if (tr.doc.content.size === 0) {
                            const newParagraph =
                                state.schema.nodes.paragraph.createAndFill();
                            if (newParagraph) tr.insert(0, newParagraph);
                        }

                        view.dispatch(tr);
                        return true;
                    }

                    // 💡 SCENARIO B: The callout has text, but user hit backspace at character 0
                    // Transforms the callout box envelope back into a normal text line block seamlessly.
                    if (hasOnlyOneChild && !isCurrentLineEmpty) {
                        return this.editor.chain().lift('callout').run();
                    }

                    // SCENARIO C: The cursor is on an inner paragraph row (line 2, line 3, etc.)
                    if (!isFirstChild) {
                        // Let it lift out of the callout envelope context layout naturally
                        return this.editor.chain().lift('callout').run();
                    }
                }

                return false;
            },

            'Shift-Enter': () => {
                // Scoped to callouts so Shift-Enter keeps inserting a hard break elsewhere
                if (!this.editor.isActive(this.name)) return false;
                return this.editor.chain().splitBlock().run();
            },
        };
    },
});

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        callout: {
            toggleCallout: () => ReturnType;
            escapeCallout: () => ReturnType;
        };
    }
}
