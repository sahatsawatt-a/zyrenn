<template>
    <node-view-wrapper :class="['diagram-block', `${kind.name}-block`]">
        <!-- Toolbar: template picker + actions -->
        <div
            :class="['diagram-toolbar', `${kind.name}-toolbar`]"
            contenteditable="false"
        >
            <Select
                :model-value="detected?.id ?? ''"
                @update:model-value="onTypeChange(String($event))"
            >
                <SelectTrigger
                    class="diagram-type-select text-muted-foreground hover:text-foreground h-7 min-w-0 gap-1 border-0 bg-transparent px-1 text-[13px] font-medium shadow-none dark:bg-transparent dark:hover:bg-transparent"
                    aria-label="Diagram type"
                    size="sm"
                >
                    <SelectValue :placeholder="kind.custom">
                        {{ detected?.label ?? kind.custom }}
                    </SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup v-for="group in kind.groups" :key="group.name">
                        <SelectLabel>{{ group.name }}</SelectLabel>
                        <SelectItem
                            v-for="template in group.templates"
                            :key="template.id"
                            :value="template.id"
                            :data-value="template.id"
                        >
                            {{ template.label }}
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>

            <div class="diagram-actions">
                <slot name="actions" />
                <button
                    v-if="svg && !error"
                    type="button"
                    class="diagram-btn"
                    title="View full size"
                    @click="expand"
                >
                    Expand
                </button>
                <button type="button" class="diagram-btn" @click="copySource">
                    {{ copied ? 'Copied!' : 'Copy' }}
                </button>
                <button
                    type="button"
                    class="diagram-btn"
                    :data-test="`${kind.name}-edit`"
                    @click="isEditing ? finishEditing() : startEditing()"
                >
                    {{ isEditing ? 'Done' : 'Edit' }}
                </button>
            </div>
        </div>

        <!-- Source: shown while the cursor is inside the block -->
        <pre
            v-show="isEditing"
            class="diagram-source"
        ><node-view-content as="code" /></pre>

        <!-- Live preview -->
        <div
            :class="[
                'diagram-preview',
                `${kind.name}-preview`,
                { 'can-expand': svg && !error },
            ]"
            contenteditable="false"
            :title="svg && !error ? 'View full size' : undefined"
            @mousedown.prevent.stop
            @click="onPreviewClick"
        >
            <p v-if="!source" class="diagram-hint">{{ kind.empty }}</p>
            <p v-else-if="rendering && !svg && !error" class="diagram-hint">
                Rendering…
            </p>
            <pre
                v-else-if="error"
                class="diagram-error"
                :data-test="`${kind.name}-error`"
                >{{ error }}</pre>
            <!-- eslint-disable-next-line vue/no-v-html -- drawn by the kind: Mermaid in its strict mode, a circuit from the library's own paths with its words escaped -->
            <div
                v-else
                :class="['diagram-svg', `${kind.name}-svg`]"
                v-html="svg"
            ></div>
        </div>

        <!-- What a reader can change about the drawing, under it -->
        <slot name="controls" />

        <!-- Asked before a template takes the place of something written -->
        <Dialog :open="!!replacing" @update:open="replacing = null">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        >Replace with “{{ replacing?.label }}”?</DialogTitle
                    >
                    <DialogDescription>
                        What is written in this block is replaced by the
                        template.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="replacing = null"
                        >Cancel</Button
                    >
                    <Button
                        data-test="diagram-replace"
                        @click="replacing && applyTemplate(replacing)"
                        >Replace</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </node-view-wrapper>
</template>

<script setup lang="ts">
import type { PropType } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { NodeViewWrapper, NodeViewContent, nodeViewProps } from '@tiptap/vue-3';
import { Selection } from '@tiptap/pm/state';
import { useDebounceFn, useMutationObserver } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useMediaViewer } from '@/composables/useMediaViewer';
import type {
    DiagramKind,
    DiagramTemplate,
} from '@/features/notes/lib/diagramKind';
import { deliver } from '@/lib/exporting';
import { holdPrint } from '@/lib/printReady';
import { canChange } from '@/lib/projects';
import { copyToClipboard } from '@/lib/utils';

// A block drawn from its own text: the source is a code block, shown while
// the cursor is in it, and the drawing is what a reader sees. What kind of
// drawing -- a Mermaid diagram, a circuit -- is the `kind` handed in.
const props = defineProps({
    ...nodeViewProps,
    kind: { type: Object as PropType<DiagramKind>, required: true },
    // Counted up when something other than the source changes what is
    // drawn: the drawing is then made again, at once
    redraw: { type: Number, default: 0 },
});

const isEditing = ref(false);
const svg = ref('');
const error = ref('');
const rendering = ref(false);
const copied = ref(false);

const source = computed(() => props.node.textContent.trim());
const detected = computed(() => props.kind.detect(source.value));

const templates = computed(() =>
    props.kind.groups.flatMap((group) => group.templates),
);

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
    const result = await props.kind.render(source.value);

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
watch(
    () => props.redraw,
    () => void render(),
);

// Re-render with the matching theme when light/dark mode flips
if (props.kind.themed) {
    useMutationObserver(document.documentElement, () => void render(), {
        attributes: true,
        attributeFilter: ['class'],
    });
}

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
        const name = `${detected.value?.label ?? props.kind.title}.${type}`;
        const image = await props.kind.image(source.value, type);

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
            title: detected.value?.label ?? props.kind.title,
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
// The template waiting on a yes, when it would replace something written
const replacing = ref<DiagramTemplate | null>(null);

const applyTemplate = (template: DiagramTemplate) => {
    replacing.value = null;

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

const onTypeChange = (id: string) => {
    const template = templates.value.find((item) => item.id === id);
    if (!template) return;

    // Only ask before replacing something the user actually wrote
    const isUntouched =
        !source.value ||
        templates.value.some((item) => item.source.trim() === source.value);

    if (isUntouched) {
        applyTemplate(template);
        return;
    }

    // The picker keeps saying what the block is until the answer is yes
    replacing.value = template;
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
</script>

<style scoped>
.diagram-block {
    margin: 1.5rem 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--card);
    overflow: hidden;
}

.diagram-toolbar {
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

.diagram-actions {
    display: flex;
    gap: 6px;
}

.diagram-actions :deep(.diagram-btn),
.diagram-btn {
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
.diagram-actions :deep(.diagram-btn:hover),
.diagram-btn:hover {
    color: var(--foreground);
    background: var(--accent);
}

.diagram-source {
    margin: 0 !important;
    padding: 1rem 1.25rem !important;
    background: #18181c !important;
    color: #abb2bf;
    overflow-x: auto;
    border-bottom: 1px solid var(--border);
}
.diagram-source code {
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

.diagram-preview {
    display: flex;
    justify-content: center;
    min-height: 6rem;
    padding: 1.5rem;
    cursor: pointer;
    overflow-x: auto;
}
.diagram-preview.can-expand {
    cursor: zoom-in;
}

.diagram-svg {
    width: 100%;
    display: flex;
    justify-content: center;
}
.diagram-svg :deep(svg) {
    max-width: 100%;
    height: auto;
}
/* Mermaid's labels are paragraphs it measured at its own size; the note's
   paragraph style (.tiptap p) would draw them bigger, past their boxes */
.diagram-svg :deep(foreignObject p) {
    margin: 0;
    font-size: inherit;
    line-height: inherit;
    color: inherit;
}

.diagram-hint {
    align-self: center;
    margin: 0;
    font-size: 13px;
    font-style: italic;
    color: var(--muted-foreground);
}

.diagram-error {
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
