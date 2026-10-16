/**
 * SVG path data -- the `d` of a <path> -- read into the few kinds of segment
 * the board works with: moves, straight lines, cubic and quadratic curves,
 * and closes, every point absolute. Arcs become cubic curves, and the
 * shorthands (H, V, S, T) their full forms, so whatever draws, measures or
 * edits a path deals with four things only. The same reading is done on the
 * server (App\Support\Board\PathData) for what an MCP client sends.
 */

export type Segment =
    | { type: 'M'; x: number; y: number }
    | { type: 'L'; x: number; y: number }
    | {
          type: 'C';
          x1: number;
          y1: number;
          x2: number;
          y2: number;
          x: number;
          y: number;
      }
    | { type: 'Q'; x1: number; y1: number; x: number; y: number }
    | { type: 'Z' };

/** A box: where its top left is, and how big. */
export type Box = { x: number; y: number; width: number; height: number };

/** A 2D transform as SVG writes one: [a, b, c, d, e, f]. */
export type Matrix = [number, number, number, number, number, number];

const PARAMS: Record<string, number> = {
    M: 2,
    L: 2,
    H: 1,
    V: 1,
    C: 6,
    S: 4,
    Q: 4,
    T: 2,
    A: 7,
    Z: 0,
};

/** The numbers and letters of a path, in order. */
function tokens(d: string): (string | number)[] {
    const found: (string | number)[] = [];
    const pattern =
        /([MmLlHhVvCcSsQqTtAaZz])|([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/g;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(d)) !== null) {
        found.push(match[1] ?? Number(match[2]));
    }

    return found;
}

/**
 * Reads path data. Anything it cannot read stops it there: what came before
 * is kept, as a browser does.
 */
export function parsePath(d: string): Segment[] {
    const list = tokens(d);
    const segments: Segment[] = [];
    let at = 0;
    let command = '';
    let x = 0;
    let y = 0;
    let startX = 0;
    let startY = 0;
    // The last curve's second control point, for S and T to mirror
    let control = null as { x: number; y: number } | null;
    let quadratic = null as { x: number; y: number } | null;

    while (at < list.length) {
        const token = list[at];

        if (typeof token === 'string') {
            command = token;
            at++;

            if (command === 'Z' || command === 'z') {
                segments.push({ type: 'Z' });
                x = startX;
                y = startY;
                control = quadratic = null;

                continue;
            }
        } else if (!command) {
            break;
        }

        const upper = command.toUpperCase();
        const count = PARAMS[upper];
        const numbers = list.slice(at, at + count);

        if (
            count === undefined ||
            numbers.length < count ||
            numbers.some((n) => typeof n !== 'number')
        ) {
            break;
        }

        at += count;
        const n = numbers as number[];
        const relative = command !== upper;
        const ox = relative ? x : 0;
        const oy = relative ? y : 0;

        switch (upper) {
            case 'M':
                x = n[0] + ox;
                y = n[1] + oy;
                startX = x;
                startY = y;
                segments.push({ type: 'M', x, y });
                // Pairs after the first are lines
                command = relative ? 'l' : 'L';
                control = quadratic = null;
                break;
            case 'L':
            case 'H':
            case 'V':
                x = upper === 'V' ? x : n[0] + ox;
                y = upper === 'H' ? y : upper === 'V' ? n[0] + oy : n[1] + oy;
                segments.push({ type: 'L', x, y });
                control = quadratic = null;
                break;
            case 'C':
            case 'S': {
                const mirrored = control
                    ? { x: 2 * x - control.x, y: 2 * y - control.y }
                    : { x, y };
                const [x1, y1] =
                    upper === 'C'
                        ? [n[0] + ox, n[1] + oy]
                        : [mirrored.x, mirrored.y];
                const rest = upper === 'C' ? n.slice(2) : n;
                const x2 = rest[0] + ox;
                const y2 = rest[1] + oy;
                x = rest[2] + ox;
                y = rest[3] + oy;
                segments.push({ type: 'C', x1, y1, x2, y2, x, y });
                control = { x: x2, y: y2 };
                quadratic = null;
                break;
            }
            case 'Q':
            case 'T': {
                const [x1, y1]: [number, number] =
                    upper === 'Q'
                        ? [n[0] + ox, n[1] + oy]
                        : quadratic
                          ? [2 * x - quadratic.x, 2 * y - quadratic.y]
                          : [x, y];
                const rest = upper === 'Q' ? n.slice(2) : n;
                x = rest[0] + ox;
                y = rest[1] + oy;
                segments.push({ type: 'Q', x1, y1, x, y });
                quadratic = { x: x1, y: y1 };
                control = null;
                break;
            }
            case 'A': {
                const toX = n[5] + ox;
                const toY = n[6] + oy;
                segments.push(
                    ...arcToCurves(
                        x,
                        y,
                        n[0],
                        n[1],
                        n[2],
                        n[3],
                        n[4],
                        toX,
                        toY,
                    ),
                );
                x = toX;
                y = toY;
                control = quadratic = null;
                break;
            }
        }
    }

    return segments;
}

/**
 * An elliptical arc as cubic curves, a quarter turn or less each (the
 * endpoint-to-centre conversion of the SVG spec, F.6.5).
 */
function arcToCurves(
    x1: number,
    y1: number,
    rxIn: number,
    ryIn: number,
    turnDegrees: number,
    large: number,
    sweep: number,
    x2: number,
    y2: number,
): Segment[] {
    if (x1 === x2 && y1 === y2) {
        return [];
    }

    let rx = Math.abs(rxIn);
    let ry = Math.abs(ryIn);

    if (!rx || !ry) {
        return [{ type: 'L', x: x2, y: y2 }];
    }

    const phi = (turnDegrees * Math.PI) / 180;
    const cos = Math.cos(phi);
    const sin = Math.sin(phi);
    const dx = (x1 - x2) / 2;
    const dy = (y1 - y2) / 2;
    const px = cos * dx + sin * dy;
    const py = -sin * dx + cos * dy;
    // Radii too small to reach are grown until they just do
    const grow = (px * px) / (rx * rx) + (py * py) / (ry * ry);

    if (grow > 1) {
        rx *= Math.sqrt(grow);
        ry *= Math.sqrt(grow);
    }

    const sign = large === sweep ? -1 : 1;
    const top = rx * rx * ry * ry - rx * rx * py * py - ry * ry * px * px;
    const bottom = rx * rx * py * py + ry * ry * px * px;
    const scale = sign * Math.sqrt(Math.max(0, top / bottom));
    const cxp = (scale * rx * py) / ry;
    const cyp = (-scale * ry * px) / rx;
    const cx = cos * cxp - sin * cyp + (x1 + x2) / 2;
    const cy = sin * cxp + cos * cyp + (y1 + y2) / 2;
    const angle = (ux: number, uy: number, vx: number, vy: number) =>
        Math.atan2(ux * vy - uy * vx, ux * vx + uy * vy);
    const start = angle(1, 0, (px - cxp) / rx, (py - cyp) / ry);
    let delta = angle(
        (px - cxp) / rx,
        (py - cyp) / ry,
        (-px - cxp) / rx,
        (-py - cyp) / ry,
    );

    if (!sweep && delta > 0) {
        delta -= 2 * Math.PI;
    } else if (sweep && delta < 0) {
        delta += 2 * Math.PI;
    }

    const pieces = Math.ceil(Math.abs(delta) / (Math.PI / 2));
    const step = delta / pieces;
    const handle = (4 / 3) * Math.tan(step / 4);
    const point = (t: number) => ({
        x: cx + rx * Math.cos(t) * cos - ry * Math.sin(t) * sin,
        y: cy + rx * Math.cos(t) * sin + ry * Math.sin(t) * cos,
    });
    const tangent = (t: number) => ({
        x: -rx * Math.sin(t) * cos - ry * Math.cos(t) * sin,
        y: -rx * Math.sin(t) * sin + ry * Math.cos(t) * cos,
    });
    const curves: Segment[] = [];

    for (let i = 0; i < pieces; i++) {
        const a = start + i * step;
        const b = a + step;
        const from = point(a);
        const to = point(b);
        const ta = tangent(a);
        const tb = tangent(b);

        curves.push({
            type: 'C',
            x1: from.x + handle * ta.x,
            y1: from.y + handle * ta.y,
            x2: to.x - handle * tb.x,
            y2: to.y - handle * tb.y,
            x: to.x,
            y: to.y,
        });
    }

    // Ended exactly where the arc does, whatever the sums drifted by
    const last = curves.at(-1);

    if (last?.type === 'C') {
        last.x = x2;
        last.y = y2;
    }

    return curves;
}

/** Path data again, numbers kept to two places. */
export function pathString(segments: Segment[]): string {
    const n = (value: number) => String(Math.round(value * 100) / 100);

    return segments
        .map((s) => {
            switch (s.type) {
                case 'M':
                case 'L':
                    return `${s.type}${n(s.x)} ${n(s.y)}`;
                case 'C':
                    return `C${n(s.x1)} ${n(s.y1)} ${n(s.x2)} ${n(s.y2)} ${n(s.x)} ${n(s.y)}`;
                case 'Q':
                    return `Q${n(s.x1)} ${n(s.y1)} ${n(s.x)} ${n(s.y)}`;
                default:
                    return 'Z';
            }
        })
        .join(' ');
}

/** Every point of a path put through `move`. */
export function mapPoints(
    segments: Segment[],
    move: (x: number, y: number) => { x: number; y: number },
): Segment[] {
    return segments.map((s) => {
        switch (s.type) {
            case 'M':
            case 'L':
                return { ...s, ...move(s.x, s.y) };
            case 'C': {
                const a = move(s.x1, s.y1);
                const b = move(s.x2, s.y2);
                const c = move(s.x, s.y);

                return {
                    ...s,
                    x1: a.x,
                    y1: a.y,
                    x2: b.x,
                    y2: b.y,
                    x: c.x,
                    y: c.y,
                };
            }
            case 'Q': {
                const a = move(s.x1, s.y1);
                const c = move(s.x, s.y);

                return { ...s, x1: a.x, y1: a.y, x: c.x, y: c.y };
            }
            default:
                return s;
        }
    });
}

/** A path through a transform, as SVG's matrix(a b c d e f) applies one. */
export const transformPath = (
    segments: Segment[],
    [a, b, c, d, e, f]: Matrix,
) =>
    mapPoints(segments, (x, y) => ({
        x: a * x + c * y + e,
        y: b * x + d * y + f,
    }));

/**
 * The box the path's line runs through: its curves followed, not their
 * control points, so a bulge is inside and a handle pulled far out is not.
 */
export function pathBounds(segments: Segment[]): Box {
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;
    let x = 0;
    let y = 0;
    let startX = 0;
    let startY = 0;
    const take = (px: number, py: number) => {
        minX = Math.min(minX, px);
        minY = Math.min(minY, py);
        maxX = Math.max(maxX, px);
        maxY = Math.max(maxY, py);
    };

    for (const s of segments) {
        if (s.type === 'Z') {
            x = startX;
            y = startY;

            continue;
        }

        if (s.type === 'M') {
            startX = s.x;
            startY = s.y;
        }

        if (s.type === 'C' || s.type === 'Q') {
            // Followed in small steps: close enough to place and size it
            for (let i = 1; i < 24; i++) {
                const t = i / 24;
                const u = 1 - t;
                const p =
                    s.type === 'C'
                        ? {
                              x:
                                  u ** 3 * x +
                                  3 * u * u * t * s.x1 +
                                  3 * u * t * t * s.x2 +
                                  t ** 3 * s.x,
                              y:
                                  u ** 3 * y +
                                  3 * u * u * t * s.y1 +
                                  3 * u * t * t * s.y2 +
                                  t ** 3 * s.y,
                          }
                        : {
                              x: u * u * x + 2 * u * t * s.x1 + t * t * s.x,
                              y: u * u * y + 2 * u * t * s.y1 + t * t * s.y,
                          };
                take(p.x, p.y);
            }
        }

        take(s.x, s.y);
        x = s.x;
        y = s.y;
    }

    return Number.isFinite(minX)
        ? { x: minX, y: minY, width: maxX - minX, height: maxY - minY }
        : { x: 0, y: 0, width: 0, height: 0 };
}

/**
 * A path moved so its box starts at 0,0, and that box: what a path item
 * keeps, drawn scaled from its own box to the item's.
 */
export function normalisePath(segments: Segment[]): {
    d: string;
    box: Box;
} {
    const box = pathBounds(segments);

    return {
        d: pathString(
            mapPoints(segments, (x, y) => ({ x: x - box.x, y: y - box.y })),
        ),
        box,
    };
}
