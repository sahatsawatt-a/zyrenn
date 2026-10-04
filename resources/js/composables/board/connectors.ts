// A connector: which faces it leaves by, the path it takes between them, and
// the caps drawn on each end.
import type { Endpoint, HeadType, Item, LineStyle, Side } from './items';
import { isConnector } from './items';
import { anchorAt, boundsOf, isFlatSide, nearestSide } from './geometry';

/** What either end of a connector can be capped with, for the inspector. */
export const HEAD_TYPES: { value: HeadType; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'arrow', label: 'Arrow' },
    { value: 'open', label: 'Open' },
    { value: 'circle', label: 'Circle' },
    { value: 'diamond', label: 'Diamond' },
    { value: 'bar', label: 'Bar' },
];

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
) => {
    if (!attached || !isFlatSide(host, side)) {
        return anchorAt(host, side);
    }

    const across = side === 'top' || side === 'bottom' ? 'x' : 'y';
    const sharing = (attached.get(host.id) ?? []).flatMap((other) =>
        (['from', 'to'] as const)
            .filter((which) => other[which]?.item === host.id)
            .filter(
                (which) =>
                    sideOf(
                        other[which],
                        host,
                        otherEndOf(other, which, byId),
                    ) === side,
            )
            .map((which) => ({
                key: `${other.id}:${which}`,
                at: otherEndOf(other, which, byId)[across],
            })),
    );

    if (sharing.length < 2) {
        return anchorAt(host, side);
    }

    sharing.sort((a, b) => a.at - b.at || a.key.localeCompare(b.key));

    const place = sharing.findIndex(
        (other) => other.key === `${item.id}:${end}`,
    );

    return anchorAt(host, side, (place + 1) / (sharing.length + 1));
};

const STUB = 24;

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
    // Every connector's shapes (attachmentsOf), so ends sharing a side can be
    // spread along it; without it each end takes its side's middle
    attached?: Map<string, Item[]>,
): number[] => {
    if (!item.from || !item.to) {
        return [];
    }

    const fromHost = item.from.item ? (byId.get(item.from.item) ?? null) : null;
    const toHost = item.to.item ? (byId.get(item.to.item) ?? null) : null;

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
            ? anchorFor(item, 'from', fromHost, fromSide, byId, attached)
            : { x: item.from.x, y: item.from.y };
    const end =
        toHost && toSide
            ? anchorFor(item, 'to', toHost, toSide, byId, attached)
            : { x: item.to.x, y: item.to.y };

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

    const out = stubbed(start, fromSide);
    const back = stubbed(end, toSide);

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

/**
 * Whether a connector's points are a Bezier (start, two handles, end) rather
 * than a polyline: a curved one, unless it fell back to a straight line.
 */
export const isSwept = (item: Item, points: number[]) =>
    item.routing === 'curved' && points.length === 8;

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
