import { ref } from 'vue';

/**
 * Drop files from the desktop anywhere on an element: spread `dropZoneProps`
 * on it. Only real files count, so dragging items within the page (useDragMove)
 * never shows the drop overlay.
 *
 * Not VueUse's useDropZone: it sets `dropEffect` on every drag over the zone,
 * which would override the `move` effect of the folders inside it.
 */
export function useFileDrop(onDrop: (files: File[]) => void) {
    const droppingFiles = ref(false);

    // dragenter / dragleave fire for every child the pointer crosses
    let depth = 0;

    const hasFiles = (event: DragEvent) =>
        Array.from(event.dataTransfer?.types ?? []).includes('Files');

    const dropZoneProps = {
        onDragenter: (event: DragEvent) => {
            event.preventDefault();

            if (hasFiles(event)) {
                depth++;
                droppingFiles.value = true;
            }
        },
        onDragover: (event: DragEvent) => event.preventDefault(),
        onDragleave: () => {
            depth = Math.max(0, depth - 1);
            droppingFiles.value = depth > 0;
        },
        onDrop: (event: DragEvent) => {
            event.preventDefault();
            depth = 0;
            droppingFiles.value = false;
            onDrop(Array.from(event.dataTransfer?.files ?? []));
        },
    };

    return { droppingFiles, dropZoneProps };
}
