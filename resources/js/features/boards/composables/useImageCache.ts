import { ref } from 'vue';
import type { Item } from './items';

/**
 * Pictures decoded for the canvas, kept by their URL.
 *
 * Konva draws an HTMLImageElement, not a URL, so each picture is decoded once
 * and held. The map is replaced rather than mutated, which is what makes the
 * canvas redraw when one finishes loading.
 */
export function useImageCache() {
    // Each picture is decoded once and kept by its URL. The map is replaced
    // rather than mutated, so the canvas redraws when one finishes loading.
    const decoded = ref(new Map<string, HTMLImageElement>());
    const loading = new Set<string>();

    const imageFor = (item: Item): HTMLImageElement | undefined => {
        // A video's picture is its poster, not the video itself
        const src = item.kind === 'video' ? item.poster : item.src;

        if (!src) {
            return undefined;
        }

        const ready = decoded.value.get(src);

        if (ready || loading.has(src)) {
            return ready;
        }

        loading.add(src);

        const image = new window.Image();

        image.onload = () => {
            decoded.value = new Map(decoded.value).set(src, image);
            loading.delete(src);
        };
        image.onerror = () => loading.delete(src);
        image.src = src;

        return undefined;
    };

    // The pictures decoded so far: changes as each one arrives
    return { imageFor, decoded };
}
