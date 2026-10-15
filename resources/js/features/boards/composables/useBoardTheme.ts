import type { ComputedRef, InjectionKey } from 'vue';
import { computed, inject, provide, ref } from 'vue';
import { boundsOf } from './geometry';
import type { Item } from './items';
import { INK, isConnector } from './items';
import { groupKeys } from './layers';

/**
 * What a board is drawn on: white, or in the dark the app's own dark
 * background. Only the board changes. A frame, a shape, a sticky keep the
 * colours they were given; what changes with it is whatever was written or
 * drawn in the board's ink straight onto the board, which would otherwise be
 * dark on dark -- that is drawn light instead. Nothing saved changes.
 */
export type BoardTheme = {
    dark: boolean;
    /** The board itself, behind everything. */
    paper: string;
    /** What this item lies on: its frame's colour, or the board. */
    behind: (item: Item) => string;
    /** A line of this item's as it is drawn: the board's ink, kept readable. */
    drawn: (colour: string, item: Item) => string;
    /**
     * The colour to write in, on this item or on whatever it lies on: the
     * colour its words were given, or what reads best there.
     */
    inkOn: (item: Item) => string;
    /** The colour to write in on a colour. */
    inkOver: (colour: string) => string;
};

const PAPER = '#ffffff';

// On a light board words are ink, but white on a fill too dark for ink
const onLight = (colour: string) =>
    (lightnessOf(colour) ?? 1) > 0.55 ? INK : '#ffffff';

const LIGHT: BoardTheme = {
    dark: false,
    paper: PAPER,
    behind: () => PAPER,
    drawn: (colour) => colour,
    inkOn: (item) =>
        item.color || (item.kind === 'frame' ? INK : onLight(item.fill)),
    inkOver: () => INK,
};

const KEY: InjectionKey<ComputedRef<BoardTheme>> = Symbol('board-theme');

// One reading of the page's theme for every board on it. The app says dark
// with a class on <html>, and which colours with data-theme beside it.
const dark = ref(false);
const tokens = ref({ paper: '', ink: '' });
let watching = false;

const read = () => {
    const root = document.documentElement;
    const style = getComputedStyle(root);
    const token = (name: string) => style.getPropertyValue(name).trim();

    dark.value = root.classList.contains('dark');
    tokens.value = {
        paper: token('--background'),
        ink: token('--foreground'),
    };
};

const watchPage = () => {
    if (watching || typeof document === 'undefined') {
        return;
    }

    watching = true;
    read();
    new MutationObserver(read).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class', 'data-theme', 'style'],
    });
};

// How light a colour is, 0 to 1, by having the browser read it -- so "red",
// "rgb(...)" and a hex all answer. Nothing to read (or see-through) is null.
const lightness = new Map<string, number | null>();
let probe: CanvasRenderingContext2D | null = null;

const lightnessOf = (colour: string): number | null => {
    if (
        !colour ||
        colour === 'transparent' ||
        typeof document === 'undefined'
    ) {
        return null;
    }

    if (!lightness.has(colour)) {
        probe ??= document
            .createElement('canvas')
            .getContext('2d', { willReadFrequently: true });

        let value: number | null = null;

        if (probe) {
            probe.clearRect(0, 0, 1, 1);
            probe.fillStyle = '#000000';
            probe.fillStyle = colour;
            probe.fillRect(0, 0, 1, 1);

            const [r, g, b, a] = probe.getImageData(0, 0, 1, 1).data;

            value =
                a < 32 ? null : (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
        }

        lightness.set(colour, value);
    }

    return lightness.get(colour) ?? null;
};

/** The frame each thing lies on, by its id; the bare board has none. */
const framesUnder = (items: Item[]): Map<string, Item> => {
    const frames = items.filter((item) => item.kind === 'frame');
    const under = new Map<string, Item>();

    if (!frames.length) {
        return under;
    }

    const byId = new Map(frames.map((frame) => [frame.id, frame]));

    groupKeys(items).forEach((key, id) => {
        const frame = byId.get(key);

        if (frame) {
            under.set(id, frame);
        }
    });

    // A line joined to nothing is filed with the board, wherever it is
    // drawn: it lies on whatever frame its first end is over
    for (const item of items) {
        const end = isConnector(item) ? (item.from ?? item.to) : null;

        if (!end || under.has(item.id)) {
            continue;
        }

        const frame = frames
            .filter((candidate) => {
                const box = boundsOf(candidate);

                return (
                    end.x >= box.x &&
                    end.x <= box.x + box.width &&
                    end.y >= box.y &&
                    end.y <= box.y + box.height
                );
            })
            .sort((a, b) => a.width * a.height - b.width * b.height)[0];

        if (frame) {
            under.set(item.id, frame);
        }
    }

    return under;
};

const opaque = (colour: string) => lightnessOf(colour) !== null;

const darkTheme = (
    now: { paper: string; ink: string },
    under: Map<string, Item>,
): BoardTheme => {
    const inkOver = (colour: string) =>
        (lightnessOf(colour) ?? 0) > 0.55 ? INK : now.ink;

    const behind = (item: Item) => {
        const frame = item.kind === 'frame' ? null : under.get(item.id);

        return frame && opaque(frame.fill) ? frame.fill : now.paper;
    };

    return {
        dark: true,
        paper: now.paper,
        behind,
        drawn: (colour, item) =>
            colour.toLowerCase() === INK ? inkOver(behind(item)) : colour,
        // A colour given to its words is theirs, as a fill is the shape's
        inkOn: (item) =>
            item.color ||
            inkOver(
                item.kind !== 'frame' && opaque(item.fill)
                    ? item.fill
                    : behind(item),
            ),
        inkOver,
    };
};

/**
 * Gives everything drawn below the theme to draw in, for the board whose
 * items these are. `plain` keeps a board light whatever the page is -- a
 * picture or a PDF of it is on paper.
 */
export function provideBoardTheme(items: () => Item[], { plain = false } = {}) {
    if (!plain) {
        watchPage();
    }

    const theme = computed(() =>
        !plain && dark.value && tokens.value.paper
            ? darkTheme(tokens.value, framesUnder(items()))
            : LIGHT,
    );

    provide(KEY, theme);

    return theme;
}

/** The theme the board this is drawn on was given; light, on none. */
export const useBoardTheme = () =>
    inject(
        KEY,
        computed(() => LIGHT),
    );
