import type { Editor } from '@tiptap/vue-3';
import { ref, shallowRef } from 'vue';
import type { Ref } from 'vue';
import { useEventListener } from '@vueuse/core';
import { blockAt } from '@/lib/editorBlocks';
import type { Block } from '@/lib/editorBlocks';

/**
 * Follows the mouse over the editor to keep the gutter handle beside the block
 * under it. `paused` holds the current block still (a menu is open, or a block
 * is being dragged); `pinned` keeps the handle up once the mouse leaves.
 */
export function useHoveredBlock(
    editor: () => Editor,
    surface: Ref<HTMLElement | null>,
    container: () => HTMLElement | null,
    { paused, pinned }: { paused: () => boolean; pinned: () => boolean },
) {
    const block = shallowRef<Block | null>(null);
    const visible = ref(false);
    const top = ref(0);

    const place = (target: Block) => {
        const box = container()?.getBoundingClientRect();
        if (!box) return;

        const rect = target.dom.getBoundingClientRect();
        const lineHeight =
            parseFloat(getComputedStyle(target.dom).lineHeight) || 24;
        // Centre the 24px handle on the block's first line
        top.value =
            rect.top -
            box.top +
            Math.max(0, Math.min(lineHeight, rect.height) - 24) / 2;
    };

    useEventListener(surface, 'mousemove', (event: MouseEvent) => {
        if (paused() || !editor().isEditable) return;

        const found = blockAt(editor(), event.clientY);
        if (!found) return;

        block.value = found;
        place(found);
        visible.value = true;
    });

    useEventListener(surface, 'mouseleave', () => {
        if (!pinned()) visible.value = false;
    });

    // Typing hides the handle until the mouse moves again, like Notion
    useEventListener(
        () => editor().view.dom,
        'keydown',
        () => {
            visible.value = false;
        },
    );

    return { block, visible, top };
}
