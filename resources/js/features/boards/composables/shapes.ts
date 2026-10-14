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
    // fields and methods (composables/rows.ts)
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
export const labelledOutside = (kind: string): boolean => {
    const text = shapeOf(kind)?.text;

    return (
        !!text &&
        [text.top, text.right, text.bottom, text.left].some(
            (share) => share !== undefined && (share >= 1 || share < 0),
        )
    );
};
