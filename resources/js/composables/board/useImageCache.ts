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
        if (!item.src) {
            return undefined;
        }

        const ready = decoded.value.get(item.src);

        if (ready || loading.has(item.src)) {
            return ready;
        }

        loading.add(item.src);

        const image = new window.Image();

        image.onload = () => {
            decoded.value = new Map(decoded.value).set(item.src, image);
            loading.delete(item.src);
        };
        image.onerror = () => loading.delete(item.src);
        image.src = item.src;

        return undefined;
    };

    return { imageFor };
}
