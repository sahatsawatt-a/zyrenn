import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import RevealBlock from '@/features/notes/components/RevealBlock.vue';

// Something kept out of sight until it is asked for: the answers under a
// set of questions, a hint, a spoiler. It has a line that is always shown --
// "Answers" -- and whatever blocks it holds, which a click on that line shows
// and hides. In Markdown it is ":::reveal Answers" … ":::", as a callout is.
export const RevealNode = Node.create({
    name: 'reveal',
    group: 'block',
    content: 'block+',
    defining: true,

    addAttributes() {
        return {
            title: {
                default: 'Answer',
                parseHTML: (element: HTMLElement) =>
                    element.getAttribute('data-title') ?? 'Answer',
                renderHTML: (attributes: Record<string, string>) => ({
                    'data-title': attributes.title,
                }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-type="reveal"]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, { 'data-type': 'reveal' }),
            0,
        ];
    },

    addNodeView() {
        return VueNodeViewRenderer(RevealBlock);
    },
});
