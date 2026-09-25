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

export const CodeBlockNode = CodeBlockLowlight.configure({
    lowlight,
    defaultLanguage: 'plaintext',
    HTMLAttributes: {
        class: 'custom-code-block',
    },
}).extend({
    addNodeView() {
        const isMermaid = (node: ProseMirrorNode) =>
            node.attrs.language === 'mermaid';

        // The view is picked once per node, so returning false when a block switches
        // to/from mermaid makes ProseMirror rebuild it with the other component
        const update: VueNodeViewRendererOptions['update'] = ({
            oldNode,
            newNode,
            updateProps,
        }) => {
            if (isMermaid(oldNode) !== isMermaid(newNode)) return false;
            updateProps();
            return true;
        };

        return (props) => {
            // 💡 Intercept block mapping if user explicitly toggles it to a flow/sequence scheme
            if (isMermaid(props.node)) {
                return VueNodeViewRenderer(MermaidBlock, { update })(props);
            }

            // Fall back directly to your standard highlight.js interface structure
            return VueNodeViewRenderer(CodeBlockView, { update })(props);
        };
    },
});
