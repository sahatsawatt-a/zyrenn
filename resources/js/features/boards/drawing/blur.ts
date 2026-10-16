import Konva from 'konva';
import type { Item } from '../model/items';

/**
 * How an item mixes with what is under it, and how soft it is, as a group's
 * config: blending is a setting, softening a filter -- which Konva only runs
 * on a node drawn once to a canvas of its own (cacheBlur).
 */
export const blending = (item: Item) => ({
    globalCompositeOperation: item.blend ?? 'source-over',
    filters: item.blur ? [Konva.Filters.Blur] : [],
    blurRadius: item.blur ?? 0,
});

/**
 * A softened item drawn again to a canvas of its own, with room round it for
 * the softening to spread into; one no longer soft let go of that canvas.
 * Called whenever it may have changed.
 */
export function cacheBlur(node: Konva.Node | undefined, item: Item): void {
    if (!node) {
        return;
    }

    if (item.blur) {
        node.cache({ offset: item.blur * 2 });
    } else if (node.isCached()) {
        node.clearCache();
    }
}
