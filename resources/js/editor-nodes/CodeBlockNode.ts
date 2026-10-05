// resources/js/editor-nodes/CodeBlockNode.ts
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import type { VueNodeViewRendererOptions } from '@tiptap/vue-3';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { createLowlight } from 'lowlight';

// 💡 THE UPGRADE: Load extensive programming language sets manually to guarantee tracking accuracy
import javascript from 'highlight.js/lib/languages/javascript';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml'; // HTML wrapper helper
import css from 'highlight.js/lib/languages/css';
import php from 'highlight.js/lib/languages/php';
import python from 'highlight.js/lib/languages/python';
import sql from 'highlight.js/lib/languages/sql';
import json from 'highlight.js/lib/languages/json';
import markdown from 'highlight.js/lib/languages/markdown';
import bash from 'highlight.js/lib/languages/bash';
import rust from 'highlight.js/lib/languages/rust';
import plaintext from 'highlight.js/lib/languages/plaintext';

// Fallback Standard Highlight Code Block Component
import CodeBlockView from '../components/Editor/CodeBlockView.vue';
// Newly created Interactive Graphic Render Canvas Component
import MermaidBlock from '../components/Editor/MermaidBlock.vue';
// A board shown in the note, chosen from two dropdowns
import BoardBlock from '../components/Editor/BoardBlock.vue';
// A trip shown in the note, the whole of it or one day
import TripBlock from '../components/Editor/TripBlock.vue';

const lowlight = createLowlight();

// Register languages into our compiler tracking index
lowlight.register('javascript', javascript);
lowlight.register('typescript', typescript);
lowlight.register('html', xml);
lowlight.register('css', css);
lowlight.register('php', php);
lowlight.register('python', python);
lowlight.register('sql', sql);
lowlight.register('json', json);
lowlight.register('markdown', markdown);
lowlight.register('bash', bash);
lowlight.register('rust', rust);
lowlight.register('plaintext', plaintext);
// Mermaid source has no highlight.js grammar; keep it from being auto-detected as another language
lowlight.register('mermaid', plaintext);
// Nor does a board or a trip reference: it is two lines naming what to show
lowlight.register('board', plaintext);
lowlight.register('trip', plaintext);

export const CodeBlockNode = CodeBlockLowlight.configure({
    lowlight,
    defaultLanguage: 'plaintext',
    HTMLAttributes: {
        class: 'custom-code-block',
    },
}).extend({
    addNodeView() {
        const EMBEDS = ['mermaid', 'board', 'trip'] as const;
        const kindOf = (
            node: ProseMirrorNode,
        ): (typeof EMBEDS)[number] | 'code' =>
            EMBEDS.find((kind) => kind === node.attrs.language) ?? 'code';

        // The view is picked once per node, so returning false when a block
        // changes kind makes ProseMirror rebuild it with the other component
        const update: VueNodeViewRendererOptions['update'] = ({
            oldNode,
            newNode,
            updateProps,
        }) => {
            if (kindOf(oldNode) !== kindOf(newNode)) return false;
            updateProps();
            return true;
        };

        return (props) => {
            const views = {
                mermaid: MermaidBlock,
                board: BoardBlock,
                trip: TripBlock,
                code: CodeBlockView,
            } as const;

            return VueNodeViewRenderer(views[kindOf(props.node)], { update })(
                props,
            );
        };
    },
});
