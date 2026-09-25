import { ref, shallowRef } from 'vue';
import type { FolderItem } from '@/composables/useFolderDialogs';

// Marks our own drags, so they're never mistaken for files dropped from the desktop
const MIME = 'application/x-zyrenn-item';

/**
 * Drag items of a folder view onto folders to move them. Spread
 * `dragProps(item)` on what can be dragged and `dropProps(destination)` on
 * what can be dropped on; `destination` is a folder ref_id, or null for the
 * top level.
 */
export function useDragMove<Kind extends string>(
    move: (item: FolderItem<Kind>, destination: string | null) => void,
) {
    const dragging = shallowRef<FolderItem<Kind> | null>(null);
    // The destination under the pointer; undefined when there is none
    const over = ref<string | null | undefined>(undefined);

    const reset = () => {
        dragging.value = null;
        over.value = undefined;
    };

    const dragProps = (item: FolderItem<Kind>) => ({
        draggable: true,
        onDragstart: (event: DragEvent) => {
            dragging.value = item;
            event.dataTransfer?.setData(MIME, item.ref_id);
            if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
        },
        onDragend: reset,
    });

    // A folder can't be dropped onto itself (the server also refuses its descendants)
    const canDrop = (destination: string | null) =>
        !!dragging.value &&
        !(
            dragging.value.kind === 'folder' &&
            dragging.value.ref_id === destination
        );

    const onOver = (destination: string | null) => (event: DragEvent) => {
        if (!canDrop(destination)) return;

        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        over.value = destination;
    };

    const dropProps = (destination: string | null) => ({
        onDragenter: onOver(destination),
        onDragover: onOver(destination),
        onDragleave: (event: DragEvent) => {
            const target = event.currentTarget as HTMLElement;

            if (!target.contains(event.relatedTarget as Node | null)) {
                over.value = undefined;
            }
        },
        onDrop: (event: DragEvent) => {
            const item = dragging.value;

            if (!item || !canDrop(destination)) return;

            event.preventDefault();
            reset();
            move(item, destination);
        },
    });

    const isOver = (destination: string | null) =>
        !!dragging.value && over.value === destination;

    const isDragging = (item: { ref_id: string }) =>
        dragging.value?.ref_id === item.ref_id;

    return { dragging, dragProps, dropProps, isOver, isDragging };
}
