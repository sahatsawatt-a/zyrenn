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

export const HEAD_TYPES: { value: HeadType; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'arrow', label: 'Arrow' },
    { value: 'open', label: 'Open' },
    { value: 'circle', label: 'Circle' },
    { value: 'diamond', label: 'Diamond' },
    { value: 'bar', label: 'Bar' },
];
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
    // A picture -- SVG, PNG, JPEG -- as a data URL drawn through an <img>
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
 * Items that hold editable text: everything except ink and arrows, so a
 * database or a decision diamond can be labelled by double-clicking it.
 */
export const hasText = (item: Item): boolean =>
    item.kind !== 'arrow' && item.kind !== 'draw';

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

export const SIDES: Side[] = ['top', 'right', 'bottom', 'left'];

/** Where a side's anchor sits on a shape, in board coordinates. */
export const anchorAt = (item: Item, side: Side) => {
    const { x, y, width, height } = boundsOf(item);

    switch (side) {
        case 'top':
            return { x: x + width / 2, y };
        case 'bottom':
            return { x: x + width / 2, y: y + height };
        case 'left':
            return { x, y: y + height / 2 };
        default:
            return { x: x + width, y: y + height / 2 };
    }
};

export const anchorsOf = (item: Item) =>
    SIDES.map((side) => ({ side, ...anchorAt(item, side) }));

/** The anchor of `item` closest to a point -- what a connector should grab. */
export const nearestSide = (
    item: Item,
    point: { x: number; y: number },
): Side => {
    let best: Side = 'right';
    let shortest = Infinity;

    for (const side of SIDES) {
        const anchor = anchorAt(item, side);
        const distance = (anchor.x - point.x) ** 2 + (anchor.y - point.y) ** 2;

        if (distance < shortest) {
            shortest = distance;
            best = side;
        }
    }

    return best;
};

/**
 * The side an end leaves by. A stored side is one somebody chose, so it is
 * kept; without one the end follows the shapes, turning to face whatever sits
 * at the other end of the line.
 */
export const sideOf = (
    end: Endpoint | null,
    host: Item | null,
    facing: { x: number; y: number },
): Side | null => {
    if (!host) {
        return null;
    }

    return end?.side ?? nearestSide(host, facing);
};

export const endpointAt = (end: Endpoint | null, byId: Map<string, Item>) => {
    if (!end) {
        return null;
    }

    const host = end.item ? (byId.get(end.item) ?? null) : null;
    const side = sideOf(end, host, { x: end.x, y: end.y });

    return host && side ? anchorAt(host, side) : { x: end.x, y: end.y };
};

const STUB = 24;

const stubbed = (
    point: { x: number; y: number },
    side: Side | null,
): { x: number; y: number } => {
    switch (side) {
        case 'top':
            return { x: point.x, y: point.y - STUB };
        case 'bottom':
            return { x: point.x, y: point.y + STUB };
        case 'left':
            return { x: point.x - STUB, y: point.y };
        case 'right':
            return { x: point.x + STUB, y: point.y };
        default:
            return point;
    }
};

/**
 * Where the elbow makes its turn, along the axis the stubs travel in.
 *
 * Halfway between them, unless that lands behind one of the stubs: an end
 * leaving downwards has to turn below where its stub finishes, or the line
 * doubles back on itself and leaves a tail hanging under the shape.
 */
const turnBetween = (
    axis: 'x' | 'y',
    out: { x: number; y: number },
    back: { x: number; y: number },
    fromSide: Side | null,
    toSide: Side | null,
): number => {
    const halfway = (out[axis] + back[axis]) / 2;

    // The side that pushes the turn further along this axis, and the one that
    // holds it back
    const onwards = axis === 'y' ? 'bottom' : 'right';
    const backwards = axis === 'y' ? 'top' : 'left';

    let atLeast = -Infinity;
    let atMost = Infinity;

    for (const [side, stub] of [
        [fromSide, out],
        [toSide, back],
    ] as const) {
        if (side === onwards) {
            atLeast = Math.max(atLeast, stub[axis]);
        }

        if (side === backwards) {
            atMost = Math.min(atMost, stub[axis]);
        }
    }

    // Two ends pointing at each other leave nowhere that suits both, and
    // halfway is as good as it gets
    if (atLeast > atMost) {
        return halfway;
    }

    return Math.min(Math.max(halfway, atLeast), atMost);
};

/**
 * A connector's path, in board coordinates: it leaves an anchor along that
 * side's normal, turns once in the middle and comes back in along the other
 * anchor's normal -- the elbow routing a diagram tool is expected to draw.
 * Ends that float take the straight line instead.
 */
export const connectorPoints = (
    item: Item,
    byId: Map<string, Item>,
): number[] => {
    if (!item.from || !item.to) {
        return [];
    }

    const fromHost = item.from.item ? (byId.get(item.from.item) ?? null) : null;
    const toHost = item.to.item ? (byId.get(item.to.item) ?? null) : null;

    const centreOf = (host: Item) => {
        const box = boundsOf(host);

        return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    };

    // An end with no side of its own turns to face wherever the other end is
    // now, rather than where it was when the line was drawn: drag a shape to
    // the far side and the line comes out of its other face, as it does in
    // every diagram tool. An end somebody put on a particular face stays on it.
    const fromSide = sideOf(
        item.from,
        fromHost,
        toHost ? centreOf(toHost) : { x: item.to.x, y: item.to.y },
    );
    const toSide = sideOf(
        item.to,
        toHost,
        fromHost ? centreOf(fromHost) : { x: item.from.x, y: item.from.y },
    );

    // The point and the side have to come from the same answer, or the line
    // leaves one face while turning as though it left another
    const start =
        fromHost && fromSide
            ? anchorAt(fromHost, fromSide)
            : { x: item.from.x, y: item.from.y };
    const end =
        toHost && toSide
            ? anchorAt(toHost, toSide)
            : { x: item.to.x, y: item.to.y };

    if (item.routing === 'straight' || (!fromSide && !toSide)) {
        return [start.x, start.y, end.x, end.y];
    }

    const out = stubbed(start, fromSide);
    const back = stubbed(end, toSide);

    // Curved: leave and arrive along the anchors' normals, and let Konva's
    // tension round off the two corners into one sweep.
    if (item.routing === 'curved') {
        return [start, out, back, end].flatMap((point) => [point.x, point.y]);
    }

    // Turn in the axis the first stub is already travelling along
    const midpoint =
        fromSide === 'left' || fromSide === 'right'
            ? [
                  {
                      x: turnBetween('x', out, back, fromSide, toSide),
                      y: out.y,
                  },
                  {
                      x: turnBetween('x', out, back, fromSide, toSide),
                      y: back.y,
                  },
              ]
            : [
                  {
                      x: out.x,
                      y: turnBetween('y', out, back, fromSide, toSide),
                  },
                  {
                      x: back.x,
                      y: turnBetween('y', out, back, fromSide, toSide),
                  },
              ];

    return [start, out, ...midpoint, back, end].flatMap((point) => [
        point.x,
        point.y,
    ]);
};

/** Dash pattern for a connector's line style. */
export const dashFor = (style: LineStyle): number[] => {
    switch (style) {
        case 'dashed':
            return [12, 8];
        case 'dotted':
            return [1, 7];
        default:
            return [];
    }
};

/** Halfway along a connector's path, where its label belongs. */
export const midpointOf = (points: number[]) => {
    if (points.length < 4) {
        return { x: 0, y: 0 };
    }

    const steps: { x: number; y: number }[] = [];

    for (let index = 0; index < points.length; index += 2) {
        steps.push({ x: points[index], y: points[index + 1] });
    }

    const lengths = steps
        .slice(1)
        .map((point, index) =>
            Math.hypot(point.x - steps[index].x, point.y - steps[index].y),
        );
    const total = lengths.reduce((sum, length) => sum + length, 0);

    let walked = 0;

    for (let index = 0; index < lengths.length; index++) {
        if (walked + lengths[index] >= total / 2) {
            const into = (total / 2 - walked) / (lengths[index] || 1);

            return {
                x:
                    steps[index].x +
                    (steps[index + 1].x - steps[index].x) * into,
                y:
                    steps[index].y +
                    (steps[index + 1].y - steps[index].y) * into,
            };
        }

        walked += lengths[index];
    }

    return steps[Math.floor(steps.length / 2)];
};

/**
 * Where each end of a connector sits and which way it points, so a head can be
 * drawn there. Konva's own Arrow draws one shape only; every other cap is a
 * shape of ours placed here and rotated to match the line.
 */
export const headsOf = (item: Item, points: number[]) => {
    if (points.length < 4) {
        return [];
    }

    const degrees = (dx: number, dy: number) =>
        (Math.atan2(dy, dx) * 180) / Math.PI;

    const [x1, y1, x2, y2] = points;
    const last = points.length;
    const [x3, y3, x4, y4] = points.slice(last - 4);

    return [
        {
            key: 'start' as const,
            type: item.startHead,
            x: x1,
            y: y1,
            // Points back out of the line, away from the second point
            rotation: degrees(x1 - x2, y1 - y2),
        },
        {
            key: 'end' as const,
            type: item.endHead,
            x: x4,
            y: y4,
            rotation: degrees(x4 - x3, y4 - y3),
        },
    ].filter((head) => head.type !== 'none');
};

/**
 * A head's outline, drawn pointing along +x from the line's end, so the shape
 * only has to be rotated into place.
 */
export const headPoints = (type: HeadType, size: number): number[] => {
    const width = size * 0.9;

    switch (type) {
        case 'arrow':
        case 'open':
            return [-size, -width / 2, 0, 0, -size, width / 2];
        case 'diamond':
            return [
                0,
                0,
                -size / 2,
                -width / 2,
                -size,
                0,
                -size / 2,
                width / 2,
            ];
        case 'bar':
            return [0, -width / 2, 0, width / 2];
        default:
            return [];
    }
};

/** How far back a head of this type needs the line to stop. */
const headRoom = (type: HeadType, size: number): number => {
    switch (type) {
        case 'arrow':
        case 'diamond':
            // Solid caps: the line would show through the middle of them
            return size * 0.9;
        case 'circle':
            return size * 0.4;
        default:
            // An open V or a bar is drawn on the line and wants it to arrive
            return 0;
    }
};

/** Move a point `distance` along the way towards `towards`. */
const pulledBack = (
    point: { x: number; y: number },
    towards: { x: number; y: number },
    distance: number,
) => {
    const span = Math.hypot(towards.x - point.x, towards.y - point.y);

    if (!span || !distance) {
        return point;
    }

    const ratio = Math.min(1, distance / span);

    return {
        x: point.x + (towards.x - point.x) * ratio,
        y: point.y + (towards.y - point.y) * ratio,
    };
};

/**
 * The line's own points: the full path with each end pulled back far enough to
 * sit behind its head, so a thick line does not show through a solid cap.
 * Heads are still placed on the untrimmed ends.
 */
export const trimmedPoints = (item: Item, points: number[]): number[] => {
    if (points.length < 4) {
        return points;
    }

    const trimmed = [...points];
    const last = trimmed.length;

    const start = pulledBack(
        { x: trimmed[0], y: trimmed[1] },
        { x: trimmed[2], y: trimmed[3] },
        headRoom(item.startHead, item.headSize),
    );
    const end = pulledBack(
        { x: trimmed[last - 2], y: trimmed[last - 1] },
        { x: trimmed[last - 4], y: trimmed[last - 3] },
        headRoom(item.endHead, item.headSize),
    );

    trimmed[0] = start.x;
    trimmed[1] = start.y;
    trimmed[last - 2] = end.x;
    trimmed[last - 1] = end.y;

    return trimmed;
};

/** A line the board shows while something is being dragged into line. */
export type Guide = {
    axis: 'x' | 'y';
    at: number;
    from: number;
    to: number;
};

type Box = { x: number; y: number; width: number; height: number };

/**
 * The nudge that puts a dragged box in line with the ones around it, and the
 * guides to draw for it: the "invisible ruler" of a board app. Each box offers
 * three lines per axis -- its two edges and its middle -- and the closest match
 * within `tolerance` wins.
 */
export const alignmentFor = (
    moving: Box,
    others: Box[],
    tolerance: number,
): { dx: number; dy: number; guides: Guide[] } => {
    const verticals = (box: Box) => [
        box.x,
        box.x + box.width / 2,
        box.x + box.width,
    ];
    const horizontals = (box: Box) => [
        box.y,
        box.y + box.height / 2,
        box.y + box.height,
    ];

    const best = (
        mine: number[],
        theirs: (box: Box) => number[],
    ): { shift: number; at: number; box: Box } | null => {
        let found: { shift: number; at: number; box: Box } | null = null;

        for (const box of others) {
            for (const line of theirs(box)) {
                for (const own of mine) {
                    const shift = line - own;

                    if (
                        Math.abs(shift) <= tolerance &&
                        (!found || Math.abs(shift) < Math.abs(found.shift))
                    ) {
                        found = { shift, at: line, box };
                    }
                }
            }
        }

        return found;
    };

    const vertical = best(verticals(moving), verticals);
    const horizontal = best(horizontals(moving), horizontals);

    const guides: Guide[] = [];

    // The guide runs the length of both boxes, so it reads as a ruler held
    // against them rather than a line crossing the whole board.
    if (vertical) {
        guides.push({
            axis: 'x',
            at: vertical.at,
            from: Math.min(moving.y, vertical.box.y) - 16,
            to:
                Math.max(
                    moving.y + moving.height,
                    vertical.box.y + vertical.box.height,
                ) + 16,
        });
    }

    if (horizontal) {
        guides.push({
            axis: 'y',
            at: horizontal.at,
            from: Math.min(moving.x, horizontal.box.x) - 16,
            to:
                Math.max(
                    moving.x + moving.width,
                    horizontal.box.x + horizontal.box.width,
                ) + 16,
        });
    }

    return {
        dx: vertical?.shift ?? 0,
        dy: horizontal?.shift ?? 0,
        guides,
    };
};

/** Keeps a picture to a sensible size on the board, in proportion. */
export const fitOnBoard = (width: number, height: number) => {
    const longest = Math.max(width, height);
    const factor = longest < 120 ? 200 / longest : Math.min(1, 480 / longest);

    return {
        width: Math.round(width * factor),
        height: Math.round(height * factor),
    };
};

/**
 * An SVG document as a data URL, with the size it asks for.
 *
 * The markup is never put in the page: it is handed to an <img>, which is the
 * one place a browser draws SVG without running anything inside it.
 */
export const svgSource = (
    markup: string,
): { src: string; width: number; height: number } => {
    const attribute = (name: string) =>
        markup.match(new RegExp(`<svg[^>]*\\s${name}="([^"]+)"`, 'i'))?.[1];

    const viewBox = attribute('viewBox')
        ?.trim()
        .split(/[\s,]+/)
        .map(Number);
    const number = (value: string | undefined) => {
        const parsed = parseFloat(value ?? '');

        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    };

    const width =
        number(attribute('width')) ??
        (viewBox?.length === 4 ? viewBox[2] : null) ??
        200;
    const height =
        number(attribute('height')) ??
        (viewBox?.length === 4 ? viewBox[3] : null) ??
        200;

    // Scaled so a 24px icon is usable on a board, keeping its proportions
    const longest = Math.max(width, height);
    const factor = longest < 120 ? 200 / longest : 1;

    // Inline in a page the parser infers the namespace, but an <img> loads the
    // document on its own and refuses it without one -- which is why markup
    // pasted straight from a web page can arrive lacking it.
    const document = /<svg[^>]*\sxmlns=/i.test(markup)
        ? markup
        : markup.replace(/<svg/i, '<svg xmlns="http://www.w3.org/2000/svg"');

    return {
        src: `data:image/svg+xml;base64,${btoa(unescape(encodeURIComponent(document)))}`,
        width: Math.round(width * factor),
        height: Math.round(height * factor),
    };
};

/** What the layers list calls an item. */
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
