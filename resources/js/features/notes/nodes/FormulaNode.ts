import { InputRule, Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import FormulaChip from '@/features/notes/components/FormulaChip.vue';

// A live value in the text: {{ trip("Shanghai").total_cost }}. Only the
// formula is kept -- in the document, the Markdown and the shared copy -- and
// the chip shows what it comes to now (see features/notes/lib/noteValues).
export const FormulaNode = Node.create({
    name: 'formula',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addAttributes() {
        return {
            expression: {
                default: '',
                parseHTML: (element) =>
                    element.getAttribute('data-formula') ?? '',
                renderHTML: (attributes) => ({
                    'data-formula': attributes.expression,
                }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-formula]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', mergeAttributes(HTMLAttributes)];
    },

    // Copied out as text, it reads as it was written
    renderText({ node }) {
        return `{{ ${node.attrs.expression} }}`;
    },

    addNodeView() {
        return VueNodeViewRenderer(FormulaChip);
    },

    // Typed out in full, {{ … }} becomes a value -- braces and all, which
    // nodeInputRule wouldn't take: it keeps whatever is around the formula
    addInputRules() {
        return [
            new InputRule({
                find: /\{\{\s*([^{}\n]*?[^{}\s])\s*\}\}$/,
                handler: ({ state, range, match }) => {
                    state.tr.replaceWith(
                        range.from,
                        range.to,
                        this.type.create({ expression: match[1] }),
                    );
                },
            }),
        ];
    },
});
