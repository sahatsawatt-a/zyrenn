<template>
    <node-view-wrapper class="mermaid-block">
        <!-- Toolbar: diagram type picker + actions -->
        <div class="mermaid-toolbar" contenteditable="false">
            <select
                class="mermaid-type-select"
                aria-label="Diagram type"
                :value="detected?.id ?? ''"
                @change="onTypeChange"
            >
                <option v-if="!detected" value="" disabled>
                    Custom diagram
                </option>
                <optgroup
                    v-for="group in groups"
                    :key="group.name"
                    :label="group.name"
                >
                    <option
                        v-for="template in group.templates"
                        :key="template.id"
                        :value="template.id"
                    >
                        {{ template.label }}
                    </option>
                </optgroup>
            </select>

            <div class="mermaid-actions">
                <DropdownMenu v-if="isEr" @update:open="onSqlMenu">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="mermaid-btn"
                            title="Turn this diagram into SQL"
                            data-test="mermaid-sql"
                        >
                            SQL
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuLabel>Dialect</DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            :model-value="dialect"
                            @update:model-value="setDialect"
                        >
                            <DropdownMenuRadioItem
                                v-for="item in sqlDialects"
                                :key="item.id"
                                :value="item.id"
                                @select.prevent
                            >
                                {{ item.label }}
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            data-test="mermaid-sql-insert"
                            @select="writeSql"
                        >
                            {{
                                hasSqlBelow
                                    ? 'Update SQL below'
                                    : 'Insert SQL below'
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            data-test="mermaid-sql-copy"
                            @select="copySql"
                        >
                            Copy SQL
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <button
                    v-if="svg && !error"
                    type="button"
                    class="mermaid-btn"
                    title="View full size"
                    @click="expand"
                >
                    Expand
                </button>
                <button type="button" class="mermaid-btn" @click="copySource">
                    {{ copied ? 'Copied!' : 'Copy' }}
                </button>
                <button
                    type="button"
                    class="mermaid-btn"
                    @click="isEditing ? finishEditing() : startEditing()"
                >
                    {{ isEditing ? 'Done' : 'Edit' }}
                </button>
            </div>
        </div>

        <!-- Source: shown while the cursor is inside the block -->
        <pre
            v-show="isEditing"
            class="mermaid-source"
        ><node-view-content as="code" /></pre>

        <!-- Live preview -->
        <div
            class="mermaid-preview"
            :class="{ 'can-expand': svg && !error }"
            contenteditable="false"
            :title="svg && !error ? 'View full size' : undefined"
            @mousedown.prevent.stop
            @click="onPreviewClick"
        >
            <p v-if="!source" class="mermaid-hint">
                Empty diagram — pick a type above or click to write one.
            </p>
            <p v-else-if="rendering && !svg && !error" class="mermaid-hint">
                Rendering…
            </p>
            <pre v-else-if="error" class="mermaid-error">{{ error }}</pre>
            <div v-else class="mermaid-svg" v-html="svg"></div>
        </div>
    </node-view-wrapper>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { NodeViewWrapper, NodeViewContent, nodeViewProps } from '@tiptap/vue-3';
import { Selection } from '@tiptap/pm/state';
import { useDebounceFn, useMutationObserver } from '@vueuse/core';
import { mermaidImage, renderMermaid } from '../../lib/mermaid';
import { deliver } from '../../lib/exporting';
import { canChange } from '../../lib/projects';
import { holdPrint } from '../../lib/printReady';
import { useMediaViewer } from '../../composables/useMediaViewer';
import { copyToClipboard } from '../../lib/utils';
import {
    SQL_HEADER,
    erDiagramToSql,
    isErDiagram,
    sqlDialects,
} from '../../lib/erToSql';
import type { SqlDialect } from '../../lib/erToSql';
import { toast } from 'vue-sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '../ui/dropdown-menu';
import {
    detectMermaidTemplate,
    mermaidTemplates,
} from '../../config/mermaidTemplates';
import type { MermaidTemplate } from '../../config/mermaidTemplates';

const props = defineProps(nodeViewProps);

const isEditing = ref(false);
const svg = ref('');
const error = ref('');
const rendering = ref(false);
const copied = ref(false);

const source = computed(() => props.node.textContent.trim());
const detected = computed(() => detectMermaidTemplate(source.value));

const groups = (['Diagrams', 'Charts'] as const).map((name) => ({
    name,
    templates: mermaidTemplates.filter((template) => template.group === name),
}));

// ------------------------------------------------------------------ Rendering
let renderToken = 0;

const render = async () => {
    const token = ++renderToken;

    if (!source.value) {
        svg.value = '';
        error.value = '';
        return;
    }

    rendering.value = true;
    const result = await renderMermaid(source.value);

    // A newer render started while this one was queued
    if (token !== renderToken) return;

    rendering.value = false;

    if (result.ok) {
        svg.value = result.svg;
        error.value = '';
    } else {
        error.value = result.error;
    }
};

const debouncedRender = useDebounceFn(render, 300);

watch(source, () => void debouncedRender());

// Re-render with the matching theme when light/dark mode flips
useMutationObserver(document.documentElement, () => void render(), {
    attributes: true,
    attributeFilter: ['class'],
});

// The PDF printer waits for the first drawing (see printReady)
const releasePrint = holdPrint();
onBeforeUnmount(releasePrint);

onMounted(() => void render().finally(releasePrint));

// -------------------------------------------------------------- Edit / preview
// The source is visible whenever the cursor is inside this block
const syncEditing = () => {
    const pos = props.getPos();
    if (typeof pos !== 'number') return;

    const { from, to } = props.editor.state.selection;
    isEditing.value = from > pos && to < pos + props.node.nodeSize;
};

props.editor.on('selectionUpdate', syncEditing);
onBeforeUnmount(() => {
    props.editor.off('selectionUpdate', syncEditing);
});

const startEditing = () => {
    const pos = props.getPos();
    if (typeof pos !== 'number') return;

    props.editor
        .chain()
        .focus()
        .setTextSelection(pos + 1 + props.node.content.size)
        .run();
};

const finishEditing = () => {
    const pos = props.getPos();
    if (typeof pos !== 'number') return;

    const after = pos + props.node.nodeSize;
    const { doc } = props.editor.state;

    if (after >= doc.content.size) {
        // Last block in the document: give the cursor somewhere to go
        props.editor
            .chain()
            .insertContentAt(after, { type: 'paragraph' })
            .focus(after + 1)
            .run();
        return;
    }

    const target = Selection.findFrom(doc.resolve(after), 1, true);
    props.editor
        .chain()
        .focus()
        .setTextSelection(target?.from ?? after)
        .run();
};

// ---------------------------------------------------------------------- Image
// A picture of the diagram to keep, offered from the full-size view:
// downloaded, or put in the Drive of wherever the note is -- which a
// project's viewer cannot add to
const canSaveToDrive = canChange();

const saveImage = async (type: 'png' | 'svg', to: 'download' | 'drive') => {
    try {
        const name = `${detected.value?.label ?? 'Diagram'}.${type}`;
        const image = await mermaidImage(source.value, type);

        await deliver(new File([image], name, { type: image.type }), to);
    } catch (err) {
        toast.error(err instanceof Error ? err.message : String(err));
    }
};

const viewer = useMediaViewer();

const expand = () => {
    viewer.open([
        {
            type: 'svg',
            svg: svg.value,
            title: detected.value?.label ?? 'Diagram',
            save: { drive: canSaveToDrive, run: saveImage },
        },
    ]);
};

// A rendered diagram opens full size; editing is the Edit button. With nothing
// to show yet (empty or broken), a click goes to the source instead. The
// preview's mousedown is cancelled: otherwise the browser drops the caret into
// the source, and the editor reveals it before the click lands.
const onPreviewClick = () => {
    if (svg.value && !error.value) expand();
    else if (!isEditing.value) startEditing();
};

// ------------------------------------------------------------------ Templates
const applyTemplate = (template: MermaidTemplate) => {
    const pos = props.getPos();
    if (typeof pos !== 'number') return;

    const { tr, schema } = props.editor.state;
    tr.replaceWith(
        pos + 1,
        pos + 1 + props.node.content.size,
        schema.text(template.source),
    );
    props.editor.view.dispatch(tr);
};

const onTypeChange = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const template = mermaidTemplates.find((item) => item.id === select.value);
    if (!template) return;

    // Only ask before replacing something the user actually wrote
    const isUntouched =
        !source.value ||
        mermaidTemplates.some((item) => item.source.trim() === source.value);

    if (
        !isUntouched &&
        !window.confirm(
            `Replace this diagram with the ${template.label} template?`,
        )
    ) {
        select.value = detected.value?.id ?? '';
        return;
    }

    applyTemplate(template);
};

const copySource = async () => {
    try {
        await copyToClipboard(props.node.textContent);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch (err) {
        console.error('Failed to copy diagram source: ', err);
    }
};

// ------------------------------------------------------------------------ SQL
// An erDiagram can be turned into CREATE TABLE statements. The diagram stays
// the source: running it again rewrites the SQL block it made last time
// (found by its header line) rather than stacking up another.
const isEr = computed(() => isErDiagram(source.value));

const DIALECT_KEY = 'zyrenn.mermaid.sqlDialect';
const readDialect = (): SqlDialect => {
    try {
        const saved = localStorage.getItem(DIALECT_KEY);
        if (sqlDialects.some((item) => item.id === saved)) {
            return saved as SqlDialect;
        }
    } catch {
        // Storage can be blocked; the default is fine
    }
    return 'postgres';
};

const dialect = ref<SqlDialect>(readDialect());
const setDialect = (value: unknown) => {
    dialect.value = value as SqlDialect;
    try {
        localStorage.setItem(DIALECT_KEY, dialect.value);
    } catch {
        // Remembered for this block only
    }
};

const sqlBelow = () => {
    const pos = props.getPos();
    if (typeof pos !== 'number') return null;

    const after = pos + props.node.nodeSize;
    const next = props.editor.state.doc.nodeAt(after);

    // By name: props.node comes through Vue's reactivity, so its type is a
    // proxy that ProseMirror's identity checks would not recognise
    return next?.type.name === props.node.type.name &&
        next.attrs.language === 'sql' &&
        next.textContent.startsWith(SQL_HEADER)
        ? { pos: after, node: next }
        : null;
};

const hasSqlBelow = ref(false);
const onSqlMenu = (open: boolean) => {
    if (open) hasSqlBelow.value = sqlBelow() !== null;
};

const generateSql = (): string | null => {
    try {
        return erDiagramToSql(source.value, dialect.value);
    } catch (err) {
        toast.error(err instanceof Error ? err.message : String(err));
        return null;
    }
};

const writeSql = () => {
    const pos = props.getPos();
    const sql = generateSql();
    if (typeof pos !== 'number' || sql === null) return;

    const { tr, schema } = props.editor.state;
    const existing = sqlBelow();

    if (existing) {
        tr.replaceWith(
            existing.pos + 1,
            existing.pos + 1 + existing.node.content.size,
            schema.text(sql),
        );
    } else {
        tr.insert(
            pos + props.node.nodeSize,
            schema.nodes[props.node.type.name].create(
                { language: 'sql' },
                schema.text(sql),
            ),
        );
    }
    props.editor.view.dispatch(tr);
};

const copySql = async () => {
    const sql = generateSql();
    if (sql === null) return;

    try {
        await copyToClipboard(sql);
        toast.success('SQL copied');
    } catch (err) {
        console.error('Failed to copy SQL: ', err);
    }
};
</script>

<style scoped>
.mermaid-block {
    margin: 1.5rem 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--card);
    overflow: hidden;
}

.mermaid-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 10px;
    border-bottom: 1px solid var(--border);
    background: var(--muted);
    font-family: var(--font-sans);
    user-select: none;
}

.mermaid-type-select {
    background: transparent;
    border: none;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted-foreground);
    cursor: pointer;
    outline: none;
}
.mermaid-type-select:hover {
    color: var(--foreground);
}
.mermaid-type-select option,
.mermaid-type-select optgroup {
    background: var(--card);
    color: var(--foreground);
}

.mermaid-actions {
    display: flex;
    gap: 6px;
}

.mermaid-btn {
    background: var(--background);
    border: 1px solid var(--border);
    font-size: 12px;
    font-weight: 500;
    color: var(--muted-foreground);
    cursor: pointer;
    padding: 3px 10px;
    border-radius: var(--radius-sm);
    transition: all 150ms ease;
}
.mermaid-btn:hover {
    color: var(--foreground);
    background: var(--accent);
}

.mermaid-source {
    margin: 0 !important;
    padding: 1rem 1.25rem !important;
    background: #18181c !important;
    color: #abb2bf;
    overflow-x: auto;
    border-bottom: 1px solid var(--border);
}
.mermaid-source code {
    display: block;
    font-family:
        'Fira Code', ui-monospace, Monaco, Consolas, monospace !important;
    font-size: 14px !important;
    line-height: 1.6 !important;
    background: transparent !important;
    color: inherit !important;
    padding: 0 !important;
    white-space: pre;
}

.mermaid-preview {
    display: flex;
    justify-content: center;
    min-height: 6rem;
    padding: 1.5rem;
    cursor: pointer;
    overflow-x: auto;
}
.mermaid-preview.can-expand {
    cursor: zoom-in;
}

.mermaid-svg {
    width: 100%;
    display: flex;
    justify-content: center;
}
.mermaid-svg :deep(svg) {
    max-width: 100%;
    height: auto;
}
/* Mermaid's labels are paragraphs it measured at its own size; the note's
   paragraph style (.tiptap p) would draw them bigger, past their boxes */
.mermaid-svg :deep(foreignObject p) {
    margin: 0;
    font-size: inherit;
    line-height: inherit;
    color: inherit;
}

.mermaid-hint {
    align-self: center;
    margin: 0;
    font-size: 13px;
    font-style: italic;
    color: var(--muted-foreground);
}

.mermaid-error {
    width: 100%;
    margin: 0;
    padding: 0.75rem;
    font-family: ui-monospace, Monaco, Consolas, monospace;
    font-size: 12px;
    white-space: pre-wrap;
    color: var(--destructive);
    background: color-mix(in oklab, var(--destructive) 8%, transparent);
    border: 1px solid color-mix(in oklab, var(--destructive) 30%, transparent);
    border-radius: var(--radius-sm);
}
</style>
