<template>
    <!-- Floating table toolbar, pinned above the table the cursor is in -->
    <BubbleMenu
        :editor="editor"
        plugin-key="tableMenu"
        :should-show="shouldShow"
        :get-referenced-virtual-element="getTableReference"
        :options="{ placement: 'top-start', offset: 8, flip: false }"
    >
        <div class="table-menu" @mousedown.prevent>
            <template v-for="(group, groupIndex) in groups" :key="groupIndex">
                <span v-if="groupIndex > 0" class="table-menu-divider" />
                <button
                    v-for="action in group"
                    :key="action.label"
                    type="button"
                    class="table-menu-btn"
                    :class="{ 'is-danger': action.danger }"
                    :title="action.label"
                    :aria-label="action.label"
                    :disabled="!action.enabled()"
                    @click="action.run()"
                >
                    <component :is="action.icon" class="size-4" />
                </button>
            </template>
        </div>
    </BubbleMenu>
</template>

<script setup lang="ts">
import type { Component } from 'vue';
import type { Editor as CoreEditor } from '@tiptap/core';
import type { Editor } from '@tiptap/vue-3';
import { BubbleMenu } from '@tiptap/vue-3/menus';
import {
    BetweenHorizontalEnd,
    BetweenHorizontalStart,
    BetweenVerticalEnd,
    BetweenVerticalStart,
    Columns3,
    PanelTop,
    Rows3,
    TableCellsMerge,
    Trash2,
} from '@lucide/vue';

const props = defineProps<{
    editor: Editor;
}>();

interface TableAction {
    label: string;
    icon: Component;
    enabled: () => boolean;
    run: () => void;
    danger?: boolean;
}

// Each action checks `can()` first so buttons disable themselves when a command would do nothing
const action = (
    label: string,
    icon: Component,
    command: (
        chain: ReturnType<Editor['chain']>,
    ) => ReturnType<Editor['chain']>,
    extra: Partial<TableAction> = {},
): TableAction => ({
    label,
    icon,
    enabled: () => command(props.editor.can().chain().focus()).run(),
    run: () => void command(props.editor.chain().focus()).run(),
    ...extra,
});

const groups: TableAction[][] = [
    [
        action('Insert row above', BetweenHorizontalStart, (chain) =>
            chain.addRowBefore(),
        ),
        action('Insert row below', BetweenHorizontalEnd, (chain) =>
            chain.addRowAfter(),
        ),
        action('Delete row', Rows3, (chain) => chain.deleteRow(), {
            danger: true,
        }),
    ],
    [
        action('Insert column left', BetweenVerticalStart, (chain) =>
            chain.addColumnBefore(),
        ),
        action('Insert column right', BetweenVerticalEnd, (chain) =>
            chain.addColumnAfter(),
        ),
        action('Delete column', Columns3, (chain) => chain.deleteColumn(), {
            danger: true,
        }),
    ],
    [
        action('Toggle header row', PanelTop, (chain) =>
            chain.toggleHeaderRow(),
        ),
        action('Merge or split cells', TableCellsMerge, (chain) =>
            chain.mergeOrSplit(),
        ),
    ],
    [
        action('Delete table', Trash2, (chain) => chain.deleteTable(), {
            danger: true,
        }),
    ],
];

const shouldShow = ({ editor }: { editor: CoreEditor }) =>
    editor.isEditable && editor.isActive('table');

// Anchor the menu to the table's box instead of the text selection
const getTableReference = () => {
    const { view, state } = props.editor;
    const { $from } = state.selection;

    for (let depth = $from.depth; depth > 0; depth--) {
        if ($from.node(depth).type.name === 'table') {
            const dom = view.nodeDOM($from.before(depth));

            if (dom instanceof HTMLElement) {
                return {
                    getBoundingClientRect: () => dom.getBoundingClientRect(),
                };
            }
        }
    }

    return null;
};
</script>

<style scoped>
/* A menu is for the screen, not for the printed note */
@media print {
    .table-menu {
        display: none !important;
    }
}

.table-menu {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 4px;
    background-color: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow:
        0 4px 12px rgba(0, 0, 0, 0.08),
        0 0 0 1px rgba(0, 0, 0, 0.04);
    z-index: 50;
}

.table-menu-divider {
    width: 1px;
    height: 18px;
    margin: 0 4px;
    background-color: var(--border);
}

.table-menu-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: var(--radius-sm);
    color: var(--muted-foreground);
    cursor: pointer;
    transition:
        background-color 100ms ease,
        color 100ms ease;
}
.table-menu-btn:hover:not(:disabled) {
    background-color: var(--muted);
    color: var(--foreground);
}
.table-menu-btn.is-danger:hover:not(:disabled) {
    color: var(--destructive);
}
.table-menu-btn:disabled {
    opacity: 0.35;
    cursor: default;
}
</style>
