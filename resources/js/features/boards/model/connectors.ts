// A connector: which faces it leaves by, the path it takes between them, and
// the caps drawn on each end.
import {
    GRID,
    isSymbol,
    nearestPin,
    pinOf,
} from '@/features/boards/circuit/symbols';
import type { Endpoint, HeadType, Item, LineStyle, Side } from './items';
import { isConnector } from './items';
import { hasRows, rowAnchor, rowNamed } from './rows';
import { shapeOf } from './shapes';
import { lineHops, runsOf } from '@/lib/lineHops';
import type { Hops } from '@/lib/lineHops';
import {
    anchorAt,
    boundsOf,
    isFlatSide,
    nearestSide,
    sideTowards,
} from './geometry';

/** What either end of a connector can be capped with, for the inspector. */
export const HEAD_TYPES: { value: HeadType; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'arrow', label: 'Arrow' },
    { value: 'open', label: 'Open' },
    { value: 'triangle', label: 'Hollow arrow (is a kind of)' },
    { value: 'circle', label: 'Circle' },
    { value: 'circle_open', label: 'Ring' },
    { value: 'diamond', label: 'Diamond (is made of)' },
    { value: 'diamond_open', label: 'Hollow diamond (has)' },
    { value: 'bar', label: 'Bar (one)' },
    { value: 'one_only', label: 'One and only one' },
    { value: 'many', label: 'Many' },
    { value: 'one_many', label: 'One or many' },
    { value: 'zero_one', label: 'Zero or one' },
    { value: 'zero_many', label: 'Zero or many' },
];

/** What a line is capped and drawn with, as one choice. */
export type ConnectorLook = Pick<Item, 'startHead' | 'endHead' | 'lineStyle'>;

/**
 * The kinds of line a diagram is drawn with, each a pair of caps and a dash
 * chosen together: picking "Inherits" is quicker than knowing it is a solid
 * line with a hollow arrow.
 */
export const CONNECTOR_TYPES: {
    id: string;
    label: string;
    group: 'Lines' | 'Class diagram' | 'Data model';
    look: ConnectorLook;
}[] = [
    {
        id: 'arrow',
        label: 'Arrow',
        group: 'Lines',
        look: { startHead: 'none', endHead: 'arrow', lineStyle: 'solid' },
    },
    {
        id: 'line',
        label: 'Line',
        group: 'Lines',
        look: { startHead: 'none', endHead: 'none', lineStyle: 'solid' },
    },
    {
        id: 'two-way',
        label: 'Both ways',
        group: 'Lines',
        look: { startHead: 'arrow', endHead: 'arrow', lineStyle: 'solid' },
    },
    {
        id: 'dashed',
        label: 'Dashed arrow',
        group: 'Lines',
        look: { startHead: 'none', endHead: 'arrow', lineStyle: 'dashed' },
    },
    {
        id: 'association',
        label: 'Uses',
        group: 'Class diagram',
        look: { startHead: 'none', endHead: 'open', lineStyle: 'solid' },
    },
    {
        id: 'dependency',
        label: 'Depends on',
        group: 'Class diagram',
        look: { startHead: 'none', endHead: 'open', lineStyle: 'dashed' },
    },
    {
        id: 'inheritance',
        label: 'Is a kind of',
        group: 'Class diagram',
        look: { startHead: 'none', endHead: 'triangle', lineStyle: 'solid' },
    },
    {
        id: 'realization',
        label: 'Implements',
        group: 'Class diagram',
        look: { startHead: 'none', endHead: 'triangle', lineStyle: 'dashed' },
    },
    {
        id: 'aggregation',
        label: 'Has',
        group: 'Class diagram',
        look: {
            startHead: 'diamond_open',
            endHead: 'none',
            lineStyle: 'solid',
        },
    },
    {
        id: 'composition',
        label: 'Is made of',
        group: 'Class diagram',
        look: { startHead: 'diamond', endHead: 'none', lineStyle: 'solid' },
    },
    {
        id: 'one-to-one',
        label: 'One to one',
        group: 'Data model',
        look: {
            startHead: 'one_only',
            endHead: 'one_only',
            lineStyle: 'solid',
        },
    },
    {
        id: 'one-to-many',
        label: 'One to many',
        group: 'Data model',
        look: {
            startHead: 'one_only',
            endHead: 'one_many',
            lineStyle: 'solid',
        },
    },
    {
        id: 'one-to-any',
        label: 'One to zero or many',
        group: 'Data model',
        look: {
            startHead: 'one_only',
            endHead: 'zero_many',
            lineStyle: 'solid',
        },
    },
    {
        id: 'one-to-optional',
        label: 'One to zero or one',
        group: 'Data model',
        look: {
            startHead: 'one_only',
            endHead: 'zero_one',
            lineStyle: 'solid',
        },
    },
    {
        id: 'many-to-many',
        label: 'Many to many',
        group: 'Data model',
        look: {
            startHead: 'one_many',
            endHead: 'one_many',
            lineStyle: 'solid',
        },
    },
];

/** The kind of line this one is drawn as, if it is one of them. */
export const connectorTypeOf = (line: ConnectorLook) =>
    CONNECTOR_TYPES.find(
        ({ look }) =>
            look.startHead === line.startHead &&
            look.endHead === line.endHead &&
            look.lineStyle === line.lineStyle,
    );

type Point = { x: number; y: number };
type Box = { x: number; y: number; width: number; height: number };

const centreOfBox = (box: Box): Point => ({
    x: box.x + box.width / 2,
    y: box.y + box.height / 2,
});

const hostOf = (end: Endpoint | null, byId: Map<string, Item>) =>
    end?.item ? (byId.get(end.item) ?? null) : null;

/** What an end is on, as a box: its shape's, or a point where it floats. */
const boxOfEnd = (end: Endpoint, host: Item | null): Box =>
    host ? boundsOf(host) : { x: end.x, y: end.y, width: 0, height: 0 };

/**
 * The faces of one box that look towards another: those the other's middle
 * lies beyond. None when the two lie over each other.
 */
const facesTowards = (box: Box, other: Box): Side[] => {
    const { x, y } = centreOfBox(other);

    return [
        x > box.x + box.width ? ('right' as const) : null,
        x < box.x ? ('left' as const) : null,
        y > box.y + box.height ? ('bottom' as const) : null,
        y < box.y ? ('top' as const) : null,
    ].filter((side) => side !== null);
};

/**
 * Whether an end just hangs off its shape's face, rather than holding a pin
 * of a symbol, a row of a table or a place along a lifeline -- which say for
 * themselves where it is and which way it leaves.
 */
const onFace = (end: Endpoint, host: Item | null): host is Item =>
    !!host &&
    !isSymbol(host) &&
    !(end.pin && hasRows(host) && rowNamed(host, end.pin)) &&
    !(typeof end.along === 'number' && shapeOf(host.kind)?.slide);

/**
 * A turn costs a line this much, in board units of length, when its faces
 * are chosen: enough that one corner beats the two of a Z about as long.
 */
const BEND = 60;

/** How long a path is, with each of its turns counted as BEND more. */
const costOf = (path: Point[]) =>
    path
        .slice(1)
        .reduce(
            (sum, point, index) =>
                sum +
                Math.abs(point.x - path[index].x) +
                Math.abs(point.y - path[index].y),
            0,
        ) +
    BEND * Math.max(0, path.length - 2);

/**
 * The faces a connector's ends leave by, where they hang off a face (an end
 * on a pin, a row or a lifeline is placed by that, and is null here, as is
 * an end that floats). A stored side is one somebody chose, so it is kept.
 * Without one an end turns to face the other: of the faces of each shape
 * that look towards the other, the pair an elbow joins by the shortest
 * line with the fewest turns -- straight over a gap, round a corner by one
 * bend -- or, for a curve or a straight line, the nearest pair.
 * Shapes lying over each other face nothing, and an end then takes the face
 * nearest the middle of the other.
 */
export const sidesOf = (
    item: Item,
    byId: Map<string, Item>,
): { from: Side | null; to: Side | null } => {
    if (!item.from || !item.to) {
        return { from: null, to: null };
    }

    const fromHost = hostOf(item.from, byId);
    const toHost = hostOf(item.to, byId);
    const fromBox = boxOfEnd(item.from, fromHost);
    const toBox = boxOfEnd(item.to, toHost);

    const choices = (end: Endpoint, host: Item | null, box: Box, other: Box) =>
        !onFace(end, host)
            ? [null]
            : end.side
              ? [end.side]
              : (() => {
                    const faces = facesTowards(box, other);

                    return faces.length
                        ? faces
                        : [nearestSide(host, centreOfBox(other))];
                })();

    const froms = choices(item.from, fromHost, fromBox, toBox);
    const tos = choices(item.to, toHost, toBox, fromBox);

    if (froms.length === 1 && tos.length === 1) {
        return { from: froms[0], to: tos[0] };
    }

    let best = { from: froms[0], to: tos[0], cost: Infinity };

    for (const from of froms) {
        for (const to of tos) {
            const start =
                from && fromHost
                    ? anchorAt(fromHost, from)
                    : centreOfBox(fromBox);
            const end =
                to && toHost ? anchorAt(toHost, to) : centreOfBox(toBox);
            // A curve or a straight line just takes the nearest faces
            const cost =
                item.routing === 'elbow'
                    ? costOf(elbowPath(start, from, end, to, STUB))
                    : Math.hypot(end.x - start.x, end.y - start.y);

            if (cost < best.cost) {
                best = { from, to, cost };
            }
        }
    }

    return { from: best.from, to: best.to };
};

/** Each connector's faces (sidesOf), by its id, worked out once for a board. */
export type SidesById = Map<string, { from: Side | null; to: Side | null }>;

export const sidesById = (items: Item[], byId: Map<string, Item>): SidesById =>
    new Map(
        items.filter(isConnector).map((item) => [item.id, sidesOf(item, byId)]),
    );

/** The connectors with an end on each item, by the item's id. */
export const attachmentsOf = (items: Item[]): Map<string, Item[]> => {
    const attached = new Map<string, Item[]>();

    for (const item of items) {
        if (!isConnector(item)) {
            continue;
        }

        for (const host of new Set([item.from?.item, item.to?.item])) {
            if (host) {
                attached.set(host, [...(attached.get(host) ?? []), item]);
            }
        }
    }

    return attached;
};

const centreOf = (host: Item) => {
    const box = boundsOf(host);

    return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
};

/** Where the far end of a connector is, seen from `end`. */
const otherEndOf = (
    item: Item,
    end: 'from' | 'to',
    byId: Map<string, Item>,
): { x: number; y: number } => {
    const other = end === 'from' ? item.to : item.from;
    const host = other?.item ? byId.get(other.item) : undefined;

    return host ? centreOf(host) : { x: other?.x ?? 0, y: other?.y ?? 0 };
};

const OPPOSITE: Record<Side, Side> = {
    left: 'right',
    right: 'left',
    top: 'bottom',
    bottom: 'top',
};

/** How far in from a side's corners an end may be put. */
const CORNER = 8;

/**
 * Where along its side an end alone there goes so its line runs straight
 * across a gap: level with the far end, when that is on the face opposite
 * and within reach of this one. Both alone on their faces, they meet in the
 * middle of the stretch the two faces share; the far end spread among
 * others, this one lines up with where it was put. Nothing when the faces
 * do not look at each other, or share no stretch.
 */
const levelWith = (
    item: Item,
    end: 'from' | 'to',
    host: Item,
    side: Side,
    byId: Map<string, Item>,
    attached: Map<string, Item[]>,
    sides?: SidesById,
): Point | null => {
    const far = end === 'from' ? 'to' : 'from';
    const farEnd = item[far];
    const farHost = hostOf(farEnd, byId);
    const farSide = (sides?.get(item.id) ?? sidesOf(item, byId))[far];

    if (
        !farEnd ||
        !farHost ||
        farHost.id === host.id ||
        farSide !== OPPOSITE[side] ||
        !isFlatSide(farHost, farSide)
    ) {
        return null;
    }

    const across = side === 'top' || side === 'bottom' ? 'x' : 'y';
    const reach = (box: Box) =>
        across === 'x'
            ? { low: box.x + CORNER, high: box.x + box.width - CORNER }
            : { low: box.y + CORNER, high: box.y + box.height - CORNER };
    const box = boundsOf(host);
    const mine = reach(box);
    const theirs = reach(boundsOf(farHost));

    // The far face has to lie ahead of this one, a gap between them
    const here = anchorAt(host, side);
    const there = anchorAt(farHost, farSide);

    if (!ahead(here, side, there)) {
        return null;
    }

    const farAlone =
        (attached.get(farHost.id) ?? []).flatMap((other) =>
            (['from', 'to'] as const).filter(
                (which) =>
                    other[which]?.item === farHost.id &&
                    (sides?.get(other.id) ?? sidesOf(other, byId))[which] ===
                        farSide,
            ),
        ).length < 2;

    const at: number = farAlone
        ? (Math.max(mine.low, theirs.low) + Math.min(mine.high, theirs.high)) /
          2
        : anchorFor(item, far, farHost, farSide, byId, attached, sides)[across];

    if (
        at < mine.low ||
        at > mine.high ||
        (farAlone &&
            Math.max(mine.low, theirs.low) > Math.min(mine.high, theirs.high))
    ) {
        return null;
    }

    const length = across === 'x' ? box.width : box.height;
    const start = across === 'x' ? box.x : box.y;

    return anchorAt(host, side, (at - start) / length);
};

/**
 * Where an end meets its shape. Alone on a side, the middle of it; sharing a
 * flat side with other connectors, they are spread evenly along it, in the
 * order their far ends lie, so their lines do not cross and their heads do
 * not land on one another.
 */
const anchorFor = (
    item: Item,
    end: 'from' | 'to',
    host: Item,
    side: Side,
    byId: Map<string, Item>,
    attached?: Map<string, Item[]>,
    sides?: SidesById,
): Point => {
    if (!attached || !isFlatSide(host, side)) {
        return anchorAt(host, side);
    }

    const across = side === 'top' || side === 'bottom' ? 'x' : 'y';
    const sharing = (attached.get(host.id) ?? []).flatMap((other) =>
        (['from', 'to'] as const)
            .filter((which) => other[which]?.item === host.id)
            .filter(
                (which) =>
                    (sides?.get(other.id) ?? sidesOf(other, byId))[which] ===
                    side,
            )
            .map((which) => ({
                key: `${other.id}:${which}`,
                at: otherEndOf(other, which, byId)[across],
            })),
    );

    if (sharing.length < 2) {
        return (
            levelWith(item, end, host, side, byId, attached, sides) ??
            anchorAt(host, side)
        );
    }

    sharing.sort((a, b) => a.at - b.at || a.key.localeCompare(b.key));

    const place = sharing.findIndex(
        (other) => other.key === `${item.id}:${end}`,
    );

    return anchorAt(host, side, (place + 1) / (sharing.length + 1));
};

/**
 * The pin an end is on, when what it hangs off is a symbol: the one it
 * names, or failing that the one nearest the far end of the line.
 */
export const pinFor = (
    end: Endpoint | null,
    host: Item | null,
    facing: { x: number; y: number },
) =>
    host && isSymbol(host)
        ? ((end?.pin ? pinOf(host, end.pin) : undefined) ??
          nearestPin(host, facing))
        : undefined;

/** The edge of a shape an end leaves by when it may only be left or right. */
const edgeFacing = (
    end: Endpoint,
    host: Item,
    facing: { x: number; y: number },
): 'left' | 'right' =>
    end.side === 'left' || end.side === 'right'
        ? end.side
        : facing.x >= host.x + host.width / 2
          ? 'right'
          : 'left';

/**
 * Where an end is held on a shape with a structure of its own: on the row
 * of a table or a class it names, at that row's height, or as far along a
 * lifeline as it was dropped. Either way it leaves by whichever edge faces
 * the far end, unless it was given one.
 */
export const heldFor = (
    end: Endpoint | null,
    host: Item | null,
    facing: { x: number; y: number },
) => {
    if (!end || !host) {
        return undefined;
    }

    if (end.pin && hasRows(host)) {
        const row = rowNamed(host, end.pin);

        if (row) {
            const at = rowAnchor(host, row, edgeFacing(end, host, facing));

            return { name: row.id, x: at.x, y: at.y, side: at.side };
        }
    }

    if (typeof end.along === 'number' && shapeOf(host.kind)?.slide) {
        const side = edgeFacing(end, host, facing);

        return {
            name: '',
            side: side as Side,
            ...anchorAt(host, side, Math.min(1, Math.max(0, end.along))),
        };
    }

    return undefined;
};

/** A wire: a connector with an end on a pin. It is drawn as one, too. */
export const isWire = (item: Item, byId: Map<string, Item>): boolean =>
    [item.from, item.to].some((end) => {
        const host = end?.item ? byId.get(end.item) : undefined;

        return !!host && isSymbol(host);
    });

export const STUB = 24;

const stubbed = (
    point: { x: number; y: number },
    side: Side | null,
    length = STUB,
): { x: number; y: number } => {
    switch (side) {
        case 'top':
            return { x: point.x, y: point.y - length };
        case 'bottom':
            return { x: point.x, y: point.y + length };
        case 'left':
            return { x: point.x - length, y: point.y };
        case 'right':
            return { x: point.x + length, y: point.y };
        default:
            return point;
    }
};

const upright = (side: Side | null) => side === 'top' || side === 'bottom';

/** Whether going from a point by this side heads towards `to`. */
const ahead = (
    from: { x: number; y: number },
    side: Side,
    to: { x: number; y: number },
) => {
    switch (side) {
        case 'top':
            return to.y < from.y;
        case 'bottom':
            return to.y > from.y;
        case 'left':
            return to.x < from.x;
        default:
            return to.x > from.x;
    }
};

/**
 * The one corner a line needs when its ends leave at right angles to each
 * other and the corner lies ahead of both, by at least `least`: out of the
 * first, round it, and into the second. Nothing when the ends face the same
 * way, or the corner would be behind one of them -- the elbow's two turns
 * are needed then.
 */
const cornerBetween = (
    start: Point,
    fromSide: Side | null,
    end: Point,
    toSide: Side | null,
    least = 0,
) => {
    if (!fromSide || !toSide || upright(fromSide) === upright(toSide)) {
        return null;
    }

    const corner = upright(fromSide)
        ? { x: start.x, y: end.y }
        : { x: end.x, y: start.y };
    const far = (from: Point) =>
        Math.abs(corner.x - from.x) + Math.abs(corner.y - from.y) >= least;

    return ahead(start, fromSide, corner) &&
        ahead(end, toSide, corner) &&
        far(start) &&
        far(end)
        ? corner
        : null;
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
    out: Point,
    back: Point,
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

/** A path without the points that are no corner: repeats, and points on a straight run. */
export const simplified = (path: Point[]): Point[] => {
    const kept: Point[] = [];

    for (const point of path) {
        const last = kept[kept.length - 1];

        if (
            last &&
            Math.abs(last.x - point.x) < 0.01 &&
            Math.abs(last.y - point.y) < 0.01
        ) {
            continue;
        }

        const before = kept[kept.length - 2];

        if (
            before &&
            ((Math.abs(before.x - last.x) < 0.01 &&
                Math.abs(last.x - point.x) < 0.01 &&
                (last.y - before.y) * (point.y - last.y) > 0) ||
                (Math.abs(before.y - last.y) < 0.01 &&
                    Math.abs(last.y - point.y) < 0.01 &&
                    (last.x - before.x) * (point.x - last.x) > 0))
        ) {
            kept[kept.length - 1] = point;
            continue;
        }

        kept.push(point);
    }

    return kept;
};

/**
 * The elbow between two anchors: once round a corner where once will do,
 * otherwise out along the first anchor's normal, a turn in the middle, and
 * back in along the other's -- the routing a diagram tool is expected to
 * draw. It knows nothing of what lies on the way; connectorRoutes does.
 */
export const elbowPath = (
    start: Point,
    fromSide: Side | null,
    end: Point,
    toSide: Side | null,
    stub: number,
    // How far ahead of both ends a single corner has to be: none for a
    // wire, which turns on its pins' grid; a stub for a line with a head
    least = stub,
): Point[] => {
    const corner = cornerBetween(start, fromSide, end, toSide, least);

    if (corner) {
        return [start, corner, end];
    }

    const out = stubbed(start, fromSide, stub);
    const back = stubbed(end, toSide, stub);

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

    return simplified([start, out, ...midpoint, back, end]);
};

/** Where a connector's ends are, which faces they leave by, and what by. */
export type Ends = {
    start: Point;
    end: Point;
    fromSide: Side | null;
    toSide: Side | null;
    fromHost: Item | null;
    toHost: Item | null;
    // An end on a pin: the line is a wire, on the pins' grid
    wire: boolean;
    // An end along a lifeline: a message, drawn straight across
    along: boolean;
};

/**
 * Where a connector's ends meet their shapes and which way they leave them.
 * Nothing for a connector missing an end.
 */
export const endsOf = (
    item: Item,
    byId: Map<string, Item>,
    // Every connector's shapes (attachmentsOf), so ends sharing a side can be
    // spread along it; without it each end takes its side's middle
    attached?: Map<string, Item[]>,
    // Every connector's faces (sidesById), worked out once for a whole board
    sides?: SidesById,
): Ends | null => {
    if (!item.from || !item.to) {
        return null;
    }

    const fromHost = hostOf(item.from, byId);
    const toHost = hostOf(item.to, byId);

    const fromFacing = toHost
        ? centreOf(toHost)
        : { x: item.to.x, y: item.to.y };
    const toFacing = fromHost
        ? centreOf(fromHost)
        : { x: item.from.x, y: item.from.y };

    // An end on a symbol is on one of its pins, and leaves the way the pin
    // points; a junction's pin points nowhere, and is left towards the far end
    // ...and one on a table's row or along a lifeline is held there the same way
    const fromHeld = heldFor(item.from, fromHost, fromFacing);
    const toHeld = heldFor(item.to, toHost, toFacing);
    const fromPin = pinFor(item.from, fromHost, fromFacing) ?? fromHeld;
    const toPin = pinFor(item.to, toHost, toFacing) ?? toHeld;

    // An end with no side of its own turns to face wherever the other end is
    // now, rather than where it was when the line was drawn: drag a shape to
    // the far side and the line comes out of its other face, as it does in
    // every diagram tool. An end somebody put on a particular face stays on it.
    // (An end held on a row or along a lifeline leaves by the edge it is on.)
    const faces = sides?.get(item.id) ?? sidesOf(item, byId);
    const fromSide = fromHeld
        ? fromHeld.side
        : fromPin
          ? (item.from.side ??
            fromPin.side ??
            sideTowards(fromPin, toPin ?? fromFacing))
          : faces.from;
    const toSide = toHeld
        ? toHeld.side
        : toPin
          ? (item.to.side ??
            toPin.side ??
            sideTowards(toPin, fromPin ?? toFacing))
          : faces.to;

    // The point and the side have to come from the same answer, or the line
    // leaves one face while turning as though it left another
    const start = fromPin
        ? { x: fromPin.x, y: fromPin.y }
        : fromHost && fromSide
          ? anchorFor(item, 'from', fromHost, fromSide, byId, attached, sides)
          : { x: item.from.x, y: item.from.y };
    const end = toPin
        ? { x: toPin.x, y: toPin.y }
        : toHost && toSide
          ? anchorFor(item, 'to', toHost, toSide, byId, attached, sides)
          : { x: item.to.x, y: item.to.y };

    return {
        start,
        end,
        fromSide,
        toSide,
        fromHost,
        toHost,
        wire: (!!fromPin && !fromHeld) || (!!toPin && !toHeld),
        along:
            typeof item.from.along === 'number' ||
            typeof item.to.along === 'number',
    };
};

/**
 * A connector's path between its ends, on its own: straight, one curve, or
 * the plain elbow. On a board, connectorRoutes takes elbows round the other
 * items and keeps lines apart; this is the path it starts from.
 */
export const pathBetween = (item: Item, ends: Ends): number[] => {
    const { start, end, fromSide, toSide, wire } = ends;

    if (item.routing === 'straight' || (!fromSide && !toSide)) {
        return [start.x, start.y, end.x, end.y];
    }

    // Curved: one cubic sweep -- start, two handles, end -- leaving and
    // arriving along the anchors' normals, its handles reaching out further
    // the further apart the ends are. A spline through the short stubs
    // instead overshot them, looping where the line left and hooking where
    // it arrived.
    if (item.routing === 'curved') {
        const reach = Math.max(
            STUB,
            Math.hypot(end.x - start.x, end.y - start.y) * 0.4,
        );

        return [
            start,
            stubbed(start, fromSide, reach),
            stubbed(end, toSide, reach),
            end,
        ].flatMap((point) => [point.x, point.y]);
    }

    // A wire keeps to the grid its pins are on, and turns a corner once
    // where once will do: out of a pin, round, and straight into the other
    return elbowPath(
        start,
        fromSide,
        end,
        toSide,
        wire ? GRID : STUB,
        wire ? 0 : STUB,
    ).flatMap((point) => [point.x, point.y]);
};

/**
 * A connector's path, in board coordinates, worked out on its own: see
 * pathBetween. Ends that float take the straight line instead.
 */
export const connectorPoints = (
    item: Item,
    byId: Map<string, Item>,
    // Every connector's shapes (attachmentsOf), so ends sharing a side can be
    // spread along it; without it each end takes its side's middle
    attached?: Map<string, Item[]>,
): number[] => {
    const ends = endsOf(item, byId, attached);

    return ends ? pathBetween(item, ends) : [];
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

/**
 * Whether a connector's points are a Bezier (start, two handles, end) rather
 * than a polyline: a curved one, unless it fell back to a straight line.
 */
export const isSwept = (item: Item, points: number[]) =>
    item.routing === 'curved' && points.length === 8;

// Close enough for a label's backing chip: Konva would have to measure the
// text to do better, and this only has to keep the line out of the words.
export const labelWidth = (item: Item) =>
    Math.max(28, item.text.length * 7 + 16);

/** How tall a connector's label chip is. */
export const LABEL_HEIGHT = 22;

/** Halfway along a connector's path, where its label belongs. */
export const midpointOf = (points: number[], swept = false) => {
    if (points.length < 4) {
        return { x: 0, y: 0 };
    }

    // The middle of the curve itself; the handles are off it
    if (swept) {
        const [x0, y0, x1, y1, x2, y2, x3, y3] = points;

        return {
            x: (x0 + 3 * x1 + 3 * x2 + x3) / 8,
            y: (y0 + 3 * y1 + 3 * y2 + y3) / 8,
        };
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
 * One piece of a head: a line through points, closed or not, or a circle on
 * the line. Filled with the line's own colour ("ink"), with whatever lies
 * behind the line ("paper", which is how a hollow cap hides the line under
 * it), or not at all.
 */
export type HeadPart = {
    points?: number[];
    closed?: boolean;
    circle?: { x: number; radius: number };
    fill?: 'ink' | 'paper';
    // A solid dot is only its fill; every other piece is drawn in the line too
    stroked: boolean;
};

/**
 * A head's pieces, drawn pointing along +x with the line's end at the
 * origin, so the whole of it only has to be turned into place.
 */
export const headParts = (type: HeadType, size: number): HeadPart[] => {
    const width = size * 0.9;
    const half = width / 2;
    const point = [-size, -half, 0, 0, -size, half];
    const kite = [0, 0, -size / 2, -half, -size, 0, -size / 2, half];
    const bar = (x: number): HeadPart => ({
        points: [x, -half, x, half],
        stroked: true,
    });
    // The three toes of a crow's foot, spread where the line arrives
    const foot: HeadPart = {
        points: [0, -half, -size, 0, 0, half],
        stroked: true,
    };
    const ring = (x: number, radius: number): HeadPart => ({
        circle: { x, radius },
        fill: 'paper',
        stroked: true,
    });

    switch (type) {
        case 'arrow':
            return [
                { points: point, closed: true, fill: 'ink', stroked: true },
            ];
        case 'open':
            return [{ points: point, stroked: true }];
        case 'triangle':
            return [
                { points: point, closed: true, fill: 'paper', stroked: true },
            ];
        case 'diamond':
            return [{ points: kite, closed: true, fill: 'ink', stroked: true }];
        case 'diamond_open':
            return [
                { points: kite, closed: true, fill: 'paper', stroked: true },
            ];
        case 'circle':
            return [
                {
                    circle: { x: 0, radius: size * 0.4 },
                    fill: 'ink',
                    stroked: false,
                },
            ];
        case 'circle_open':
            return [ring(0, size * 0.4)];
        case 'bar':
            return [bar(0)];
        case 'one_only':
            return [bar(-size * 0.45), bar(-size * 0.9)];
        case 'many':
            return [foot];
        case 'one_many':
            return [foot, bar(-size * 1.3)];
        case 'zero_one':
            return [bar(-size * 0.45), ring(-size * 1.15, size * 0.32)];
        case 'zero_many':
            return [foot, ring(-size * 1.4, size * 0.32)];
        default:
            return [];
    }
};

/** How far back a head of this type needs the line to stop. */
export const headRoom = (type: HeadType, size: number): number => {
    switch (type) {
        case 'arrow':
        case 'diamond':
        case 'triangle':
        case 'diamond_open':
            // Solid caps: the line would show through the middle of them
            return size * 0.9;
        case 'circle':
        case 'circle_open':
            return size * 0.4;
        default:
            // An open V, a bar or a crow's foot is drawn on the line and
            // wants it to arrive
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

/** How big the bump is where a connector hops over another, by its weight. */
export const hopRadius = (item: Item): number =>
    Math.max(8, item.lineWidth * 4);

/**
 * Where each connector hops over another, by its id: worked out from the
 * paths the lines are drawn along. A curve sweeps past and hops nothing;
 * a line out of sight is not there to cross.
 */
export const connectorHops = (
    items: Item[],
    pathOf: (item: Item) => number[],
): Map<string, Hops> =>
    lineHops(
        items
            .filter((item) => isConnector(item) && !item.hidden)
            .flatMap((item) => {
                const points = pathOf(item);

                return points.length >= 4 && !isSwept(item, points)
                    ? [
                          {
                              id: item.id,
                              runs: runsOf(points),
                              radius: hopRadius(item),
                          },
                      ]
                    : [];
            }),
    );
