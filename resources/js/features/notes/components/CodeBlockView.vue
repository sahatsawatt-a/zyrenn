<template>
    <node-view-wrapper class="code-block-wrapper">
        <div class="code-block-header" contenteditable="false">
            <Select
                :model-value="selectedLanguage"
                @update:model-value="changeLanguage(String($event))"
            >
                <SelectTrigger
                    class="h-7 gap-1 border-0 bg-transparent px-1 text-[13px] font-medium text-[#a0a0a5] shadow-none hover:text-white dark:bg-transparent dark:hover:bg-transparent [&_svg:not([class*='text-'])]:text-[#a0a0a5]"
                    aria-label="Language"
                    size="sm"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="language in LANGUAGES"
                        :key="language.value"
                        :value="language.value"
                    >
                        {{ language.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps(nodeViewProps);
const copied = ref<boolean>(false);

const selectedLanguage = computed(
    () => props.node.attrs.language || 'plaintext',
);

const LANGUAGES = [
    { value: 'plaintext', label: 'Plain Text' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'typescript', label: 'TypeScript' },
    { value: 'html', label: 'HTML / XML' },
    { value: 'css', label: 'CSS' },
    { value: 'php', label: 'PHP' },
    { value: 'python', label: 'Python' },
    { value: 'sql', label: 'SQL Database' },
    { value: 'json', label: 'JSON' },
    { value: 'markdown', label: 'Markdown' },
    { value: 'bash', label: 'Bash / Shell' },
    { value: 'rust', label: 'Rust' },
];

const changeLanguage = (language: string) =>
    props.updateAttributes({ language });

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
