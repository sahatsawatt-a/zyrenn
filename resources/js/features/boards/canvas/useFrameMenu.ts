import type Konva from 'konva';
import type { Ref } from 'vue';
import { ref } from 'vue';
import { boundsOf, overlaps } from '../model/geometry';
import type { Item } from '../model/items';
import type { useBoard } from './useBoard';
import type { useCamera } from './useCamera';

type FrameMenu = {
    board: ReturnType<typeof useBoard>;
    camera: ReturnType<typeof useCamera>;
    stage: () => Konva.Stage | undefined;
    presenting: Ref<boolean>;
    /** Whether a frame can be drawn as a picture at all, to offer it. */
    offered: () => boolean;
};

/**
 * A frame, right-clicked: where on the canvas, so its menu (FrameMenu) opens
 * there. Anywhere on a frame -- its title, its empty middle, or something
 * sitting on it -- is the frame; anywhere else, nothing.
 */
export function useFrameMenu({
    board,
    camera,
    stage,
    presenting,
    offered,
}: FrameMenu) {
    const frameMenu = ref<{ frame: Item; x: number; y: number } | null>(null);

    /** The frame a board point is on -- the topmost, where frames overlap. */
    const frameAt = (point: { x: number; y: number }): Item | null => {
        for (let index = board.items.value.length - 1; index >= 0; index--) {
            const item = board.items.value[index];

            if (
                item.kind === 'frame' &&
                !item.hidden &&
                overlaps(boundsOf(item), { ...point, width: 1, height: 1 })
            ) {
                return item;
            }
        }

        return null;
    };

    /**
     * The menu of the frame under a point on the canvas, opened there.
     * Answers whether there was one to open.
     */
    const openAt = (point: { x: number; y: number }): boolean => {
        if (!offered() || presenting.value) {
            return false;
        }

        const frame = frameAt(camera.toBoard(point));

        if (frame) {
            frameMenu.value = { frame, x: point.x, y: point.y };
        }

        return !!frame;
    };

    // A finger's long press; a mouse's or a pen's right click comes by way
    // of useRightPan, which can tell it from a drag
    const onContextMenu = (event: Konva.KonvaEventObject<PointerEvent>) => {
        const point = stage()?.getPointerPosition();

        if (point && openAt(point)) {
            event.evt.preventDefault();
        }
    };

    return { frameMenu, onContextMenu, openAt };
}
