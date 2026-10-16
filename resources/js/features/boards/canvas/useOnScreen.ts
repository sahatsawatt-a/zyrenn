import type { ComputedRef, Ref } from 'vue';
import { computed } from 'vue';
import { boundsOf, overlaps } from '../model/geometry';
import type { Item } from '../model/items';
import { isConnector } from '../model/items';
import type { useCamera } from './useCamera';

type OnScreen = {
    items: () => Item[];
    camera: ReturnType<typeof useCamera>;
    width: Ref<number>;
    height: Ref<number>;
    /** Drawn wherever they are: what is selected, and what is being typed into. */
    kept: () => Set<string>;
    /** Every slide is drawn while the camera glides between them. */
    presenting: Ref<boolean>;
    connectorPath: (item: Item) => number[];
};

/** How far past the edge of the screen is still drawn, in screens. */
const MARGIN = 0.5;

/**
 * The box an item may paint in. Konva turns an item about its top left
 * corner, so a turned one is somewhere within its diagonal of that corner.
 */
const reachOf = (item: Item) => {
    const box = boundsOf(item);

    if (!item.rotation) {
        return box;
    }

    const reach = Math.hypot(box.width, box.height);

    return {
        x: item.x - reach,
        y: item.y - reach,
        width: reach * 2,
        height: reach * 2,
    };
};

/** The box round a connector's path, which is all of it there is to draw. */
const pathBox = (points: number[]) => {
    const xs = points.filter((_, index) => index % 2 === 0);
    const ys = points.filter((_, index) => index % 2 === 1);

    return xs.length
        ? {
              x: Math.min(...xs),
              y: Math.min(...ys),
              width: Math.max(...xs) - Math.min(...xs),
              height: Math.max(...ys) - Math.min(...ys),
          }
        : null;
};

/**
 * What is on the board that is worth drawing just now, in paint order:
 * whatever is in view, or near enough to come into it as the board is moved
 * a little, and not what is screens away -- Konva would draw that on every
 * drag, pan and zoom for nobody to see. What is selected or being typed into
 * is always drawn, since its handles and its editor need it; and while the
 * camera glides (between slides, or to something picked from the layers),
 * everything is, so nothing appears late.
 */
export function useOnScreen({
    items,
    camera,
    width,
    height,
    kept,
    presenting,
    connectorPath,
}: OnScreen): ComputedRef<Item[]> {
    return computed(() => {
        const visible = items().filter((item) => !item.hidden);

        if (
            presenting.value ||
            camera.animating.value ||
            !width.value ||
            !height.value
        ) {
            return visible;
        }

        const screen = camera.visibleBox();
        const view = {
            x: screen.x - screen.width * MARGIN,
            y: screen.y - screen.height * MARGIN,
            width: screen.width * (1 + MARGIN * 2),
            height: screen.height * (1 + MARGIN * 2),
        };
        const always = kept();

        return visible.filter((item) => {
            if (always.has(item.id)) {
                return true;
            }

            if (isConnector(item)) {
                const box = pathBox(connectorPath(item));

                return !box || overlaps(box, view);
            }

            return overlaps(reachOf(item), view);
        });
    });
}
