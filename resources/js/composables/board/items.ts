// The board's data model. Everything on the canvas is one flat list of items in
// paint order, which keeps z-order, undo snapshots and hit-testing simple.

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
    | 'image'
    // A video from the Drive or a link, played on the board (useVideos)
    | 'video'
    // A formula, written as LaTeX and set with KaTeX
    | 'math'
    | 'arrow'
    | 'draw';

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
};

export type Routing = 'elbow' | 'straight' | 'curved';

/** What either end of a connector is capped with. */
export type HeadType = 'none' | 'arrow' | 'open' | 'circle' | 'diamond' | 'bar';
export type LineStyle = 'solid' | 'dashed' | 'dotted';

/** Where a label sits in the box it is written in. */
export type Align = 'left' | 'center' | 'right';

export type VerticalAlign = 'top' | 'middle' | 'bottom';

export const ALIGNS: Align[] = ['left', 'center', 'right'];

export const VERTICAL_ALIGNS: VerticalAlign[] = ['top', 'middle', 'bottom'];

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
    align: Align;
    verticalAlign: VerticalAlign;
    // Freehand keeps its shape as points relative to x/y
    points: number[];
    // Out of sight, and out of reach of the pointer, from the layers list
    hidden: boolean;
    locked: boolean;
    // A picture -- SVG, PNG, JPEG -- as a data URL drawn through an <img>;
    // or, on a video, where the video is
    src: string;
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

export type Tool = 'select' | ItemKind;

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
    item.kind !== 'arrow' && item.kind !== 'draw' && item.kind !== 'video';

/** Items drawn from a point list rather than a box. */
export const isStroke = (item: Item): boolean =>
    item.kind === 'arrow' || item.kind === 'draw';

/** A connector: two ends, each either pinned to a shape or to a point. */
export const isConnector = (item: Item): boolean => item.kind === 'arrow';

/** Shapes a connector is allowed to pin itself to. */
export const isConnectable = (item: Item): boolean =>
    item.kind !== 'arrow' && item.kind !== 'draw' && item.kind !== 'frame';

export const makeItem = (
    kind: ItemKind,
    x: number,
    y: number,
    colours: { fill?: string; stroke?: string } = {},
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
        align: 'center',
        verticalAlign: 'middle',
        points: [],
        hidden: false,
        locked: false,
        src: '',
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
        default:
            return base;
    }
};

/**
 * A saved item made whole again. Anything missing from it -- a field a board
 * written by an MCP client never mentioned, or one that came back as null --
 * falls back to the default for its kind, so the canvas always has a string to
 * draw and a list of points to follow.
 */
export const hydrate = (saved: Partial<Item>): Item => {
    const item = makeItem(saved.kind ?? 'rect', 0, 0);

    for (const [field, value] of Object.entries(saved)) {
        if (value !== null && value !== undefined) {
            (item as Record<string, unknown>)[field] = value;
        }
    }

    return item;
};

export const nameOf = (item: Item): string => {
    const label = item.text.trim().split('\n')[0];

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
        case 'cylinder':
            return 'Database';
        case 'parallelogram':
            return 'Data';
        case 'process':
            return 'Predefined process';
        default:
            return item.kind.charAt(0).toUpperCase() + item.kind.slice(1);
    }
};
