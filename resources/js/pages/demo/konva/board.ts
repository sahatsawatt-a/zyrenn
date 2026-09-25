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
    // Arrow and freehand keep their shape as points relative to x/y
    points: number[];
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

/**
 * Items that hold editable text: everything except ink and arrows, so a
 * database or a decision diamond can be labelled by double-clicking it.
 */
export const hasText = (item: Item): boolean =>
    item.kind !== 'arrow' && item.kind !== 'draw';

/** Items drawn from a point list rather than a box. */
export const isStroke = (item: Item): boolean =>
    item.kind === 'arrow' || item.kind === 'draw';

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
        points: [],
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

/** The box an item occupies, including stroke items built from points. */
export const boundsOf = (item: Item) => {
    if (!isStroke(item)) {
        return {
            x: item.x,
            y: item.y,
            width: item.width,
            height: item.height,
        };
    }

    const xs = item.points.filter((_, index) => index % 2 === 0);
    const ys = item.points.filter((_, index) => index % 2 === 1);

    if (!xs.length) {
        return { x: item.x, y: item.y, width: 0, height: 0 };
    }

    return {
        x: item.x + Math.min(...xs),
        y: item.y + Math.min(...ys),
        width: Math.max(...xs) - Math.min(...xs),
        height: Math.max(...ys) - Math.min(...ys),
    };
};

/** The box around several items, or null when the list is empty. */
export const boundsOfAll = (items: Item[]) => {
    if (!items.length) {
        return null;
    }

    const boxes = items.map(boundsOf);

    const left = Math.min(...boxes.map((box) => box.x));
    const top = Math.min(...boxes.map((box) => box.y));
    const right = Math.max(...boxes.map((box) => box.x + box.width));
    const bottom = Math.max(...boxes.map((box) => box.y + box.height));

    return { x: left, y: top, width: right - left, height: bottom - top };
};

export const overlaps = (
    a: { x: number; y: number; width: number; height: number },
    b: { x: number; y: number; width: number; height: number },
): boolean =>
    a.x < b.x + b.width &&
    a.x + a.width > b.x &&
    a.y < b.y + b.height &&
    a.y + a.height > b.y;

/**
 * Points for a closed polygon that fills the item's box, so triangles and the
 * rest resize with the Transformer like every other shape.
 */
export const polygonPoints = (item: Item): number[] => {
    const { width: w, height: h } = item;

    switch (item.kind) {
        case 'triangle':
            return [w / 2, 0, w, h, 0, h];
        case 'diamond':
            return [w / 2, 0, w, h / 2, w / 2, h, 0, h / 2];
        case 'hexagon':
            return [
                w * 0.25,
                0,
                w * 0.75,
                0,
                w,
                h / 2,
                w * 0.75,
                h,
                w * 0.25,
                h,
                0,
                h / 2,
            ];
        default:
            return [];
    }
};
