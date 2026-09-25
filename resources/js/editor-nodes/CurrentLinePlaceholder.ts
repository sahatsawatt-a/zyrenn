import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

export interface CurrentLinePlaceholderOptions {
    placeholder: string;
    // Textblocks that never show the placeholder (e.g. code blocks)
    excludeNodes: string[];
}

/**
 * Shows a placeholder on the empty textblock holding the cursor, at any depth
 * (inside callouts, lists, task items).
 *
 * Replaces @tiptap/extension-placeholder with `includeChildren: true`, whose
 * incremental decoration set (v3.31) never removes the decoration from the
 * line the cursor left, so arrowing up leaves the placeholder on every line passed.
 * Rebuilding the single decoration from the current state avoids that entirely.
 */
export const CurrentLinePlaceholder =
    Extension.create<CurrentLinePlaceholderOptions>({
        name: 'currentLinePlaceholder',

        addOptions() {
            return {
                placeholder: "Type '/' for commands...",
                excludeNodes: ['codeBlock'],
            };
        },

        addProseMirrorPlugins() {
            const { editor, options } = this;

            return [
                new Plugin({
                    key: new PluginKey('currentLinePlaceholder'),
                    props: {
                        decorations: ({ doc, selection }) => {
                            const { $anchor } = selection;
                            const node = $anchor.parent;

                            if (
                                !editor.isEditable ||
                                $anchor.depth === 0 ||
                                !node.isTextblock ||
                                node.content.size > 0 ||
                                options.excludeNodes.includes(node.type.name)
                            ) {
                                return null;
                            }

                            const isEmptyDoc =
                                doc.childCount === 1 && doc.firstChild === node;
                            const pos = $anchor.before();

                            return DecorationSet.create(doc, [
                                Decoration.node(pos, pos + node.nodeSize, {
                                    class: isEmptyDoc
                                        ? 'is-empty is-editor-empty'
                                        : 'is-empty',
                                    'data-placeholder': options.placeholder,
                                }),
                            ]);
                        },
                    },
                }),
            ];
        },
    });
