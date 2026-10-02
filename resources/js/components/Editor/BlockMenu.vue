<template>
    <!-- Block options: turn into / duplicate / page break / leave out of the PDF / delete -->
    <Teleport to="body">
        <div ref="menuRef" class="block-menu" :style="menuStyle" role="menu">
            <template v-if="canTurnInto">
                <div class="block-menu-label">Turn into</div>
                <button
                    v-for="option in turnIntoOptions"
                    :key="option.label"
                    type="button"
                    role="menuitem"
                    class="block-menu-item"
                    @click="turnInto(option)"
                >
                    <span class="block-menu-icon">{{ option.icon }}</span>
                    <span class="flex-1">{{ option.label }}</span>
                    <Check
                        v-if="option.isCurrent(block.node)"
                        class="size-3.5 opacity-70"
                    />
                </button>
                <div class="block-menu-divider" />
            </template>
            <button
                type="button"
                role="menuitem"
                class="block-menu-item"
                @click="duplicate"
            >
                <Copy class="block-menu-lucide" />
                <span>Duplicate</span>
            </button>
            <button
                v-if="block.node.type.name !== 'pageBreak'"
                type="button"
                role="menuitem"
                class="block-menu-item"
                data-test="block-page-break"
                @click="pageBreakBelow"
            >
                <SeparatorHorizontal class="block-menu-lucide" />
                <span>Page break below</span>
            </button>
            <button
                v-if="canHide"
                type="button"
                role="menuitem"
                class="block-menu-item"
                data-test="block-pdf-toggle"
                @click="togglePdf"
            >
                <Eye v-if="hiddenInPdf" class="block-menu-lucide" />
                <EyeOff v-else class="block-menu-lucide" />
                <span>{{ hiddenInPdf ? 'Show in PDF' : 'Hide in PDF' }}</span>
            </button>
            <button
                type="button"
                role="menuitem"
                class="block-menu-item is-danger"
                @click="remove"
            >
                <Trash2 class="block-menu-lucide" />
                <span>Delete</span>
            </button>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import type { CSSProperties } from 'vue';
import type { Editor } from '@tiptap/vue-3';
import type { ChainedCommands } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Selection, TextSelection } from '@tiptap/pm/state';
import { onClickOutside, useEventListener } from '@vueuse/core';
import {
    Check,
    Copy,
    Eye,
    EyeOff,
    SeparatorHorizontal,
    Trash2,
} from '@lucide/vue';
import { PDF_HIDDEN, canHideInPdf } from '@/editor-nodes/PdfHidden';
import { freshBlock } from '@/lib/editorBlocks';
import type { Block } from '@/lib/editorBlocks';

const props = defineProps<{
    editor: Editor;
    block: Block;
    // The grip the menu hangs from, and the one click that shouldn't close it
    anchor: HTMLElement | null;
}>();

const emit = defineEmits<{ close: []; removed: [] }>();

const menuRef = ref<HTMLElement | null>(null);
const menuStyle = ref<CSSProperties>({});

// ------------------------------------------------------------------ Position
const positionMenu = () => {
    const anchor = props.anchor?.getBoundingClientRect();
    const height = menuRef.value?.offsetHeight ?? 0;
    if (!anchor) return;

    const edge = 8;
    const below = anchor.bottom + 4;
    const fitsBelow = below + height <= window.innerHeight - edge;

    menuStyle.value = {
        position: 'fixed',
        left: `${Math.max(edge, Math.min(anchor.left, window.innerWidth - 240 - edge))}px`,
        top: `${Math.max(edge, fitsBelow ? below : anchor.top - height - 4)}px`,
    };
};

onMounted(() => nextTick(positionMenu));

const anchorRef = computed(() => props.anchor);

onClickOutside(menuRef, () => emit('close'), { ignore: [anchorRef] });

// Focus usually stays on the page, not the menu, so listen for Esc globally
useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
    }
});
useEventListener(window, 'scroll', positionMenu, {
    capture: true,
    passive: true,
});

// ------------------------------------------------------------------ Turn into
interface TurnIntoOption {
    label: string;
    icon: string;
    apply: (chain: ChainedCommands) => ChainedCommands;
    isCurrent: (node: ProseMirrorNode) => boolean;
}

const is =
    (type: string, attrs: Record<string, unknown> = {}) =>
    (node: ProseMirrorNode) =>
        node.type.name === type &&
        Object.entries(attrs).every(
            ([key, value]) => node.attrs[key] === value,
        );

const turnIntoOptions: TurnIntoOption[] = [
    // clearNodes (run first) already turns everything into paragraphs
    { label: 'Text', icon: '📄', apply: (c) => c, isCurrent: is('paragraph') },
    {
        label: 'Heading 1',
        icon: 'H1',
        apply: (c) => c.setHeading({ level: 1 }),
        isCurrent: is('heading', { level: 1 }),
    },
    {
        label: 'Heading 2',
        icon: 'H2',
        apply: (c) => c.setHeading({ level: 2 }),
        isCurrent: is('heading', { level: 2 }),
    },
    {
        label: 'Heading 3',
        icon: 'H3',
        apply: (c) => c.setHeading({ level: 3 }),
        isCurrent: is('heading', { level: 3 }),
    },
    {
        label: 'Bullet list',
        icon: '•',
        apply: (c) => c.toggleBulletList(),
        isCurrent: is('bulletList'),
    },
    {
        label: 'Numbered list',
        icon: '1.',
        apply: (c) => c.toggleOrderedList(),
        isCurrent: is('orderedList'),
    },
    {
        label: 'To-do list',
        icon: '☑️',
        apply: (c) => c.toggleTaskList(),
        isCurrent: is('taskList'),
    },
    {
        label: 'Quote',
        icon: '”',
        apply: (c) => c.toggleBlockquote(),
        isCurrent: is('blockquote'),
    },
    {
        label: 'Code',
        icon: '‹›',
        apply: (c) => c.toggleCodeBlock(),
        isCurrent: (node) =>
            is('codeBlock')(node) && node.attrs.language !== 'mermaid',
    },
    {
        label: 'Callout',
        icon: '💡',
        apply: (c) => c.wrapIn('callout'),
        isCurrent: is('callout'),
    },
];

// Only text-like blocks convert; tables, diagrams, equations and rules can't
const canTurnInto = computed(() => {
    const { node } = props.block;
    if (node.type.name === 'codeBlock')
        return node.attrs.language !== 'mermaid';

    return [
        'paragraph',
        'heading',
        'bulletList',
        'orderedList',
        'taskList',
        'blockquote',
        'callout',
    ].includes(node.type.name);
});

// ------------------------------------------------------------------ Actions
const current = () => freshBlock(props.editor, props.block);

const turnInto = (option: TurnIntoOption) => {
    const target = current();
    emit('close');
    if (!target) return;

    const { pos, node } = target;
    const { doc } = props.editor.state;
    // Select from the block's first to last text position (pos + 1 may sit between a
    // list and its first item), unwrap to plain paragraphs, then apply the new type
    const from = Selection.near(doc.resolve(pos + 1)).from;
    const to = Selection.near(doc.resolve(pos + node.nodeSize - 1), -1).to;
    const chain = props.editor
        .chain()
        .focus()
        .setTextSelection({ from, to })
        .clearNodes();

    option.apply(chain).run();
};

const duplicate = () => {
    const target = current();
    emit('close');
    if (!target) return;

    const { view } = props.editor;
    const after = target.pos + target.node.nodeSize;
    const tr = view.state.tr.insert(
        after,
        target.node.copy(target.node.content),
    );
    view.dispatch(
        tr
            .setSelection(TextSelection.near(tr.doc.resolve(after + 1)))
            .scrollIntoView(),
    );
    view.focus();
};

// The printed note carries on on a new page after this block (see PageBreak)
const pageBreakBelow = () => {
    const target = current();
    emit('close');
    if (!target) return;

    const { view } = props.editor;
    view.dispatch(
        view.state.tr
            .insert(
                target.pos + target.node.nodeSize,
                view.state.schema.nodes.pageBreak.create(),
            )
            .scrollIntoView(),
    );
    view.focus();
};

// Kept in the note, left out when it is printed (see PdfHidden)
const canHide = computed(() => canHideInPdf(props.block.node));
const hiddenInPdf = computed(() => Boolean(props.block.node.attrs[PDF_HIDDEN]));

const togglePdf = () => {
    const target = current();
    emit('close');
    if (!target) return;

    const { view } = props.editor;
    view.dispatch(
        view.state.tr.setNodeAttribute(
            target.pos,
            PDF_HIDDEN,
            !target.node.attrs[PDF_HIDDEN],
        ),
    );
    view.focus();
};

const remove = () => {
    const target = current();
    emit('removed');
    if (!target) return;

    props.editor
        .chain()
        .focus()
        .deleteRange({
            from: target.pos,
            to: target.pos + target.node.nodeSize,
        })
        .run();
};
</script>

<style scoped>
.block-menu {
    z-index: 60;
    width: 240px;
    max-height: min(480px, calc(100vh - 16px));
    overflow-y: auto;
    padding: 6px;
    background-color: var(--card);
    color: var(--foreground);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow:
        0 8px 24px rgba(0, 0, 0, 0.12),
        0 0 0 1px rgba(0, 0, 0, 0.04);
    font-family: var(--font-sans);
}

.block-menu-label {
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.block-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 5px 8px;
    font-size: 14px;
    text-align: left;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.block-menu-item:hover,
.block-menu-item:focus-visible {
    background-color: var(--muted);
    outline: none;
}
.block-menu-item.is-danger:hover {
    color: var(--destructive);
}

.block-menu-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    font-size: 12px;
    font-weight: 700;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background-color: var(--background);
    flex-shrink: 0;
}

.block-menu-lucide {
    width: 16px;
    height: 16px;
    margin: 0 3px;
    color: var(--muted-foreground);
}

.block-menu-divider {
    height: 1px;
    margin: 6px 0;
    background-color: var(--border);
}
</style>
