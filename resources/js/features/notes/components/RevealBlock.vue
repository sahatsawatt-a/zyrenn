<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { NodeViewContent, NodeViewWrapper } from '@tiptap/vue-3';
import type { NodeViewProps } from '@tiptap/vue-3';
import { onBeforeUnmount, ref } from 'vue';

// What a reveal holds stays out of sight until its line is clicked. Open or
// shut is each reader's own and is not kept: it starts shut for everyone,
// whoever last looked.
const props = defineProps<NodeViewProps>();

// A new one, with nothing in it yet, starts open: there to be written in
const open = ref(props.editor.isEditable && props.node.textContent === '');

// The caret has come inside -- by a search, or an arrow key: show where it is
const follow = () => {
    const pos = props.getPos();

    if (typeof pos !== 'number') {
        return;
    }

    const { from, to } = props.editor.state.selection;

    if (from > pos && to < pos + props.node.nodeSize) {
        open.value = true;
    }
};

props.editor.on('selectionUpdate', follow);
onBeforeUnmount(() => props.editor.off('selectionUpdate', follow));

const rename = (event: Event) =>
    props.updateAttributes({
        title: (event.target as HTMLInputElement).value,
    });
</script>

<template>
    <NodeViewWrapper
        class="reveal-block"
        :class="{ 'is-open': open }"
        data-test="reveal"
    >
        <div class="reveal-head" contenteditable="false">
            <button
                type="button"
                class="reveal-toggle"
                :aria-expanded="open"
                :title="open ? 'Hide' : 'Show'"
                data-test="reveal-toggle"
                @click="open = !open"
            >
                <ChevronRight class="reveal-chevron" aria-hidden="true" />
                <span v-if="!editor.isEditable">{{ node.attrs.title }}</span>
            </button>
            <!-- What the line says, typed where it is shown -->
            <input
                v-if="editor.isEditable"
                class="reveal-title"
                type="text"
                maxlength="120"
                placeholder="Answer"
                aria-label="What this line says"
                :value="node.attrs.title"
                data-test="reveal-title"
                @change="rename"
                @keydown.enter.prevent="open = true"
            />
        </div>
        <NodeViewContent v-show="open" class="reveal-body" />
    </NodeViewWrapper>
</template>

<style scoped>
.reveal-block {
    margin: 0.75rem 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background-color: color-mix(in oklab, var(--muted) 45%, transparent);
}

.reveal-head {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.375rem 0.625rem;
    user-select: none;
}

.reveal-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.125rem;
    font-weight: 600;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.reveal-toggle:hover {
    background-color: var(--muted);
}

.reveal-chevron {
    width: 1rem;
    height: 1rem;
    color: var(--muted-foreground);
    transition: transform 120ms ease;
}
.is-open .reveal-chevron {
    transform: rotate(90deg);
}

.reveal-title {
    flex: 1;
    min-width: 0;
    font-weight: 600;
    background: transparent;
    border: none;
    outline: none;
}

.reveal-body {
    padding: 0 0.875rem 0.5rem 1.75rem;
    border-top: 1px solid var(--border);
}

/* On paper there is nothing to click: it is all there to read */
@media print {
    .reveal-body {
        display: block !important;
    }
    .reveal-chevron {
        display: none;
    }
}
</style>
