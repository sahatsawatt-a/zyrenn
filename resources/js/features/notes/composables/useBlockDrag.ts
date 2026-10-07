import type { Editor } from '@tiptap/vue-3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import { NodeSelection } from '@tiptap/pm/state';
import { useEventListener } from '@vueuse/core';
import { blockAt, freshBlock } from '@/features/notes/lib/editorBlocks';
import type { Block } from '@/features/notes/lib/editorBlocks';

/**
 * Drag a top-level block by the gutter grip to move it.
 *
 * Block moves are handled here instead of by ProseMirror's drop logic, which picks
 * before/after from the pointer's *horizontal* position in the text. Here the
 * top/bottom half of the hovered block decides, blocks stay top-level (never
 * dropped into a list), and our own line shows exactly where it will land.
 */
export function useBlockDrag(
    editor: () => Editor,
    surface: Ref<HTMLElement | null>,
    container: () => HTMLElement | null,
    hovered: () => Block | null,
) {
    const dragging = ref(false);
    const dropLine = ref<number | null>(null);

    let dragged: Block | null = null;
    let dropAt: number | null = null;

    const onDragStart = (event: DragEvent) => {
        const target = freshBlock(editor(), hovered());
        if (!target || !event.dataTransfer) return;

        const { view } = editor();
        dragging.value = true;
        dragged = target;

        // Highlight the block being moved; the drag data lets it be dropped elsewhere as HTML
        view.dispatch(
            view.state.tr.setSelection(
                NodeSelection.create(view.state.doc, target.pos),
            ),
        );
        const { dom, text } = view.serializeForClipboard(
            view.state.selection.content(),
        );

        event.dataTransfer.clearData();
        event.dataTransfer.setData('text/html', dom.innerHTML);
        event.dataTransfer.setData('text/plain', text);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setDragImage(target.dom, 0, 0);
    };

    const onDragOver = (event: DragEvent) => {
        if (!dragged) return;

        // Capture phase + stopPropagation keeps ProseMirror's drop cursor out of it
        event.preventDefault();
        event.stopPropagation();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

        const over = blockAt(editor(), event.clientY);
        const box = container()?.getBoundingClientRect();
        if (!over || !box) return;

        const rect = over.dom.getBoundingClientRect();
        const before = event.clientY < rect.top + rect.height / 2;

        dropAt = before ? over.pos : over.pos + over.node.nodeSize;
        dropLine.value = (before ? rect.top : rect.bottom) - box.top;
    };

    const onDrop = (event: DragEvent) => {
        if (!dragged) return;

        event.preventDefault();
        event.stopPropagation();

        const source = freshBlock(editor(), dragged) ?? dragged;
        const insertAt = dropAt;
        finishDrag();

        const { view } = editor();
        const from = source.pos;
        const to = from + source.node.nodeSize;

        // Dropping onto its own position (or right after itself) is a no-op
        if (insertAt === null || (insertAt >= from && insertAt <= to)) return;

        const tr = view.state.tr.delete(from, to);
        const target = tr.mapping.map(insertAt);
        tr.insert(target, source.node);
        tr.setSelection(NodeSelection.create(tr.doc, target));
        view.dispatch(tr.scrollIntoView());
        view.focus();
    };

    const finishDrag = () => {
        dragging.value = false;
        dragged = null;
        dropAt = null;
        dropLine.value = null;
    };

    useEventListener(surface, 'dragover', onDragOver, { capture: true });
    useEventListener(surface, 'drop', onDrop, { capture: true });
    useEventListener(surface, 'dragleave', (event: DragEvent) => {
        if (
            dragged &&
            !surface.value?.contains(
                event.relatedTarget as globalThis.Node | null,
            )
        )
            dropLine.value = null;
    });

    return { dragging, dropLine, onDragStart, onDragEnd: finishDrag };
}
