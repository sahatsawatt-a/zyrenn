// The shapes drawn from a path: what each looks like, where a connector stops
// on it and where its label is written. One list (lib/shapes.json) is read by
// the canvas and by the server (App\Support\Board\Shapes), so a shape is the
// same shape wherever it is named. Add one there and both have it.
import library from '@/features/boards/lib/shapes.json';

type Side = 'top' | 'right' | 'bottom' | 'left';

/** The kinds the library adds to the canvas's own. */
export type ShapeKind = keyof typeof library.shapes;

export type ShapeDef = {
    id: ShapeKind;
    label: string;
    group: string;
    // The outline, in a 100 by 100 box: filled, and drawn in the item's line
    path: string;
    // Parts filled in the line's colour: the dot in an end state
    solid?: string;
    // Lines only, with nothing between them to fill: a brace
    noFill?: boolean;
    // A drawing of thin lines takes the pointer anywhere in its box
    hit?: 'box';
    // How far inside the box the outline lies at the middle of each side
    insets?: Partial<Record<Side, number>>;
    // The sides that are straight edges of the box
    flat?: Side[];
    // How far in from each side the label's box starts, as a share of the
    // item: over 1 or under 0 is outside it, the way a figure is named below
    text?: Partial<Record<Side, number>>;
    // Other words a search finds it by
    aliases?: string[];
    // It holds a list of rows under its title: a table's columns, a class's
    // fields and methods (model/rows.ts)
    rows?: 'table' | 'class';
    // It has a titled band of a fixed depth: a table, a class, a lane
    header?: boolean;
    // A connector's end dropped on it keeps how far along it it was dropped:
    // a message stays at its height on a lifeline
    slide?: boolean;
    // What a new one starts with, where that is not what any shape does
    defaults?: Record<string, unknown>;
};

const SHAPES = new Map<string, ShapeDef>(
    Object.entries(library.shapes).map(([id, shape]) => [
        id,
        { ...(shape as unknown as Omit<ShapeDef, 'id'>), id: id as ShapeKind },
    ]),
);

/** A shape from the library by the kind it is drawn as; none, for any other. */
export const shapeOf = (kind: string): ShapeDef | undefined => SHAPES.get(kind);

/** The library's shapes, a group at a time, in the order they are listed. */
export const SHAPE_GROUPS: { name: string; shapes: ShapeDef[] }[] =
    library.groups.map((name) => ({
        name,
        shapes: [...SHAPES.values()].filter((shape) => shape.group === name),
    }));

/** Every shape's outline, by its kind. */
export const SHAPE_PATHS: Record<string, string> = Object.fromEntries(
    [...SHAPES.values()].map((shape) => [shape.id, shape.path]),
);

/** Whether its label is written outside it: under a figure, beside a brace. */
export const labelledOutside = (kind: string, variant?: string): boolean => {
    const text = lookOf({ kind, variant }).text;

    return (
        !!text &&
        [text.top, text.right, text.bottom, text.left].some(
            (share) => share !== undefined && (share >= 1 || share < 0),
        )
    );
};

// ------------------------------------------------------------------ designs

/**
 * Another way to draw a kind: a database as a stack of discs, or as a card
 * with an icon on it. Picked by an item's `variant`; the first of a kind's
 * designs is the kind as it has always been drawn.
 */
export type Design = {
    id: string;
    label: string;
    // An outline of its own, in the same 100 by 100 box
    path?: string;
    solid?: string;
    // Parts filled a little darker than the fill, for depth: a lid, a side
    shade?: string;
    noFill?: boolean;
    // Drawn round its icon: a card with rounded corners, or a disc
    frame?: 'card' | 'badge';
    // The icon drawn with it, by its name in lib/icons.json
    icon?: string;
    text?: Partial<Record<Side, number>>;
    insets?: Partial<Record<Side, number>>;
    flat?: Side[];
    hit?: 'box';
};

type DesignSpec = { icon?: string; more?: Design[] };

const DESIGNS =
    (library as unknown as { designs?: Record<string, DesignSpec> }).designs ??
    {};

// Under the box, as a figure is named
const BELOW = { top: 1, bottom: -0.4, left: -0.6, right: -0.6 };
const SIDES: Side[] = ['top', 'right', 'bottom', 'left'];

/** Every way a kind can be drawn, the usual first; none for a kind with one. */
export const designsOf = (kind: string): Design[] => {
    const spec = DESIGNS[kind];

    if (!spec) {
        return [];
    }

    return [
        { id: 'classic', label: 'Classic' },
        ...(spec.more ?? []),
        ...(spec.icon
            ? ([
                  {
                      id: 'card',
                      label: 'Card',
                      frame: 'card',
                      icon: spec.icon,
                      text: {
                          top: 0.54,
                          left: 0.06,
                          right: 0.06,
                          bottom: 0.04,
                      },
                      insets: {},
                      flat: SIDES,
                      hit: 'box',
                  },
                  {
                      id: 'badge',
                      label: 'Badge',
                      frame: 'badge',
                      icon: spec.icon,
                      text: BELOW,
                      insets: {},
                      flat: [],
                      hit: 'box',
                  },
                  {
                      id: 'icon',
                      label: 'Icon',
                      icon: spec.icon,
                      // No outline: the icon is all there is to it
                      path: '',
                      noFill: true,
                      text: BELOW,
                      insets: {},
                      flat: [],
                      hit: 'box',
                  },
              ] satisfies Design[])
            : []),
    ];
};

/** The design an item is drawn in, when it is not its kind's usual one. */
export const designOf = (item: {
    kind: string;
    variant?: string;
}): Design | undefined =>
    item.variant && item.variant !== 'classic'
        ? designsOf(item.kind).find((design) => design.id === item.variant)
        : undefined;

/**
 * What an item is drawn from: its kind's library entry, with whatever its
 * design draws differently laid over it.
 */
export const lookOf = (item: { kind: string; variant?: string }) => {
    const shape = shapeOf(item.kind);
    const design = designOf(item);

    return {
        solid: design ? design.solid : shape?.solid,
        shade: design?.shade,
        noFill: design?.noFill ?? shape?.noFill,
        frame: design?.frame,
        icon: design?.icon,
        text: design?.text ?? shape?.text,
        insets: design?.insets ?? shape?.insets,
        flat: design?.flat ?? shape?.flat,
        hit: design?.hit ?? shape?.hit,
        // Its own outline, when the design has one; else the kind's
        path: design?.path,
    };
};
