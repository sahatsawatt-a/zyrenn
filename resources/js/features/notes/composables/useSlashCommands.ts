import { Extension, Range, Editor } from '@tiptap/core';
import Suggestion, { SuggestionOptions } from '@tiptap/suggestion';

export interface CommandItemProps {
    editor: Editor;
    range: Range;
}

export interface SlashCommandItem {
    title: string;
    description: string;
    icon: string;
    // Extra search terms matched by the slash menu filter
    keywords?: string[];
    command: (props: CommandItemProps) => void;
}

export const CustomSlash = (
    renderCallbacks: () => Omit<
        SuggestionOptions<SlashCommandItem>,
        'editor' | 'char'
    >['render'],
    itemFilterCallback: (props: {
        query: string;
        editor: Editor;
    }) => SlashCommandItem[],
) => {
    return Extension.create({
        name: 'customSlash',
        addProseMirrorPlugins() {
            return [
                Suggestion({
                    editor: this.editor,
                    char: '/',
                    // Anywhere after a space (or at line start), so inline items like math work mid-sentence;
                    // "and/or" or URLs don't trigger it
                    startOfLine: false,
                    allowedPrefixes: [' '],
                    command: ({ editor, range, props }) => {
                        // Execution context proxy logic
                        (props as any).command({ editor, range });
                    },
                    items: itemFilterCallback,
                    render: renderCallbacks as any,
                }),
            ];
        },
    });
};
