import { useEventListener } from '@vueuse/core';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { boundsOf, boundsOfAll } from '../model/geometry';
import type { Item } from '../model/items';

type Show = {
    board: {
        items: Ref<Item[]>;
        frames: ComputedRef<Item[]>;
        select: (ids: string[]) => void;
    };
    camera: {
        focus: (
            box: { x: number; y: number; width: number; height: number } | null,
            options?: {
                animate?: boolean;
                padding?: number;
                inset?: { bottom?: number };
            },
        ) => void;
    };
    /** The size of the canvas, which changes as the panels come and go. */
    width: Ref<number>;
    height: Ref<number>;
    /** What fills the screen while presenting: the board, without the app round it. */
    screen?: () => HTMLElement | null;
    /** Back to everything on the board, once the show is over. */
    overview?: (options: { animate: boolean }) => void;
    /** Room kept clear under the slide: the speaker notes, when shown. */
    below?: () => number;
};

/**
 * Playing the board's frames one screen at a time.
 *
 * Starting and stopping hides or restores the side panels, so the canvas
 * changes width a moment later; the camera is recomputed once the new size
 * lands, which also keeps the frame filling the screen if the window is
 * resized midway through.
 */
export function usePresenting({
    board,
    camera,
    width,
    height,
    screen,
    overview = ({ animate }) =>
        camera.focus(boundsOfAll(board.items.value), { animate }),
    below = () => 0,
}: Show) {
    const presenting = ref(false);
    const frameIndex = ref(0);
    // When this showing began, for the presenter's clock
    const startedAt = ref(0);

    const fit = (frame: Item, animate = false) =>
        camera.focus(boundsOf(frame), {
            animate,
            padding: 24,
            inset: { bottom: below() },
        });

    const currentFrame = computed(
        () => board.frames.value[frameIndex.value] ?? null,
    );

    const showFrame = (index: number, animate = true) => {
        const frames = board.frames.value;

        if (!frames.length) {
            return;
        }

        frameIndex.value = Math.min(frames.length - 1, Math.max(0, index));
        fit(frames[frameIndex.value], animate);
    };

    /** The slide fitted again: the room under it has changed. */
    const refit = () => {
        if (presenting.value && currentFrame.value) {
            fit(currentFrame.value, true);
        }
    };

    let refitOnResize: 'all' | null = null;

    watch([width, height], () => {
        if (presenting.value) {
            const frame = currentFrame.value;

            if (frame) {
                fit(frame);
            }

            return;
        }

        if (refitOnResize === 'all') {
            refitOnResize = null;
            overview({ animate: false });
        }
    });

    const startPresenting = () => {
        if (!board.frames.value.length) {
            return;
        }

        board.select([]);
        presenting.value = true;
        startedAt.value = Date.now();
        showFrame(0);
        enterFullScreen();
    };

    // The whole screen, as a slideshow should be. A browser that won't
    // (or a frame embedded somewhere) presents inside the page instead.
    const fullScreen = ref(false);
    const enterFullScreen = () =>
        void screen?.()
            ?.requestFullscreen?.()
            .catch(() => undefined);

    // Opening a window of its own (the presenter's) takes the browser out of
    // full screen: the show goes on in the page rather than ending
    let keepUntil = 0;
    const keepShowing = () => {
        keepUntil = Date.now() + 2000;
    };

    const stopPresenting = () => {
        presenting.value = false;
        refitOnResize = 'all';
        overview({ animate: true });

        if (document.fullscreenElement) {
            void document.exitFullscreen().catch(() => undefined);
        }
    };

    // Esc leaves full screen before the page hears it: leaving it ends the
    // show, unless the show was told to keep going
    useEventListener(document, 'fullscreenchange', () => {
        fullScreen.value = !!document.fullscreenElement;

        if (
            !document.fullscreenElement &&
            presenting.value &&
            Date.now() > keepUntil
        ) {
            stopPresenting();
        }
    });

    return {
        presenting,
        frameIndex,
        currentFrame,
        showFrame,
        refit,
        startedAt,
        fullScreen,
        enterFullScreen,
        keepShowing,
        startPresenting,
        stopPresenting,
    };
}
