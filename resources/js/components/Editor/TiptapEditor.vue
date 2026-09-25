<template>
    <div class="editor-surface" :class="{ 'is-wide': wide }">
        <div class="editor-container">
            <!-- Core Interactive Tiptap Writing Surface -->
            <editor-content :editor="editor" />

            <!-- + / drag handle / block menu in the left gutter -->
            <BlockHandle v-if="editor" :editor="editor" />
        </div>

        <!-- Floating Slash Command Menu (teleported so page coordinates aren't offset by a positioned layout ancestor) -->
        <Teleport to="body">
            <CustomMenu
                v-if="showMenu"
                :items="filteredItems"
                :selected-index="selectedIndex"
                :style="menuStyle"
                @select="executeCommand"
            />
        </Teleport>

        <!-- Row / column controls while the cursor is inside a table -->
        <TableMenu v-if="editor" :editor="editor" />

        <!-- LaTeX editor for inline / block math -->
        <MathPopover v-if="editor" ref="mathPopover" :editor="editor" />

        <!-- /image: upload, pick from Drive, or link -->
        <ImagePickerDialog
            v-model:open="showImagePicker"
            @insert="onImagesPicked"
        />

        <!-- Full-size view of images and diagrams -->
        <MediaViewer />
    </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref, useTemplateRef } from 'vue';
import type { Editor } from '@tiptap/core';
import { toast } from 'vue-sonner';
import { useEventListener } from '@vueuse/core';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import type { Content, JSONContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import { TableKit } from '@tiptap/extension-table';
import { BlockMath, InlineMath } from '@tiptap/extension-mathematics';

// Decoupled Structural Nodes and Composables
import { CalloutNode } from '../../editor-nodes/CalloutNode';
import { CodeBlockNode } from '../../editor-nodes/CodeBlockNode';
import { CurrentLinePlaceholder } from '../../editor-nodes/CurrentLinePlaceholder';
import { ImageNode } from '../../editor-nodes/ImageNode';
import MediaViewer from '../MediaViewer.vue';
import ImagePickerDialog from './ImagePickerDialog.vue';
import type { PickedImage } from './ImagePickerDialog.vue';
import { isImageFile, uploadToDrive } from '../../lib/drive';
import CustomMenu from './CustomMenu.vue';
import TableMenu from './TableMenu.vue';
import BlockHandle from './BlockHandle.vue';
import MathPopover from './MathPopover.vue';
import type { MathTarget } from './MathPopover.vue';
import { IMAGE_PICK_EVENT, MATH_EDIT_EVENT } from '../../config/commandsConfig';
import { CustomSlash } from '../../composables/useSlashCommands';
import { useMenuRenderer } from '../../composables/useMenuRenderer';
import { commandItems } from '../../config/commandsConfig';

// Decoupled Styling Layers (Tailwind v4 Integration)
import '../../../css/editor.css';
import '../../../css/typography.css';
import 'katex/dist/katex.min.css';

const props = withDefaults(
    defineProps<{
        // Initial document; only read on mount (key the component to load a different document)
        content?: Content;
        autofocus?: boolean;
        // Use the full page width instead of the 720px reading column
        wide?: boolean;
    }>(),
    {
        content: null,
        autofocus: false,
        wide: false,
    },
);

const emit = defineEmits<{
    (e: 'update', content: JSONContent): void;
}>();

const mathPopover = useTemplateRef('mathPopover');
const editMath = (target: MathTarget) => mathPopover.value?.open(target);

// Follow a document position through edits made while something async (an upload) runs
const trackPosition = (target: Editor, pos: number) => {
    const tracker = {
        pos,
        stop: () => target.off('transaction', follow),
    };
    const follow = ({
        transaction,
    }: {
        transaction: { mapping: { map: (pos: number) => number } };
    }) => {
        tracker.pos = transaction.mapping.map(tracker.pos);
    };
    target.on('transaction', follow);
    return tracker;
};

const insertImageAt = (target: Editor, pos: number, image: PickedImage) => {
    const at = Math.min(pos, target.state.doc.content.size);
    target.chain().insertContentAt(at, { type: 'image', attrs: image }).run();
    return target.state.selection.to;
};

// Pasted / dropped files: save each to the Drive, then place it where it was pasted or dropped
const uploadImages = async (files: File[], pos: number) => {
    const target = editor.value;
    if (!target) return;

    const tracker = trackPosition(target, pos);
    const loading = toast.loading(
        files.length > 1
            ? `Uploading ${files.length} images…`
            : 'Uploading image…',
    );

    try {
        for (const file of files) {
            try {
                const stored = await uploadToDrive(file);
                if (target.isDestroyed) return;
                tracker.pos = insertImageAt(target, tracker.pos, {
                    src: stored.url,
                    alt: stored.name,
                });
            } catch (error) {
                toast.error((error as Error).message);
            }
        }
    } finally {
        tracker.stop();
        toast.dismiss(loading);
    }
};

// /image opens the picker; its choice goes where the command was typed
const showImagePicker = ref(false);
let pickPos = 0;

const onImagesPicked = (images: PickedImage[]) => {
    const target = editor.value;
    if (!target) return;

    let pos = pickPos;
    for (const image of images) pos = insertImageAt(target, pos, image);
    target.commands.focus();
};

// Initialize the reactive menu overlay and scroll-tracking mechanics
const {
    showMenu,
    selectedIndex,
    menuStyle,
    filteredItems,
    executeCommand,
    suggestionRenderOptions,
    searchQuery,
} = useMenuRenderer(commandItems);

// Initialize Core Tiptap Instance with all verified plugins
const editor = useEditor({
    extensions: [
        StarterKit.configure({
            blockquote: {},
            // 💡 Turn off standard code blocks to prevent layout collision with our interactive NodeView
            codeBlock: false,
        }),

        // Custom isolated structural Callout and CodeBlock view modules
        CalloutNode,
        CodeBlockNode,

        // Task List configurations
        TaskList,
        TaskItem.configure({
            nested: true,
            HTMLAttributes: {
                class: 'task-item-element',
            },
        }),

        // Tables (Tab / Shift-Tab move between cells; drag column edges to resize)
        TableKit.configure({
            table: {
                resizable: true,
                HTMLAttributes: { class: 'tiptap-table' },
            },
        }),

        // LaTeX math via KaTeX: $$x^2$$ inline, a line of $$$…$$$ for a block; click a formula to edit it
        // (registered separately so block equations render in KaTeX display mode)
        InlineMath.configure({
            onClick: (_node, pos) => editMath({ type: 'inline', pos }),
        }),
        BlockMath.configure({
            katexOptions: { displayMode: true },
            onClick: (_node, pos) => editMath({ type: 'block', pos }),
        }),

        // Images from the Drive or a link (see ImageNode)
        ImageNode,

        // Notion-style placeholder on the current empty line (see CurrentLinePlaceholder for why not Placeholder)
        CurrentLinePlaceholder,

        // Slash commands pipeline hook injection
        CustomSlash(suggestionRenderOptions as any, ({ query }) => {
            searchQuery.value = query;
            return filteredItems.value;
        }),
    ],
    content: props.content,
    autofocus: props.autofocus ? 'end' : false,
    editorProps: {
        handlePaste: (view, event) => {
            const files = Array.from(event.clipboardData?.files ?? []).filter(
                isImageFile,
            );
            if (!files.length) return false;

            void uploadImages(files, view.state.selection.from);
            return true;
        },
        handleDrop: (view, event, _slice, moved) => {
            // Moving a block inside the editor is ProseMirror's job
            if (moved) return false;

            const files = Array.from(event.dataTransfer?.files ?? []).filter(
                isImageFile,
            );
            if (!files.length) return false;

            const drop = view.posAtCoords({
                left: event.clientX,
                top: event.clientY,
            });
            void uploadImages(files, drop?.pos ?? view.state.selection.from);
            return true;
        },
    },
    onUpdate: ({ editor }) => {
        emit('update', editor.getJSON());
    },
});

// Slash commands insert a formula and ask for it to be opened for editing
useEventListener(
    () => editor.value?.view.dom,
    MATH_EDIT_EVENT,
    (event: Event) => editMath((event as CustomEvent<MathTarget>).detail),
);

// The /image slash command asks for the file picker
useEventListener(
    () => editor.value?.view.dom,
    IMAGE_PICK_EVENT,
    () => {
        pickPos = editor.value?.state.selection.from ?? 0;
        showImagePicker.value = true;
    },
);

defineExpose({
    focus: () => {
        // commands.focus() defers DOM focus to the next frame, so keystrokes typed right away would be lost
        editor.value?.view.focus();
        editor.value?.commands.focus('start');
    },
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>
