// Ready-made looks for what is drawn on a board: colour sets to choose from
// and styles to put a colour on with -- soft, solid, outline. One list
// (lib/styles.json) is read by the canvas and by the server
// (App\Support\Board\Styles), so a style an MCP client names is the one the
// canvas paints.
import library from '@/features/boards/lib/styles.json';
import icons from '@/features/boards/lib/icons.json';
import type { Item, ItemKind } from './items';

export type Shade = keyof (typeof library.colours)['slate']['shades'];
export type ColourName = keyof typeof library.colours;
export type StyleDef = (typeof library.styles)[number];
export type ColourSet = (typeof library.sets)[number];

export const COLOURS = library.colours as Record<
    string,
    { label: string; shades: Record<Shade, string>; solid: Shade }
>;
export const STYLES: StyleDef[] = library.styles;
export const COLOUR_SETS: ColourSet[] = library.sets;

/** An icon's outline, in a 24 by 24 box, by its name. */
export const iconPath = (name: string): string | undefined =>
    (icons.icons as Record<string, string>)[name];

/** A shade of a colour, as a style names it: "500", "solid" or "white". */
const shadeOf = (colour: string, shade: string): string => {
    const family = COLOURS[colour] ?? COLOURS.slate;

    if (shade === 'white') {
        return '#ffffff';
    }

    return family.shades[(shade === 'solid' ? family.solid : shade) as Shade];
};

// Kinds a style says nothing about: what is a picture, a formula, a part
const UNSTYLED: ItemKind[] = ['image', 'video', 'math', 'symbol'];

/**
 * What putting a colour on in a style changes about an item: its fill, its
 * line and its words for a shape; its line for a connector or ink; its
 * words for a text.
 */
export const styled = (
    item: Pick<Item, 'kind'>,
    style: string,
    colour: string,
): Partial<Item> => {
    const look = STYLES.find((each) => each.id === style) ?? STYLES[0];

    if (UNSTYLED.includes(item.kind)) {
        return {};
    }

    // Lines and words go on the board itself: they take a colour's strong
    // shade, whatever the style, where white would vanish
    if (item.kind === 'arrow' || item.kind === 'draw') {
        return {
            stroke: shadeOf(
                colour,
                look.stroke === 'white' ? 'solid' : look.stroke,
            ),
        };
    }

    if (item.kind === 'text') {
        return {
            color: shadeOf(
                colour,
                look.color === 'white' ? 'solid' : look.color,
            ),
        };
    }

    return {
        fill: shadeOf(colour, look.fill),
        stroke: shadeOf(colour, look.stroke),
        color: shadeOf(colour, look.color),
        lineWidth: look.lineWidth,
    };
};

// ----------------------------------------------- restyling a board at once

const rgb = (hex: string): [number, number, number] | null => {
    const found = /^#?([0-9a-f]{6})$/i.exec(hex.trim());

    if (!found) {
        return null;
    }

    const value = parseInt(found[1], 16);

    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
};

/** How far a colour is from grey: 0 for a grey, up to 1. */
const saturation = ([r, g, b]: [number, number, number]) => {
    const most = Math.max(r, g, b);
    const least = Math.min(r, g, b);

    return most === 0 ? 0 : (most - least) / most;
};

/**
 * The colour a hex is nearest to among the families, or null for a grey,
 * white or no colour at all -- those go with a set's grey.
 */
export const familyOf = (hex: string): string | null => {
    const at = rgb(hex);

    if (!at || saturation(at) < 0.08) {
        return null;
    }

    let best: string | null = null;
    let nearest = Infinity;

    for (const [name, family] of Object.entries(COLOURS)) {
        for (const shade of Object.values(family.shades)) {
            const other = rgb(shade)!;
            const distance = other.reduce(
                (sum, channel, index) => sum + (channel - at[index]) ** 2,
                0,
            );

            if (distance < nearest) {
                nearest = distance;
                best = name;
            }
        }
    }

    // A blue-grey is still a grey
    return best === 'slate' || best === 'stone' ? null : best;
};

/** The colour an item is known by: its fill, unless that is blank; else its line. */
const colourOf = (item: Item): string =>
    item.kind === 'arrow' || item.kind === 'draw'
        ? item.stroke
        : item.kind === 'text'
          ? (item.color ?? '')
          : rgb(item.fill) && saturation(rgb(item.fill)!) >= 0.12
            ? item.fill
            : item.stroke;

/**
 * A whole board in one colour set and one style, each item changed only as
 * far as it has to be. Colours that go together keep going together: a
 * colour the set has stays itself, any other takes one of the set's that is
 * left, and greys and blank shapes take the set's grey. Connectors take
 * the grey too, so the shapes stand out. A frame keeps its colours: it is
 * the slide everything is on.
 */
export const restyled = (
    items: Item[],
    set: string,
    style: string,
): Map<string, Partial<Item>> => {
    const colours = (
        COLOUR_SETS.find((each) => each.id === set) ?? COLOUR_SETS[0]
    ).colours;
    const grey = colours[colours.length - 1];
    const accents = colours.slice(0, -1);
    const styledable = items.filter(
        (item) => item.kind !== 'frame' && !UNSTYLED.includes(item.kind),
    );
    const families = styledable.map((item) =>
        item.kind === 'arrow' ? null : familyOf(colourOf(item)),
    );

    // A colour the set has keeps itself, so that restyling twice changes
    // nothing; the others take the set's colours left over, in the order
    // they are first met
    const slots = new Map<string, string>();

    for (const family of families) {
        if (family !== null && accents.includes(family)) {
            slots.set(family, family);
        }
    }

    const spare = accents.filter(
        (accent) => ![...slots.values()].includes(accent),
    );
    let next = 0;

    for (const family of families) {
        if (family !== null && !slots.has(family) && accents.length) {
            const pool = spare.length ? spare : accents;

            slots.set(family, pool[next++ % pool.length]);
        }
    }

    const changes = new Map<string, Partial<Item>>();

    styledable.forEach((item, index) => {
        const family = families[index];
        const change = styled(
            item,
            style,
            family === null || !accents.length ? grey : slots.get(family)!,
        );

        if (Object.keys(change).length) {
            changes.set(item.id, change);
        }
    });

    return changes;
};
