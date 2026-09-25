<template>
    <!-- LaTeX editor for a math node, anchored under the formula being edited -->
    <Teleport to="body">
        <div
            v-if="target"
            ref="panelRef"
            class="math-popover"
            :style="position"
            role="dialog"
            aria-label="Edit math"
        >
            <div class="math-popover-header">
                <span
                    >{{
                        target.type === 'block'
                            ? 'Block equation'
                            : 'Inline math'
                    }}
                    · LaTeX</span
                >
                <a
                    href="https://katex.org/docs/supported"
                    target="_blank"
                    rel="noopener"
                    class="math-popover-help"
                >
                    Syntax
                </a>
            </div>

            <textarea
                ref="inputRef"
                v-model="latex"
                class="math-popover-input"
                :rows="target.type === 'block' ? 4 : 2"
                spellcheck="false"
                aria-label="LaTeX source"
                @keydown.enter.exact.prevent="apply"
                @keydown.esc.prevent="close"
            />

            <div
                class="math-popover-preview"
                :class="{ 'is-block': target.type === 'block' }"
            >
                <p v-if="!latex.trim()" class="math-popover-hint">
                    Empty — applying will remove this formula.
                </p>
                <p v-else-if="preview.error" class="math-popover-error">
                    {{ preview.error }}
                </p>
                <div v-else v-html="preview.html" />
            </div>

            <div class="math-popover-actions">
                <button
                    type="button"
                    class="math-popover-btn is-danger"
                    @click="remove"
                >
                    Delete
                </button>
                <span class="math-popover-keys"
                    >Enter to apply · Shift+Enter new line · Esc</span
                >
                <button
                    type="button"
                    class="math-popover-btn is-primary"
                    @click="apply"
                >
                    Apply
                </button>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import type { CSSProperties } from 'vue';
import type { Editor } from '@tiptap/vue-3';
import katex from 'katex';
import { onClickOutside, useEventListener } from '@vueuse/core';

export interface MathTarget {
    type: 'inline' | 'block';
    pos: number;
}

const props = defineProps<{
    editor: Editor;
}>();

const target = ref<MathTarget | null>(null);
const latex = ref('');
const position = ref<CSSProperties>({
    position: 'fixed',
    visibility: 'hidden',
});
const panelRef = ref<HTMLElement | null>(null);
const inputRef = ref<HTMLTextAreaElement | null>(null);

const preview = computed(() => {
    try {
        return {
            html: katex.renderToString(latex.value, {
                displayMode: target.value?.type === 'block',
                throwOnError: true,
            }),
            error: '',
        };
    } catch (error) {
        return {
            html: '',
            error: error instanceof Error ? error.message : String(error),
        };
    }
});

const nodeName = (type: MathTarget['type']) =>
    type === 'block' ? 'blockMath' : 'inlineMath';

// Opens the editor for the math node at `pos` (called from the node's click handler)
const open = (next: MathTarget) => {
    const node = props.editor.state.doc.nodeAt(next.pos);
    if (!node || node.type.name !== nodeName(next.type)) return;

    target.value = next;
    latex.value = node.attrs.latex ?? '';

    // A formula inserted at the bottom of the screen may start out of view
    const dom = props.editor.view.nodeDOM(next.pos);
    if (dom instanceof HTMLElement) dom.scrollIntoView({ block: 'nearest' });

    nextTick(() => {
        updatePosition();
        inputRef.value?.select();
    });
};

const GAP = 8;
const EDGE = 16;

// Fixed (viewport) positioning so the popover never stretches the page: below the
// formula when it fits, otherwise above it
const updatePosition = () => {
    if (!target.value) return;

    const dom = props.editor.view.nodeDOM(target.value.pos);
    if (!(dom instanceof HTMLElement)) return;

    const rect = dom.getBoundingClientRect();
    const width = Math.min(420, window.innerWidth - EDGE * 2);
    const height = panelRef.value?.offsetHeight ?? 0;
    const left = Math.min(
        Math.max(EDGE, rect.left),
        window.innerWidth - width - EDGE,
    );
    const fitsBelow = rect.bottom + GAP + height <= window.innerHeight - EDGE;
    const top =
        fitsBelow || rect.top - GAP - height < EDGE
            ? Math.min(rect.bottom + GAP, window.innerHeight - height - EDGE)
            : rect.top - GAP - height;

    position.value = {
        position: 'fixed',
        top: `${Math.max(EDGE, top)}px`,
        left: `${left}px`,
        width: `${width}px`,
    };
};

// The preview grows and shrinks while typing, which changes where the popover fits
watch(latex, () => nextTick(updatePosition));

// Stay attached to the formula while the page or a container scrolls
useEventListener(window, 'scroll', updatePosition, {
    capture: true,
    passive: true,
});
useEventListener(window, 'resize', updatePosition);

const close = () => {
    const pos = target.value?.pos;
    target.value = null;

    // Put the cursor back just after the formula
    if (typeof pos === 'number') {
        const node = props.editor.state.doc.nodeAt(pos);
        props.editor
            .chain()
            .focus()
            .setTextSelection(pos + (node?.nodeSize ?? 0))
            .run();
    }
};

const remove = () => {
    if (!target.value) return;

    const { type, pos } = target.value;
    target.value = null;

    props.editor
        .chain()
        .focus()
        [type === 'block' ? 'deleteBlockMath' : 'deleteInlineMath']({ pos })
        .run();
};

const apply = () => {
    if (!target.value) return;

    if (!latex.value.trim()) {
        remove();
        return;
    }

    const { type, pos } = target.value;
    const value = latex.value.trim();

    if (type === 'block') {
        props.editor.commands.updateBlockMath({ pos, latex: value });
    } else {
        props.editor.commands.updateInlineMath({ pos, latex: value });
    }

    close();
};

onClickOutside(panelRef, () => {
    if (target.value) close();
});

// The node may be deleted or moved by other edits while the popover is open
watch(
    () => props.editor.state.doc,
    () => {
        if (!target.value) return;

        const node = props.editor.state.doc.nodeAt(target.value.pos);
        if (!node || node.type.name !== nodeName(target.value.type))
            target.value = null;
    },
);

defineExpose({ open });
</script>

<style scoped>
.math-popover {
    z-index: 60;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px;
    background-color: var(--card);
    color: var(--foreground);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow:
        0 8px 24px rgba(0, 0, 0, 0.12),
        0 0 0 1px rgba(0, 0, 0, 0.04);
    font-family: var(--font-sans);
}

.math-popover-header {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--muted-foreground);
}

.math-popover-help {
    color: var(--muted-foreground);
    text-decoration: underline;
}

.math-popover-input {
    width: 100%;
    resize: vertical;
    padding: 8px 10px;
    font-family: 'Fira Code', ui-monospace, Monaco, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
    background-color: var(--background);
    color: var(--foreground);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    outline: none;
}
.math-popover-input:focus {
    border-color: var(--ring);
}

.math-popover-preview {
    min-height: 2.5rem;
    max-height: 12rem;
    overflow: auto;
    padding: 8px 10px;
    background-color: var(--muted);
    border-radius: var(--radius-sm);
    font-size: 15px;
}
.math-popover-preview.is-block {
    text-align: center;
}

.math-popover-hint,
.math-popover-error {
    margin: 0;
    font-size: 12px;
}
.math-popover-hint {
    font-style: italic;
    color: var(--muted-foreground);
}
.math-popover-error {
    font-family: ui-monospace, Monaco, Consolas, monospace;
    color: var(--destructive);
    white-space: pre-wrap;
}

.math-popover-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.math-popover-keys {
    flex: 1;
    font-size: 11px;
    color: var(--muted-foreground);
    text-align: center;
}

.math-popover-btn {
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 500;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--background);
    color: var(--foreground);
    cursor: pointer;
}
.math-popover-btn.is-primary {
    background: var(--primary);
    border-color: var(--primary);
    color: var(--primary-foreground);
}
.math-popover-btn.is-danger:hover {
    color: var(--destructive);
}

@media (max-width: 480px) {
    .math-popover-keys {
        display: none;
    }
    .math-popover-actions {
        justify-content: space-between;
    }
}
</style>
