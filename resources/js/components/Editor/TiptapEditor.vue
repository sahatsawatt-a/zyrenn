<template>
    <div class="editor-surface" :class="{ 'is-wide': wide }">
        <div class="editor-container">
            <!-- Core Interactive Tiptap Writing Surface -->
            <editor-content :editor="editor" />

            <!-- + / drag handle / block menu in the left gutter -->
            <BlockHandle v-if="editor && editable" :editor="editor" />
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

        <!-- /image and /video: upload, pick from Drive, or link -->
        <MediaPickerDialog
            v-model:open="showPicker"
            :kind="pickKind"
            @insert="onMediaPicked"
        />

        <!-- Full-size view of images, videos and diagrams -->
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
import Collaboration, { isChangeOrigin } from '@tiptap/extension-collaboration';
import UniqueID from '@tiptap/extension-unique-id';
import noteBlocks from '../../lib/note-blocks.json';
import CollaborationCaret from '@tiptap/extension-collaboration-caret';
import type { HocuspocusProvider } from '@hocuspocus/provider';
import type * as Y from 'yjs';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import { TableKit } from '@tiptap/extension-table';
import { BlockMath, InlineMath } from '@tiptap/extension-mathematics';

// Decoupled Structural Nodes and Composables
import { CalloutNode } from '../../editor-nodes/CalloutNode';
import { CodeBlockNode } from '../../editor-nodes/CodeBlockNode';
import { CurrentLinePlaceholder } from '../../editor-nodes/CurrentLinePlaceholder';
import { ImageNode } from '../../editor-nodes/ImageNode';
import { VideoNode } from '../../editor-nodes/VideoNode';
import MediaViewer from '../MediaViewer.vue';
import MediaPickerDialog from '../media/MediaPickerDialog.vue';
import type { PickedMedia } from '../media/MediaPickerDialog.vue';
import { isImageFile, isVideoFile, uploadToDrive } from '../../lib/drive';
import type { MediaKind } from '../../lib/drive';
import { markEditorReady } from '../../lib/printReady';
import CustomMenu from './CustomMenu.vue';
import TableMenu from './TableMenu.vue';
import BlockHandle from './BlockHandle.vue';
import MathPopover from './MathPopover.vue';
import type { MathTarget } from './MathPopover.vue';
import { MATH_EDIT_EVENT, MEDIA_PICK_EVENT } from '../../config/commandsConfig';
import { CustomSlash } from '../../composables/useSlashCommands';
import { useMenuRenderer } from '../../composables/useMenuRenderer';
import { commandItems } from '../../config/commandsConfig';

// Decoupled Styling Layers (Tailwind v4 Integration)
import '../../../css/editor.css';
import '../../../css/typography.css';
import 'katex/dist/katex.min.css';

/** A block id, as the app and the collaboration server make them. */
const newBlockId = (): string => {
    let id = '';

    while (id.length < noteBlocks.idLength) {
        id += Math.random().toString(36).slice(2);
    }

    return id.slice(0, noteBlocks.idLength);
};

const props = withDefaults(
    defineProps<{
        // Initial document; only read on mount (key the component to load a different document)
        content?: Content;
        autofocus?: boolean;
        // Use the full page width instead of the 720px reading column
        wide?: boolean;
        // False to only read: a project's viewers
        editable?: boolean;
        // Edited live with others (useShared): the document comes from here, not `content`
        shared?: {
            document: Y.Doc;
            provider: HocuspocusProvider;
            me: { name: string; color: string };
        } | null;
    }>(),
    {
        content: null,
        autofocus: false,
        wide: false,
        editable: true,
        shared: null,
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

const insertMediaAt = (target: Editor, pos: number, media: PickedMedia) => {
    const at = Math.min(pos, target.state.doc.content.size);
    const node =
        media.kind === 'video'
            ? { type: 'video', attrs: { src: media.src, title: media.name } }
            : { type: 'image', attrs: { src: media.src, alt: media.name } };
    target.chain().insertContentAt(at, node).run();
    return target.state.selection.to;
};

// What a pasted or dropped file can become in a note
const isMediaFile = (file: File) => isImageFile(file) || isVideoFile(file);

// Pasted / dropped files: save each to the Drive, then place it where it was pasted or dropped
const uploadMedia = async (files: File[], pos: number) => {
    const target = editor.value;
    if (!target) return;

    const tracker = trackPosition(target, pos);
    const loading = toast.loading(
        files.length > 1 ? `Uploading ${files.length} files…` : 'Uploading…',
    );

    try {
        for (const file of files) {
            try {
                // A video can take a while; say how far along it is
                const stored = await uploadToDrive(file, (fraction) =>
                    toast.loading(
                        `Uploading ${file.name}… ${Math.round(fraction * 100)}%`,
                        { id: loading },
                    ),
                );
                if (target.isDestroyed) return;
                tracker.pos = insertMediaAt(target, tracker.pos, {
                    kind: isVideoFile(file) ? 'video' : 'image',
                    src: stored.url,
                    name: stored.name,
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

// /image and /video open the picker; its choice goes where the command was typed
const showPicker = ref(false);
const pickKind = ref<MediaKind>('image');
let pickPos = 0;

const onMediaPicked = (picked: PickedMedia[]) => {
    const target = editor.value;
    if (!target) return;

    let pos = pickPos;
    for (const media of picked) pos = insertMediaAt(target, pos, media);
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
            // Shared, undo is the collaboration's own: it takes back only your edits
            ...(props.shared ? { undoRedo: false as const } : {}),
        }),

        // Every block carries a short id of its own, so one can be read or
        // changed alone -- over MCP, say (App\Support\NoteBlocks). A block
        // someone else wrote arrives with theirs, so only this editor's own
        // changes are given ids here.
        UniqueID.configure({
            types: noteBlocks.types,
            attributeName: noteBlocks.attribute,
            generateID: newBlockId,
            filterTransaction: (transaction) => !isChangeOrigin(transaction),
        }),

        // Shared: one document for everyone, and where each of the others is typing
        ...(props.shared
            ? [
                  Collaboration.configure({ document: props.shared.document }),
                  CollaborationCaret.configure({
                      provider: props.shared.provider,
                      user: props.shared.me,
                  }),
              ]
            : []),

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
            onClick: (_node, pos) =>
                props.editable && editMath({ type: 'inline', pos }),
        }),
        BlockMath.configure({
            katexOptions: { displayMode: true },
            onClick: (_node, pos) =>
                props.editable && editMath({ type: 'block', pos }),
        }),

        // Images and videos from the Drive or a link (see ImageNode, VideoNode)
        ImageNode,
        VideoNode,

        // Notion-style placeholder on the current empty line (see CurrentLinePlaceholder for why not Placeholder)
        CurrentLinePlaceholder,

        // Slash commands pipeline hook injection
        CustomSlash(suggestionRenderOptions as any, ({ query }) => {
            searchQuery.value = query;
            return filteredItems.value;
        }),
    ],
    content: props.shared ? undefined : props.content,
    editable: props.editable,
    autofocus: props.autofocus ? 'end' : false,
    editorProps: {
        handlePaste: (view, event) => {
            const files = Array.from(event.clipboardData?.files ?? []).filter(
                isMediaFile,
            );
            if (!files.length) return false;

            void uploadMedia(files, view.state.selection.from);
            return true;
        },
        handleDrop: (view, event, _slice, moved) => {
            // Moving a block inside the editor is ProseMirror's job
            if (moved) return false;

            const files = Array.from(event.dataTransfer?.files ?? []).filter(
                isMediaFile,
            );
            if (!files.length) return false;

            const drop = view.posAtCoords({
                left: event.clientX,
                top: event.clientY,
            });
            void uploadMedia(files, drop?.pos ?? view.state.selection.from);
            return true;
        },
    },
    onCreate: () => {
        markEditorReady();
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

// The /image and /video slash commands ask for the picker
useEventListener(
    () => editor.value?.view.dom,
    MEDIA_PICK_EVENT,
    (event: Event) => {
        pickKind.value = (event as CustomEvent<MediaKind>).detail;
        pickPos = editor.value?.state.selection.from ?? 0;
        showPicker.value = true;
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
