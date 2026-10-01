import Konva from 'konva';
import type { ComputedRef, Ref } from 'vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Item } from './items';

/** Konva name of a video's play button, so a click can tell it from the rest. */
export const VIDEO_PLAY = 'video-play';

/**
 * Videos on a board.
 *
 * Konva draws a <video> the way it draws an <img>, one frame at a time, so a
 * video is a picture that keeps being redrawn while it plays. Drawn on the
 * canvas rather than laid over it, it sits in its proper place among the other
 * items, turns with them and is picked up like any of them.
 *
 * Each video item gets an element of its own, kept by the item's id, so two
 * copies of one file play separately. Nobody else sees you press play: what
 * is playing is this window's business, not the board's.
 */
export function useVideos(
    items: Ref<Item[]> | ComputedRef<Item[]>,
    layer: () => Konva.Layer | undefined,
) {
    const elements = new Map<string, HTMLVideoElement>();

    // Which have a frame to show, and which are playing. Each set is replaced
    // rather than changed, which is what makes the canvas draw them again.
    const ready = ref(new Set<string>());
    const playing = ref(new Set<string>());

    const mark = (set: Ref<Set<string>>, id: string, on: boolean) => {
        if (set.value.has(id) !== on) {
            const next = new Set(set.value);

            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            set.value = next;
        }
    };

    // Redraws the layer every frame while anything plays, and only then
    let animation: Konva.Animation | null = null;

    watch(playing, (now) => {
        const target = layer();

        if (now.size && target) {
            animation ??= new Konva.Animation(() => {}, target);
            animation.start();
        } else {
            animation?.stop();
        }
    });

    const redraw = () => layer()?.batchDraw();

    const create = (item: Item): HTMLVideoElement => {
        const video = document.createElement('video');
        const id = item.id;

        video.preload = 'metadata';
        video.playsInline = true;

        video.addEventListener('loadedmetadata', () => {
            // Some browsers stop at the metadata and have no frame to show;
            // asking for a moment just past the start makes them fetch one
            if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
                video.currentTime = 0.01;
            }
        });
        video.addEventListener('loadeddata', () => {
            mark(ready, id, true);
            redraw();
        });
        // Scrubbing while paused: show the frame it landed on
        video.addEventListener('seeked', redraw);
        video.addEventListener('play', () => mark(playing, id, true));
        video.addEventListener('pause', () => {
            mark(playing, id, false);
            redraw();
        });

        video.src = item.src;
        elements.set(id, video);

        return video;
    };

    /** Lets go of a video, so it stops playing and stops downloading. */
    const release = (id: string) => {
        const video = elements.get(id);

        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load();
            elements.delete(id);
            mark(ready, id, false);
            mark(playing, id, false);
        }
    };

    /** The element a video item plays in, once it has a frame to show. */
    const videoFor = (item: Item): HTMLVideoElement | undefined => {
        if (item.kind !== 'video' || !item.src) {
            return undefined;
        }

        let video = elements.get(item.id);

        // Pointed at another file: start again with that one
        if (video && video.getAttribute('src') !== item.src) {
            release(item.id);
            video = undefined;
        }

        video ??= create(item);

        return ready.value.has(item.id) ? video : undefined;
    };

    /** The element, whether or not it has a frame yet: for its controls. */
    const elementOf = (id: string) => elements.get(id);

    const isPlaying = (id: string) => playing.value.has(id);

    const toggle = (id: string) => {
        const video = elements.get(id);

        if (!video) {
            return;
        }

        if (video.paused) {
            void video.play().catch(() => undefined);
        } else {
            video.pause();
        }
    };

    const pause = (id: string) => elements.get(id)?.pause();

    // A video deleted (or undone away) stops; it comes back afresh with undo
    watch(
        () => items.value.map((item) => item.id),
        (ids) => {
            const present = new Set(ids);

            for (const id of [...elements.keys()]) {
                if (!present.has(id)) {
                    release(id);
                }
            }
        },
    );

    onBeforeUnmount(() => {
        animation?.stop();

        for (const id of [...elements.keys()]) {
            release(id);
        }
    });

    return { videoFor, elementOf, isPlaying, toggle, pause, playing };
}
