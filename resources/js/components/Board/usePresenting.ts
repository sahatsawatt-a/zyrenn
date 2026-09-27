import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { boundsOf, boundsOfAll } from './geometry';
import type { Item } from './items';

type Show = {
    board: {
        items: Ref<Item[]>;
        frames: ComputedRef<Item[]>;
        select: (ids: string[]) => void;
    };
    camera: {
        focus: (
            box: { x: number; y: number; width: number; height: number } | null,
            options?: { animate?: boolean; padding?: number },
        ) => void;
    };
    /** The size of the canvas, which changes as the panels come and go. */
    width: Ref<number>;
    height: Ref<number>;
};

/**
 * Playing the board's frames one screen at a time.
 *
 * Starting and stopping hides or restores the side panels, so the canvas
 * changes width a moment later; the camera is recomputed once the new size
 * lands, which also keeps the frame filling the screen if the window is
 * resized midway through.
 */
export function usePresenting({ board, camera, width, height }: Show) {
    const presenting = ref(false);
    const frameIndex = ref(0);

    const currentFrame = computed(
        () => board.frames.value[frameIndex.value] ?? null,
    );

    const showFrame = (index: number) => {
        const frames = board.frames.value;

        if (!frames.length) {
            return;
        }

        frameIndex.value = Math.min(frames.length - 1, Math.max(0, index));
        camera.focus(boundsOf(frames[frameIndex.value]), {
            animate: true,
            padding: 24,
        });
    };

    let refitOnResize: 'all' | null = null;

    watch([width, height], () => {
        if (presenting.value) {
            const frame = currentFrame.value;

            if (frame) {
                camera.focus(boundsOf(frame), { padding: 24 });
            }

            return;
        }

        if (refitOnResize === 'all') {
            refitOnResize = null;
            camera.focus(boundsOfAll(board.items.value));
        }
    });

    const startPresenting = () => {
        if (!board.frames.value.length) {
            return;
        }

        board.select([]);
        presenting.value = true;
        showFrame(0);
    };

    const stopPresenting = () => {
        presenting.value = false;
        refitOnResize = 'all';
        camera.focus(boundsOfAll(board.items.value), { animate: true });
    };

    return {
        presenting,
        frameIndex,
        currentFrame,
        showFrame,
        startPresenting,
        stopPresenting,
    };
}
