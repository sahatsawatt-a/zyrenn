import type Konva from 'konva';
import { computed } from 'vue';
import type { Item } from '../model/items';
import { labelledOutside } from '../model/shapes';
import { withAlpha } from './paint';
import { useBoardTheme } from './useBoardTheme';

// What every part of an item's drawing (BoardItem and drawing/kinds/) shares:
// the colours it is drawn in, and how its edge, shadow and glow look.

// Konva's text is "inherit" by default, and then asks the canvas which way it
// runs every time it draws -- which has the browser work out the canvas's
// style again, for every label, on every frame of a zoom. The app runs left
// to right throughout, so it is said once.
export const DIRECTION = 'ltr';

/** What is selected is outlined in this. */
export const SELECTED = '#6366f1';

/** What is high in a running logic circuit is drawn in this. */
export const LIVE = '#16a34a';

/** What a circuit was worked out to, and that which is over its rating. */
export const WORKED = '#6366f1';
export const OVER = '#dc2626';

/**
 * The colours one item is drawn in, on the board it is drawn on: on a dark
 * board a line in the board's ink is drawn light where it lies on the board
 * itself, and every other colour as it was chosen.
 */
export function useItemLook(props: { item: Item; selected: boolean }) {
    const theme = useBoardTheme();
    const shown = (colour: string) => theme.value.drawn(colour, props.item);

    /**
     * The colour its label is written in: read against the shape, or -- for
     * one named outside itself, a figure with its name under it -- against
     * whatever it stands on.
     */
    const ink = computed(() =>
        labelledOutside(props.item.kind, props.item.variant)
            ? theme.value.inkOver(theme.value.behind(props.item))
            : theme.value.inkOn(props.item),
    );

    /** A rule across a rich label is drawn as the shape's own edge is. */
    const ruled = computed(() =>
        props.item.stroke && props.item.stroke !== 'transparent'
            ? shown(props.item.stroke)
            : ink.value,
    );

    /**
     * What lifts it off the board or lights it: a glow round it in a colour;
     * else a shadow -- the soft one `shadow` puts under the fill, or one of
     * its own. A line with nothing inside casts it from the line itself.
     */
    const effects = computed(() => {
        const item = props.item;
        const hollow = !item.fill || item.fill === 'transparent';

        if (item.glow) {
            return {
                shadowColor: item.glow,
                shadowBlur: item.glowSize ?? 24,
                shadowOpacity: 0.9,
                shadowOffsetX: 0,
                shadowOffsetY: 0,
                shadowForStrokeEnabled: hollow,
            };
        }

        if (item.shadow || item.shadowColor) {
            return {
                shadowColor: item.shadowColor ?? '#0f172a',
                shadowOpacity: item.shadowOpacity ?? 0.2,
                shadowBlur: item.shadowBlur ?? 16,
                shadowOffsetX: item.shadowX ?? 0,
                shadowOffsetY: item.shadowY ?? 6,
                shadowForStrokeEnabled: hollow,
            };
        }

        return {};
    });

    /**
     * A shape's edge: its own line at its own weight -- none at all, at 0 --
     * or the selection's colour over it, heavy enough to see.
     */
    const edge = computed(() => ({
        stroke: props.selected
            ? SELECTED
            : withAlpha(shown(props.item.stroke), props.item.strokeOpacity),
        strokeWidth: props.selected
            ? Math.max(2, props.item.lineWidth)
            : props.item.lineWidth,
        ...effects.value,
    }));

    return { theme, shown, ink, ruled, effects, edge };
}

/** A drawing of thin lines takes the pointer anywhere in its box. */
export const boxHit = (context: Konva.Context, shape: Konva.Shape) => {
    context.beginPath();
    context.rect(0, 0, 100, 100);
    context.closePath();
    context.fillStrokeShape(shape);
};

/**
 * Where a picture is drawn in its box, by its fit: stretched to the box,
 * whole inside it with room either side, or covering it with the picture's
 * overflow cut away. The box is always what is picked up and resized.
 */
export const pictured = (item: Item, image: HTMLImageElement) => {
    const width = image.naturalWidth || item.width;
    const height = image.naturalHeight || item.height;

    if (item.fit === 'contain') {
        const scale = Math.min(item.width / width, item.height / height);

        return {
            x: (item.width - width * scale) / 2,
            y: (item.height - height * scale) / 2,
            width: width * scale,
            height: height * scale,
        };
    }

    if (item.fit === 'cover') {
        // The part of the picture, in its own pixels, that fills the box
        const scale = Math.max(item.width / width, item.height / height);
        const across = item.width / scale;
        const down = item.height / scale;

        // The point kept in view: the middle, unless the item names another
        return {
            width: item.width,
            height: item.height,
            crop: {
                x: (width - across) * (item.focusX ?? 0.5),
                y: (height - down) * (item.focusY ?? 0.5),
                width: across,
                height: down,
            },
        };
    }

    return { width: item.width, height: item.height };
};
