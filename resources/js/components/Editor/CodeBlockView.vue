<template>
    <node-view-wrapper class="code-block-wrapper">
        <div class="code-block-header" contenteditable="false">
            <select :value="selectedLanguage" @change="changeLanguage">
                <option value="plaintext">Plain Text</option>
                <option value="javascript">JavaScript</option>
                <option value="typescript">TypeScript</option>
                <option value="html">HTML / XML</option>
                <option value="css">CSS</option>
                <option value="php">PHP</option>
                <option value="python">Python</option>
                <option value="sql">SQL Database</option>
                <option value="json">JSON</option>
                <option value="markdown">Markdown</option>
                <option value="bash">Bash / Shell</option>
                <option value="rust">Rust</option>
                <option value="mermaid">Mermaid Diagram</option>
            </select>

            <button class="copy-btn" @click="copyCode">
                {{ copied ? 'Copied!' : 'Copy' }}
            </button>
        </div>

        <pre><code class="hljs"><node-view-content /></code></pre>
    </node-view-wrapper>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { NodeViewWrapper, NodeViewContent, nodeViewProps } from '@tiptap/vue-3';
import { copyToClipboard } from '../../lib/utils';

const props = defineProps(nodeViewProps);
const copied = ref<boolean>(false);

const selectedLanguage = computed(
    () => props.node.attrs.language || 'plaintext',
);

const changeLanguage = (event: Event) => {
    const target = event.target as HTMLSelectElement;
    props.updateAttributes({ language: target.value });
};

const copyCode = async () => {
    const textContent = props.node.textContent;
    try {
        await copyToClipboard(textContent);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch (err) {
        console.error('Failed to copy code: ', err);
    }
};
</script>

<style scoped>
.code-block-wrapper {
    position: relative;
    margin: 1.5rem 0;
    /* 💡 THE UPGRADE: Switch background to a custom dark obsidian palette independent of global text tokens */
    background: #18181c;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.code-block-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 16px;
    background: #111114;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    font-family: var(--font-sans);
    user-select: none;
}

select {
    background: transparent;
    border: none;
    font-size: 13px;
    color: #a0a0a5;
    cursor: pointer;
    outline: none;
    font-weight: 500;
}
select:hover {
    color: #ffffff;
}
select option {
    background: #111114;
    color: #a0a0a5;
}

.copy-btn {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 12px;
    color: #a0a0a5;
    cursor: pointer;
    padding: 4px 10px;
    border-radius: var(--radius-sm);
    font-weight: 500;
    transition: all 150ms ease;
}
.copy-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
    border-color: rgba(255, 255, 255, 0.15);
}

pre {
    margin: 0 !important;
    padding: 1.25rem !important;
    background: transparent !important;
    overflow-x: auto;
}

code {
    font-family:
        'Fira Code', ui-monospace, Monaco, Consolas, monospace !important;
    font-size: 14px !important;
    line-height: 1.6 !important;
    display: block;
}
</style>
