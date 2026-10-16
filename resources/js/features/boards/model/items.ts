// The board's data model. Everything on the canvas is one flat list of items in
// paint order, which keeps z-order, undo snapshots and hit-testing simple.
import { symbolOf } from '@/features/boards/circuit/symbols';
import { makeRow, rowsHeight } from './rows';
import type { Row } from './rows';
import { SHAPE_PATHS, shapeOf } from './shapes';
import type { ShapeKind } from './shapes';

export type ItemKind =
    | 'frame'
    | 'sticky'
    | 'text'
    | 'rect'
    | 'pill'
    | 'ellipse'
    | 'triangle'
    | 'diamond'
    | 'hexagon'
    | 'star'
    // Flowchart set, drawn from a path fitted to the box
    | 'cylinder'
    | 'parallelogram'
    | 'document'
    | 'process'
    | 'cloud'
    // The library's shapes: more flowchart, UML, architecture (lib/shapes.json)
    | ShapeKind
    | 'image'
    // A video from the Drive or a link, played on the board (useVideos)
    | 'video'
    // A formula, written as LaTeX and set with KaTeX
    | 'math'
    // A part of a circuit -- a resistor, a breaker, a ground -- drawn from the
    // symbol library, with pins a wire holds on to (circuit/symbols)
    | 'symbol'
    | 'arrow'
    | 'draw'
    // A vector line or shape: SVG path data, drawn from its own box
    | 'path';

/** Shapes drawn as a closed polygon fitted to the item's box. */
export const POLYGONS: ItemKind[] = ['triangle', 'diamond', 'hexagon'];

/**
 * Flowchart shapes, as SVG paths drawn in a 100x100 box and then scaled to the
 * item. Konva keeps the outline an even thickness through that scaling when
 * strokeScaleEnabled is off, so a wide database still has a 1.5px edge.
 */
export const PATHS: Partial<Record<ItemKind, string>> = {
    // Database: the classic cylinder, top ellipse drawn over the body
    cylinder:
        'M0,14 L0,86 a50,14 0 0,0 100,0 L100,14 a50,14 0 0,0 -100,0 a50,14 0 0,0 100,0',
    // Data in or out
    parallelogram: 'M22,0 L100,0 L78,100 L0,100 Z',
    // A printed document, with the torn-looking bottom edge
    document: 'M0,0 L100,0 L100,84 q-25,16 -50,4 q-25,-12 -50,4 Z',
    // Predefined process: a box with its two inner rails
    process: 'M0,0 L100,0 L100,100 L0,100 Z M14,0 L14,100 M86,0 L86,100',
    // Four overlapping bumps over a flat base
    cloud: 'M22,78 A20,20 0 0,1 24,40 A22,22 0 0,1 60,27 A20,20 0 0,1 82,46 A17,17 0 0,1 80,78 Z',
    // ...and every shape the library lists, drawn the same way
    ...SHAPE_PATHS,
};

export const isPath = (kind: ItemKind): boolean => kind in PATHS;

export type Side = 'top' | 'right' | 'bottom' | 'left';

/**
 * One end of a connector. It either hangs off a shape's anchor, which it then
 * follows, or floats at a fixed board point.
 */
export type Endpoint = {
    item: string | null;
    side: Side | null;
    x: number;
    y: number;
    // On a symbol: which of its pins the end is on, making the line a wire.
    // On a table or a class: which of its rows, by the row's id
    pin?: string | null;
    // On a shape an end can slide along (a lifeline): how far down it the
    // end was put, 0 to 1, which it keeps as the shape moves and grows
    along?: number | null;
};

export type Routing = 'elbow' | 'straight' | 'curved';

/**
 * What either end of a connector is capped with: the plain caps, the hollow
 * ones a class diagram reads by, and the crow's feet of a data model.
 */
export type HeadType =
    | 'none'
    | 'arrow'
    | 'open'
    | 'circle'
    | 'diamond'
    | 'bar'
    // Hollow: "is a kind of", "has", and a ring
    | 'triangle'
    | 'diamond_open'
    | 'circle_open'
    // How many of the thing at this end: crow's feet
    | 'one_only'
    | 'many'
    | 'one_many'
    | 'zero_one'
    | 'zero_many';
export type LineStyle = 'solid' | 'dashed' | 'dotted';

/** Where a label sits in the box it is written in. */
export type Align = 'left' | 'center' | 'right';

export type VerticalAlign = 'top' | 'middle' | 'bottom';

/**
 * How a picture fills its box: stretched to it, fitted inside it whole, or
 * covering it with the overflow cut off.
 */
export type Fit = 'fill' | 'contain' | 'cover';

/**
 * The typeface a label is set in. Each is a font every system has, or one
 * drawn to the same widths (Liberation, on Linux), so a label wraps the same
 * everywhere -- and where the server works out whether it fits.
 */
export type FontFamily = 'sans' | 'serif' | 'mono';

export const FONT_FAMILIES: Record<FontFamily, string> = {
    sans: 'Arial',
    serif: 'Times New Roman',
    mono: 'Courier New',
};

export const ALIGNS: Align[] = ['left', 'center', 'right'];

export const VERTICAL_ALIGNS: VerticalAlign[] = ['top', 'middle', 'bottom'];

/** A colour a fade passes through, and where: 0 at its start, 1 at its end. */
export type GradientStop = { at: number; color: string };

/** How an item's pixels mix with what is under it, as the canvas names them. */
export type Blend =
    | 'source-over'
    | 'multiply'
    | 'screen'
    | 'overlay'
    | 'darken'
    | 'lighten'
    | 'color-dodge'
    | 'soft-light';

export type Item = {
    id: string;
    kind: ItemKind;
    x: number;
    y: number;
    width: number;
    height: number;
    rotation: number;
    fill: string;
    stroke: string;
    text: string;
    fontSize: number;
    fontFamily: FontFamily;
    // Room between a shape's edge and its label, in board units
    padding: number;
    // The label read as light Markdown: # headings, - bullets, **bold**
    rich: boolean;
    align: Align;
    verticalAlign: VerticalAlign;
    // Freehand keeps its shape as points relative to x/y
    points: number[];
    // Out of sight, and out of reach of the pointer, from the layers list
    hidden: boolean;
    locked: boolean;
    // A frame kept on the board but left out of the PDF export
    pdfHidden: boolean;
    // A picture -- SVG, PNG, JPEG -- as a data URL drawn through an <img>;
    // or, on a video, where the video is
    src: string;
    // A picture's way of filling its box
    fit: Fit;
    // A symbol only: which one, by its id in the library
    symbol: string;
    // A logic input only: whether it is set, for the circuit to run from
    on: boolean;
    // A table or a class only: its columns, or its fields and methods
    rows?: Row[];
    // A shape drawn more than one way: a lane with its title down its side,
    // a database as a card with an icon (model/shapes, designsOf)
    variant?: string;
    // The colour its words are written in; left out, whatever reads best
    // on its fill
    color?: string;
    // Lifted off the board by a soft shadow under it
    shadow?: boolean;
    // How round its corners are, in board units; left out, its kind's own
    radius?: number;
    // How see-through it is, 0 (not at all) to 1 (gone); left out, solid
    opacity?: number;
    // A second colour its fill fades to, and which way: degrees as CSS
    // writes them, 180 (the default) top to bottom
    gradient?: string;
    gradientAngle?: number;
    // A fade through more colours than two, and whether it runs straight
    // (the default) or out from the middle
    gradientStops?: GradientStop[];
    gradientType?: 'linear' | 'radial';
    // The inside and the line made see-through on their own, 0 to 1
    fillOpacity?: number;
    strokeOpacity?: number;
    // A line that fades: to a second colour, or through stops, at an angle
    // as CSS writes one (90, the default, left to right); a connector's
    // fades from its start to its end
    strokeGradient?: string;
    strokeStops?: GradientStop[];
    strokeAngle?: number;
    // A shadow of its own, in place of the soft one `shadow` puts under it
    shadowColor?: string;
    shadowBlur?: number;
    shadowX?: number;
    shadowY?: number;
    shadowOpacity?: number;
    // A light round it, in a colour, so far out
    glow?: string;
    glowSize?: number;
    // Softened, by so many board units; and how it mixes with what is under
    blur?: number;
    blend?: Blend;
    // Its words spread apart, set in capitals, or fading to a second colour
    letterSpacing?: number;
    caps?: boolean;
    colorTo?: string;
    // A picture filling its box ("cover"): the point of it kept in view,
    // 0 to 1 across and down; left out, the middle
    focusX?: number;
    focusY?: number;
    // As tall as its words need, growing and shrinking with them
    hug?: boolean;
    // A video only: a picture shown in its place until it plays, and the
    // moment of it shown otherwise, in seconds (left out, its first frame)
    poster?: string;
    posterAt?: number;
    // A frame only: what the presenter says over it, shown to them alone
    notes?: string;
    // A frame only: the part of the deck it begins, for a footer's {section}
    section?: string;
    // A frame only: no header, footer or number of the deck's on it
    bare?: boolean;
    // A path only: its points as SVG path data, starting at 0,0, and the box
    // they span -- drawn scaled from that box to the item's
    d?: string;
    viewWidth?: number;
    viewHeight?: number;
    // Connectors only: where each end is pinned and how the line is drawn
    from: Endpoint | null;
    to: Endpoint | null;
    routing: Routing;
    lineStyle: LineStyle;
    lineWidth: number;
    startHead: HeadType;
    endHead: HeadType;
    headSize: number;
};

// A symbol is picked with the tool, as "symbol:resistor"
export type Tool = 'select' | ItemKind | `symbol:${string}`;

/** The kind of item a tool draws, and which symbol when it draws one. */
export const drawnBy = (tool: Tool): { kind: ItemKind; symbol?: string } =>
    tool.startsWith('symbol:')
        ? { kind: 'symbol', symbol: tool.slice(7) }
        : { kind: tool as ItemKind };

export const STICKY_COLOURS = [
    '#fde68a',
    '#bbf7d0',
    '#bfdbfe',
    '#fbcfe8',
    '#ddd6fe',
];

/** Presets beside the colour picker -- a starting point, not the whole range. */
export const PALETTE = [
    '#ffffff',
    '#0f172a',
    '#64748b',
    '#ef4444',
    '#f97316',
    '#fde68a',
    '#22c55e',
    '#bbf7d0',
    '#0ea5e9',
    '#bfdbfe',
    '#6366f1',
    '#ddd6fe',
    '#ec4899',
    '#fbcfe8',
];

export const SHAPE_FILL = '#ffffff';
export const INK = '#0f172a';

let counter = 0;

export const newId = (): string => `i${++counter}`;

/** Carry on numbering past the ids of a board that was loaded. */
export const bumpIdsTo = (highest: number): void => {
    counter = Math.max(counter, highest);
};

/**
 * Items that hold editable text: everything except ink, arrows and videos, so
 * a database or a decision diamond can be labelled by double-clicking it. A
 * video is double-clicked to watch it full size instead.
 */
export const hasText = (item: Item): boolean =>
    item.kind !== 'arrow' &&
    item.kind !== 'draw' &&
    item.kind !== 'path' &&
    item.kind !== 'video' &&
    // A junction is only its dot
    !(item.kind === 'symbol' && symbolOf(item.symbol)?.labels === 'none');

/** Items drawn from a point list rather than a box. */
export const isStroke = (item: Item): boolean =>
    item.kind === 'arrow' || item.kind === 'draw';

/** A connector: two ends, each either pinned to a shape or to a point. */
export const isConnector = (item: Item): boolean => item.kind === 'arrow';

/**
 * Shapes with an edge of their own, as heavy as their lineWidth -- down to
 * none at all. A sticky and a frame are cards, and text is only its words.
 */
export const hasOutline = (item: Item): boolean =>
    item.kind === 'rect' ||
    item.kind === 'pill' ||
    item.kind === 'ellipse' ||
    item.kind === 'star' ||
    item.kind === 'math' ||
    item.kind === 'image' ||
    POLYGONS.includes(item.kind) ||
    isPath(item.kind);

/** Shapes a connector is allowed to pin itself to. */
export const isConnectable = (item: Item): boolean =>
    item.kind !== 'arrow' && item.kind !== 'draw' && item.kind !== 'frame';

export const makeItem = (
    kind: ItemKind,
    x: number,
    y: number,
    colours: { fill?: string; stroke?: string; symbol?: string } = {},
): Item => {
    const base: Item = {
        id: newId(),
        kind,
        x,
        y,
        width: 200,
        height: 140,
        rotation: 0,
        fill: SHAPE_FILL,
        stroke: '#cbd5e1',
        text: '',
        fontSize: 16,
        fontFamily: 'sans',
        padding: 12,
        rich: false,
        align: 'center',
        verticalAlign: 'middle',
        points: [],
        hidden: false,
        locked: false,
        pdfHidden: false,
        src: '',
        fit: 'fill',
        symbol: '',
        on: false,
        from: null,
        to: null,
        routing: 'elbow',
        lineStyle: 'solid',
        lineWidth: 2,
        startHead: 'none',
        endHead: 'arrow',
        headSize: 10,
    };

    switch (kind) {
        case 'frame':
            return {
                ...base,
                width: 960,
                height: 540, // 16:9, so a frame reads as a slide
                fill: '#ffffff',
                stroke: '#94a3b8',
                text: 'Frame',
            };
        case 'sticky':
            return {
                ...base,
                width: 180,
                height: 180,
                fill: STICKY_COLOURS[counter % STICKY_COLOURS.length],
                stroke: 'transparent',
                text: 'Double-click to type',
            };
        case 'text':
            return {
                ...base,
                width: 260,
                height: 40,
                fill: 'transparent',
                stroke: 'transparent',
                text: 'Text',
                fontSize: 28,
                // A text item is only its words: they start at its edge
                padding: 0,
                align: 'left',
                verticalAlign: 'top',
            };
        case 'ellipse':
            return {
                ...base,
                width: 200,
                height: 200,
                fill: colours.fill ?? base.fill,
                stroke: colours.stroke ?? base.stroke,
            };
        case 'star':
            return {
                ...base,
                width: 180,
                height: 180,
                fill: colours.fill ?? '#fde68a',
                stroke: colours.stroke ?? base.stroke,
            };
        case 'image':
            return {
                ...base,
                width: 200,
                height: 200,
                fill: 'transparent',
                stroke: 'transparent',
            };
        case 'video':
            return {
                ...base,
                // 16:9, the shape most videos are; any other plays with
                // black bars round it
                width: 480,
                height: 270,
                fill: '#000000',
                stroke: 'transparent',
            };
        case 'math':
            return {
                ...base,
                width: 260,
                height: 90,
                fill: 'transparent',
                stroke: 'transparent',
                fontSize: 24,
                text: 'a^2 + b^2 = c^2',
            };
        case 'cylinder':
            return {
                ...base,
                width: 180,
                height: 200,
                fill: colours.fill ?? '#e0e7ff',
                stroke: colours.stroke ?? '#6366f1',
            };
        case 'symbol': {
            // Drawn as the library draws it: its own size, in ink, with its
            // name and value set small beside it
            const symbol =
                symbolOf(colours.symbol ?? '') ?? symbolOf('resistor');

            return {
                ...base,
                symbol: symbol?.id ?? '',
                width: symbol?.width ?? 80,
                height: symbol?.height ?? 40,
                fill: 'transparent',
                stroke: INK,
                fontSize: 13,
                padding: 0,
            };
        }
        case 'parallelogram':
        case 'document':
        case 'process':
        case 'cloud':
        case 'triangle':
        case 'diamond':
        case 'hexagon':
        case 'pill':
        case 'rect':
            return {
                ...base,
                fill: colours.fill ?? base.fill,
                stroke: colours.stroke ?? base.stroke,
            };
        case 'arrow':
        case 'draw':
            return {
                ...base,
                width: 0,
                height: 0,
                fill: 'transparent',
                stroke: colours.stroke ?? INK,
                points: [],
            };
        default: {
            // A shape from the library starts as the library says it does
            const shape = shapeOf(kind);

            if (!shape) {
                return base;
            }

            const made: Item = {
                ...base,
                ...(shape.defaults as Partial<Item> | undefined),
                fill:
                    colours.fill ??
                    (shape.defaults?.fill as string | undefined) ??
                    base.fill,
                stroke:
                    colours.stroke ??
                    (shape.defaults?.stroke as string | undefined) ??
                    base.stroke,
            };

            // A table or a class starts with rows of its own, and as tall
            // as they need
            if (shape.rows) {
                made.rows = (
                    (shape.defaults?.rows as Partial<Row>[] | undefined) ?? []
                ).map((row) => makeRow(shape.rows!, { ...row, id: undefined }));
                made.height = rowsHeight(made);
            }

            return made;
        }
    }
};

/**
 * A saved item made whole again. Anything missing from it -- a field a board
 * written by an MCP client never mentioned, or one that came back as null --
 * falls back to the default for its kind, so the canvas always has a string to
 * draw and a list of points to follow.
 */
export const hydrate = (saved: Partial<Item>): Item => {
    const item = makeItem(saved.kind ?? 'rect', 0, 0, {
        symbol: saved.symbol,
    });

    for (const [field, value] of Object.entries(saved)) {
        if (value !== null && value !== undefined) {
            (item as Record<string, unknown>)[field] = value;
        }
    }

    return item;
};

export const nameOf = (item: Item): string => {
    const line = item.text.trim().split('\n')[0];
    // A rich label is named by its words, not by the marks that set them
    const label = item.rich
        ? line
              .replace(/^(#{1,3}|[-*•])\s+/, '')
              .replace(/\*\*|(?<![A-Za-z0-9])[*_]|[*_](?![A-Za-z0-9])/g, '')
              .trim()
        : line;

    if (label) {
        return label.length > 22 ? `${label.slice(0, 22)}…` : label;
    }

    switch (item.kind) {
        case 'arrow':
            return 'Connector';
        case 'draw':
            return 'Ink';
        case 'image':
            return 'Picture';
        case 'video':
            return 'Video';
        case 'math':
            return 'Formula';
        case 'symbol':
            return symbolOf(item.symbol)?.label ?? 'Symbol';
        case 'cylinder':
            return 'Database';
        case 'parallelogram':
            return 'Data';
        case 'process':
            return 'Predefined process';
        default:
            return (
                shapeOf(item.kind)?.label ??
                item.kind.charAt(0).toUpperCase() + item.kind.slice(1)
            );
    }
};
