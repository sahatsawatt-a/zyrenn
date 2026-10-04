<template>
    <!-- Notion-style gutter controls for the top-level block under the mouse -->
    <div
        ref="rootRef"
        v-show="menuOpen || (block && visible)"
        class="block-handle"
        :style="{ top: `${top}px` }"
        contenteditable="false"
    >
        <button
            type="button"
            class="block-handle-btn"
            title="Add a block below"
            aria-label="Add a block below"
            data-test="block-add"
            @mousedown.prevent
            @click="addBelow"
        >
            <Plus class="size-4" />
        </button>
        <button
            ref="gripRef"
            type="button"
            class="block-handle-btn is-grip"
            title="Drag to move · click for options"
            aria-label="Drag to move, click for options"
            aria-haspopup="menu"
            data-test="block-grip"
            draggable="true"
            @click="toggleMenu"
            @dragstart="startDrag"
            @dragend="endDrag"
        >
            <GripVertical class="size-4" />
        </button>
    </div>

    <!-- Where a dragged block will land -->
    <div
        v-if="dropLine !== null"
        class="block-drop-line"
        :style="{ top: `${dropLine}px` }"
    />

    <BlockMenu
        v-if="menuOpen && block"
        :editor="editor"
        :block="block"
        :anchor="gripRef"
        @close="menuOpen = false"
        @removed="closeAndHide"
    />
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { Editor } from '@tiptap/vue-3';
import { GripVertical, Plus } from '@lucide/vue';
import BlockMenu from './BlockMenu.vue';
import { useBlockDrag } from '@/composables/useBlockDrag';
import { useHoveredBlock } from '@/composables/useHoveredBlock';
import { freshBlock } from '@/lib/editorBlocks';

const props = defineProps<{
    editor: Editor;
}>();

const menuOpen = ref(false);
const gripRef = ref<HTMLElement | null>(null);
const rootRef = ref<HTMLElement | null>(null);

// Resolved from this component's own element once mounted: the editor view isn't
// attached to the page yet when this component is set up
const surface = ref<HTMLElement | null>(null);
const container = () => rootRef.value?.parentElement ?? null;

onMounted(() => {
    surface.value = rootRef.value?.closest('.editor-surface') ?? null;
});

const getEditor = () => props.editor;

const { block, visible, top } = useHoveredBlock(getEditor, surface, container, {
    paused: () => menuOpen.value || dragging.value,
    pinned: () => menuOpen.value,
});

const { dragging, dropLine, onDragStart, onDragEnd } = useBlockDrag(
    getEditor,
    surface,
    container,
    () => block.value,
);

const startDrag = (event: DragEvent) => {
    menuOpen.value = false;
    onDragStart(event);
};

const endDrag = () => {
    onDragEnd();
    visible.value = false;
};

const closeAndHide = () => {
    menuOpen.value = false;
    visible.value = false;
};

// ------------------------------------------------------------------ Add
const addBelow = () => {
    const target = freshBlock(props.editor, block.value);
    if (!target) return;

    const { node, pos } = target;
    const isEmptyParagraph =
        node.type.name === 'paragraph' && node.content.size === 0;

    // Reuse an empty line, otherwise open a new one below; then start a slash command
    const chain = props.editor.chain().focus();
    if (isEmptyParagraph) {
        chain.setTextSelection(pos + 1);
    } else {
        chain
            .insertContentAt(pos + node.nodeSize, { type: 'paragraph' })
            .setTextSelection(pos + node.nodeSize + 1);
    }
    chain.insertContent('/').run();

    visible.value = false;
};

// ------------------------------------------------------------------ Menu
const toggleMenu = () => {
    if (dragging.value) return;

    if (menuOpen.value) {
        menuOpen.value = false;
        return;
    }

    const target = freshBlock(props.editor, block.value);
    if (!target) return;

    block.value = target;
    menuOpen.value = true;
};
</script>

<style scoped>
.block-handle {
    position: absolute;
    left: -52px;
    z-index: 10;
    display: flex;
    gap: 2px;
}

.block-handle-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 24px;
    border-radius: var(--radius-sm);
    color: var(--muted-foreground);
    opacity: 0.7;
    cursor: pointer;
    transition:
        background-color 100ms ease,
        opacity 100ms ease;
}
.block-handle-btn:hover {
    background-color: var(--muted);
    opacity: 1;
}
.block-handle-btn.is-grip {
    cursor: grab;
}
.block-handle-btn.is-grip:active {
    cursor: grabbing;
}

.block-drop-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 3px;
    margin-top: -1.5px;
    border-radius: 2px;
    background-color: var(--primary);
    opacity: 0.6;
    pointer-events: none;
    z-index: 10;
}

/* Touch screens have no hover; the gutter would only get in the way */
@media (hover: none) {
    .block-handle {
        display: none;
    }
}
</style>
