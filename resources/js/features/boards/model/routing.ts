// Every connector on a board routed together: an elbow goes round the items in
// its way rather than through them, lines that would lie on top of each other
// are moved apart into lanes of their own, and a label is put where it covers
// no item. Nothing is stored: a board drawn before any of this gets it too,
// and an end somebody put on a face keeps it.
import type { Ends } from './connectors';
import {
    endsOf,
    isSwept,
    labelWidth,
    LABEL_HEIGHT,
    midpointOf,
    pathBetween,
    sidesById,
    simplified,
    STUB,
} from './connectors';
import { boundsOf } from './geometry';
import type { Item, Side } from './items';
import { isConnector } from './items';
import { shapeOf } from './shapes';

type Point = { x: number; y: number };
type Box = { left: number; top: number; right: number; bottom: number };

/** A connector's path, and where its label goes. */
export type Route = { points: number[]; label: Point | null };

/** How far a route keeps from an item it goes round. */
export const MARGIN = 14;

/** How close a plain elbow may pass an item before it is taken round it. */
const NEAR = 6;

/** How far apart lines running side by side are put. */
export const LANE = 12;

/** What a turn costs a route, in board units of length. */
const BEND = 40;

/** How far round the ends a search for a way looks, at the least. */
const REACH = 80;

/** How many routes are remembered between one drawing and the next. */
const REMEMBERED = 4000;

const boxOf = (item: Item): Box => {
    const { x, y, width, height } = boundsOf(item);

    return { left: x, top: y, right: x + width, bottom: y + height };
};

const grown = (box: Box, by: number): Box => ({
    left: box.left - by,
    top: box.top - by,
    right: box.right + by,
    bottom: box.bottom + by,
});

const meet = (a: Box, b: Box) =>
    a.left < b.right &&
    a.right > b.left &&
    a.top < b.bottom &&
    a.bottom > b.top;

const holds = (box: Box, point: Point) =>
    point.x > box.left &&
    point.x < box.right &&
    point.y > box.top &&
    point.y < box.bottom;

/** Whether a straight run from one point to another passes inside a box. */
const runThrough = (from: Point, to: Point, box: Box) =>
    meet(
        {
            left: Math.min(from.x, to.x),
            right: Math.max(from.x, to.x),
            top: Math.min(from.y, to.y),
            bottom: Math.max(from.y, to.y),
        },
        box,
    ) &&
    // A run that only grazes an edge is not inside
    (from.x === to.x
        ? from.x > box.left && from.x < box.right
        : from.y === to.y
          ? from.y > box.top && from.y < box.bottom
          : true);

/**
 * What a line goes round: anything with a body -- shapes, notes, pictures,
 * words -- but not frames (a line runs inside them), other lines, ink, or
 * the lifelines a sequence's messages cross by design.
 */
const isObstacle = (item: Item) =>
    !item.hidden &&
    !isConnector(item) &&
    item.kind !== 'frame' &&
    item.kind !== 'draw' &&
    item.kind !== 'path' &&
    !shapeOf(item.kind)?.slide &&
    item.width > 0 &&
    item.height > 0;

/**
 * The items a board's lines go round, filed by the cells of a coarse grid so
 * a route only looks at those near it -- a big board has hundreds.
 */
const CELL = 256;

type Obstacle = { id: string; box: Box };

const obstaclesOf = (items: Item[]) => {
    const cells = new Map<string, Obstacle[]>();
    const all: Obstacle[] = [];

    for (const item of items) {
        if (!isObstacle(item)) {
            continue;
        }

        const obstacle = { id: item.id, box: boxOf(item) };

        all.push(obstacle);

        for (
            let cx = Math.floor(obstacle.box.left / CELL);
            cx <= Math.floor(obstacle.box.right / CELL);
            cx++
        ) {
            for (
                let cy = Math.floor(obstacle.box.top / CELL);
                cy <= Math.floor(obstacle.box.bottom / CELL);
                cy++
            ) {
                const key = `${cx},${cy}`;

                cells.set(key, [...(cells.get(key) ?? []), obstacle]);
            }
        }
    }

    /** The obstacles meeting a box, each once. */
    const near = (box: Box): Obstacle[] => {
        // A box wider than the board is all of it
        if (
            (box.right - box.left) * (box.bottom - box.top) >
            CELL * CELL * 400
        ) {
            return all.filter((obstacle) => meet(obstacle.box, box));
        }

        const found = new Set<Obstacle>();

        for (
            let cx = Math.floor(box.left / CELL);
            cx <= Math.floor(box.right / CELL);
            cx++
        ) {
            for (
                let cy = Math.floor(box.top / CELL);
                cy <= Math.floor(box.bottom / CELL);
                cy++
            ) {
                for (const obstacle of cells.get(`${cx},${cy}`) ?? []) {
                    if (meet(obstacle.box, box)) {
                        found.add(obstacle);
                    }
                }
            }
        }

        return [...found];
    };

    return near;
};

const pointsOf = (flat: number[]): Point[] => {
    const points: Point[] = [];

    for (let index = 0; index + 1 < flat.length; index += 2) {
        points.push({ x: flat[index], y: flat[index + 1] });
    }

    return points;
};

const flatOf = (points: Point[]) =>
    points.flatMap((point) => [point.x, point.y]);

const spanOf = (points: Point[]): Box => ({
    left: Math.min(...points.map((point) => point.x)),
    top: Math.min(...points.map((point) => point.y)),
    right: Math.max(...points.map((point) => point.x)),
    bottom: Math.max(...points.map((point) => point.y)),
});

// Directions a route travels in: right, down, left, up
const STEPS = [
    { x: 1, y: 0 },
    { x: 0, y: 1 },
    { x: -1, y: 0 },
    { x: 0, y: -1 },
];

const OUTWARDS: Record<Side, number> = { right: 0, bottom: 1, left: 2, top: 3 };

/** A small binary heap of numbers, cheapest first, for the search. */
class Queue {
    private keys: number[] = [];
    private costs: number[] = [];

    get size() {
        return this.keys.length;
    }

    push(key: number, cost: number) {
        const { keys, costs } = this;
        let at = keys.length;

        keys.push(key);
        costs.push(cost);

        while (at > 0) {
            const parent = (at - 1) >> 1;

            if (costs[parent] <= cost) {
                break;
            }

            keys[at] = keys[parent];
            costs[at] = costs[parent];
            at = parent;
        }

        keys[at] = key;
        costs[at] = cost;
    }

    pop(): number {
        const { keys, costs } = this;
        const top = keys[0];
        const lastKey = keys.pop()!;
        const lastCost = costs.pop()!;

        if (keys.length) {
            let at = 0;

            for (;;) {
                const left = at * 2 + 1;
                const right = left + 1;
                let least = at;
                let leastCost = lastCost;

                if (left < keys.length && costs[left] < leastCost) {
                    least = left;
                    leastCost = costs[left];
                }

                if (right < keys.length && costs[right] < leastCost) {
                    least = right;
                }

                if (least === at) {
                    break;
                }

                keys[at] = keys[least];
                costs[at] = costs[least];
                at = least;
            }

            keys[at] = lastKey;
            costs[at] = lastCost;
        }

        return top;
    }
}

const sorted = (values: number[]) =>
    [...new Set(values.map((value) => Math.round(value * 100) / 100))].sort(
        (a, b) => a - b,
    );

/** Each coordinate, and halfway between each and the next: the middle of a gap. */
const withMiddles = (values: number[]) =>
    sorted([
        ...values,
        ...values.slice(1).map((value, index) => (value + values[index]) / 2),
    ]);

/**
 * The cheapest way from `out` to `back` that keeps out of every box, on a
 * grid made of the boxes' edges, the gaps between them and the two points:
 * the lines along which a tidy route would run. A turn costs BEND, so of two
 * ways about as long the one with fewer corners wins. It leaves heading the
 * way `out` points and arrives heading into `back`; nothing when there is no
 * way at all.
 */
const search = (
    out: Point,
    leaving: number,
    back: Point,
    arriving: number,
    boxes: Box[],
    bounds: Box,
): Point[] | null => {
    const xs = withMiddles(
        sorted([
            out.x,
            back.x,
            bounds.left,
            bounds.right,
            ...boxes.flatMap((box) => [box.left, box.right]),
        ]),
    );
    const ys = withMiddles(
        sorted([
            out.y,
            back.y,
            bounds.top,
            bounds.bottom,
            ...boxes.flatMap((box) => [box.top, box.bottom]),
        ]),
    );
    const across = xs.length;
    const down = ys.length;
    const nodes = across * down;

    // Which grid points lie inside a box, and which runs to the next point
    // along (right, or down) pass through one
    const inside = new Uint8Array(nodes);
    const blockedRight = new Uint8Array(nodes);
    const blockedDown = new Uint8Array(nodes);

    for (const box of boxes) {
        const x0 = xs.findIndex((x) => x >= box.left);
        const y0 = ys.findIndex((y) => y >= box.top);

        for (
            let ix = Math.max(0, x0);
            ix < across && xs[ix] <= box.right;
            ix++
        ) {
            for (
                let iy = Math.max(0, y0);
                iy < down && ys[iy] <= box.bottom;
                iy++
            ) {
                const node = iy * across + ix;
                const inX = xs[ix] > box.left && xs[ix] < box.right;
                const inY = ys[iy] > box.top && ys[iy] < box.bottom;

                if (inX && inY) {
                    inside[node] = 1;
                }

                if (inY && ix + 1 < across && xs[ix + 1] <= box.right) {
                    blockedRight[node] = 1;
                }

                if (inX && iy + 1 < down && ys[iy + 1] <= box.bottom) {
                    blockedDown[node] = 1;
                }
            }
        }
    }

    const start =
        ys.indexOf(Math.round(out.y * 100) / 100) * across +
        xs.indexOf(Math.round(out.x * 100) / 100);
    const goal =
        ys.indexOf(Math.round(back.y * 100) / 100) * across +
        xs.indexOf(Math.round(back.x * 100) / 100);

    if (start < 0 || goal < 0) {
        return null;
    }

    // A state is a grid point and the way the route is heading there
    const best = new Float64Array(nodes * 4).fill(Infinity);
    const came = new Int32Array(nodes * 4).fill(-1);
    const queue = new Queue();
    const first = start * 4 + leaving;

    best[first] = 0;
    queue.push(first, 0);

    let reached = -1;
    let reachedCost = Infinity;

    while (queue.size) {
        const state = queue.pop();
        const node = state >> 2;
        const heading = state & 3;
        const cost = best[state];

        if (cost >= reachedCost) {
            break;
        }

        if (node === goal) {
            // Arriving any other way than into the end turns once more;
            // arriving backwards would fold the line onto its own stub
            if (heading !== (arriving + 2) % 4) {
                const total = cost + (heading === arriving ? 0 : BEND);

                if (total < reachedCost) {
                    reachedCost = total;
                    reached = state;
                }
            }

            continue;
        }

        const ix = node % across;
        const iy = (node - ix) / across;

        for (let turn = 0; turn < 4; turn++) {
            // Never straight back the way it came
            if (turn === (heading + 2) % 4) {
                continue;
            }

            const step = STEPS[turn];
            const nx = ix + step.x;
            const ny = iy + step.y;

            if (nx < 0 || ny < 0 || nx >= across || ny >= down) {
                continue;
            }

            const next = ny * across + nx;

            if (
                inside[next] ||
                (step.x === 1 && blockedRight[node]) ||
                (step.x === -1 && blockedRight[next]) ||
                (step.y === 1 && blockedDown[node]) ||
                (step.y === -1 && blockedDown[next])
            ) {
                continue;
            }

            const length =
                Math.abs(xs[nx] - xs[ix]) + Math.abs(ys[ny] - ys[iy]);
            const total = cost + length + (turn === heading ? 0 : BEND);
            const into = next * 4 + turn;

            if (total < best[into]) {
                best[into] = total;
                came[into] = state;
                queue.push(into, total);
            }
        }
    }

    if (reached < 0) {
        return null;
    }

    const path: Point[] = [];

    for (let state = reached; state >= 0; state = came[state]) {
        const node = state >> 2;
        const ix = node % across;

        path.unshift({ x: xs[ix], y: ys[(node - ix) / across] });
    }

    return path;
};

/** Routes worked out before, by everything they depended on. */
const remembered = new Map<string, Point[] | null>();

/**
 * An elbow's way from one end to the other round what is in it. The plain
 * elbow when it passes clear of everything -- most lines do, and keep the
 * look they always had -- and otherwise the cheapest way round, keeping
 * MARGIN from each item. An item lying over either end (a box drawn round a
 * group, words on a card) is not in the way: the line starts inside it.
 */
const routeRound = (
    ends: Ends,
    plain: Point[],
    near: (box: Box) => Obstacle[],
): Point[] => {
    const { start, end, fromSide, toSide, fromHost, toHost } = ends;

    if (!fromSide || !toSide) {
        return plain;
    }

    const out = {
        x: start.x + STEPS[OUTWARDS[fromSide]].x * STUB,
        y: start.y + STEPS[OUTWARDS[fromSide]].y * STUB,
    };
    const back = {
        x: end.x + STEPS[OUTWARDS[toSide]].x * STUB,
        y: end.y + STEPS[OUTWARDS[toSide]].y * STUB,
    };
    const hosts = [fromHost, toHost].flatMap((host) =>
        host ? [boxOf(host)] : [],
    );
    const inTheWay = (obstacle: Obstacle) =>
        obstacle.id !== fromHost?.id &&
        obstacle.id !== toHost?.id &&
        !hosts.some((host) => meet(host, obstacle.box)) &&
        !holds(grown(obstacle.box, MARGIN), out) &&
        !holds(grown(obstacle.box, MARGIN), back);

    // The plain elbow, checked against everything near it -- and against its
    // own shapes, which it may not double back through
    const plainBlocked = () => {
        const checked = [
            ...near(grown(spanOf(plain), NEAR))
                .filter(inTheWay)
                .map((obstacle) => grown(obstacle.box, NEAR)),
            ...hosts,
        ];

        return plain.slice(1).some((point, index) =>
            checked.some((box) =>
                // The first and last runs leave and reach their shapes
                index === 0 || index === plain.length - 2
                    ? !hosts.includes(box) &&
                      runThrough(plain[index], point, box)
                    : runThrough(plain[index], point, box),
            ),
        );
    };

    if (!plainBlocked()) {
        return plain;
    }

    // What lies around both ends, and around whatever of that is in the way
    let bounds = grown(spanOf([out, back]), REACH);
    let found: Obstacle[] = [];

    for (let round = 0; round < 3; round++) {
        found = near(bounds).filter(inTheWay);

        const wider = found.reduce(
            (box, obstacle) => ({
                left: Math.min(box.left, obstacle.box.left - REACH),
                top: Math.min(box.top, obstacle.box.top - REACH),
                right: Math.max(box.right, obstacle.box.right + REACH),
                bottom: Math.max(box.bottom, obstacle.box.bottom + REACH),
            }),
            bounds,
        );

        if (
            wider.left === bounds.left &&
            wider.top === bounds.top &&
            wider.right === bounds.right &&
            wider.bottom === bounds.bottom
        ) {
            break;
        }

        bounds = wider;
    }

    const boxes = [
        ...found.map((obstacle) => grown(obstacle.box, MARGIN)),
        // Its own shapes too, so it goes round them rather than back through
        ...hosts
            .map((host) => grown(host, MARGIN))
            .filter((box) => !holds(box, out) && !holds(box, back)),
    ];
    const key = JSON.stringify([out, back, fromSide, toSide, boxes, bounds]);

    if (!remembered.has(key)) {
        if (remembered.size >= REMEMBERED) {
            remembered.clear();
        }

        remembered.set(
            key,
            search(
                out,
                OUTWARDS[fromSide],
                back,
                (OUTWARDS[toSide] + 2) % 4,
                boxes,
                bounds,
            ),
        );
    }

    const way = remembered.get(key);

    return way ? simplified([start, ...way, end]) : plain;
};

/**
 * One straight run of a routed line that may be moved sideways: not the
 * first or the last, which are fixed to the shapes at their ends.
 */
type Run = {
    line: number;
    // Its points are path[at] and path[at + 1]
    at: number;
    // Upright (moves in x) or lying (moves in y)
    upright: boolean;
    // Where it lies across, and the stretch it covers along
    across: number;
    low: number;
    high: number;
    // Which way the runs before and after it go: -1 towards less, 1 more
    before: number;
    after: number;
    // Where along it the runs before and after join it
    starts: number;
    ends: number;
    // How far it may move without its neighbours folding back
    least: number;
    most: number;
};

const runsOf = (paths: Point[][], heads: number[][]): Run[] =>
    paths.flatMap((path, line) =>
        path.slice(1, -2).flatMap((_, offset) => {
            const at = offset + 1;
            const a = path[at];
            const b = path[at + 1];
            const upright = Math.abs(a.x - b.x) < 0.01;

            if (!upright && Math.abs(a.y - b.y) > 0.01) {
                return [];
            }

            const across = upright ? a.x : a.y;
            const prior = path[at - 1];
            const next = path[at + 2];
            const sideOfRun = (point: Point) =>
                Math.sign((upright ? point.x : point.y) - across);

            // A neighbour that is the first or last run keeps its stub, and
            // room for the head at that end; any other keeps a sliver
            const room = (neighbour: Point, end: number) =>
                end >= 0 ? heads[line][end] : 2;
            const limits = [
                {
                    point: prior,
                    keep: room(prior, at - 1 === 0 ? 0 : -1),
                },
                {
                    point: next,
                    keep: room(next, at + 2 === path.length - 1 ? 1 : -1),
                },
            ];
            let least = -Infinity;
            let most = Infinity;

            for (const { point, keep } of limits) {
                const where = upright ? point.x : point.y;

                if (where < across) {
                    least = Math.max(least, where + keep);
                } else {
                    most = Math.min(most, where - keep);
                }
            }

            return [
                {
                    line,
                    at,
                    upright,
                    across,
                    low: Math.min(upright ? a.y : a.x, upright ? b.y : b.x),
                    high: Math.max(upright ? a.y : a.x, upright ? b.y : b.x),
                    before: sideOfRun(prior),
                    after: sideOfRun(next),
                    starts: upright ? a.y : a.x,
                    ends: upright ? b.y : b.x,
                    least,
                    most,
                },
            ];
        }),
    );

const within = (value: number, run: Run) =>
    value > run.low + 0.5 && value < run.high - 0.5;

/**
 * How many times two runs side by side cross the runs joining them when the
 * first is put on the lower side: a neighbour of the upper one reaching back
 * across the lower, or of the lower reaching across the upper.
 */
const crossings = (lower: Run, upper: Run) =>
    (upper.before < 0 && within(upper.starts, lower) ? 1 : 0) +
    (upper.after < 0 && within(upper.ends, lower) ? 1 : 0) +
    (lower.before > 0 && within(lower.starts, upper) ? 1 : 0) +
    (lower.after > 0 && within(lower.ends, upper) ? 1 : 0);

/**
 * Lines that would lie on top of each other, moved apart: runs of different
 * lines in the same direction, closer than a LANE and sharing a stretch, are
 * set a LANE apart about where they were, in the order that crosses least.
 * Upright runs first, then lying ones, each moving the corners it ends at.
 */
const intoLanes = (paths: Point[][], heads: number[][]) => {
    for (const upright of [true, false]) {
        const runs = runsOf(paths, heads)
            .filter((run) => run.upright === upright)
            .sort((a, b) => a.across - b.across);

        // Runs in the way of each other, gathered into groups
        const group = runs.map((_, index) => index);
        const root = (index: number): number =>
            group[index] === index
                ? index
                : (group[index] = root(group[index]));

        for (let i = 0; i < runs.length; i++) {
            for (
                let j = i + 1;
                j < runs.length && runs[j].across - runs[i].across < LANE - 0.5;
                j++
            ) {
                if (
                    runs[i].line !== runs[j].line &&
                    runs[i].low < runs[j].high - 0.5 &&
                    runs[j].low < runs[i].high - 0.5
                ) {
                    group[root(j)] = root(i);
                }
            }
        }

        const groups = new Map<number, Run[]>();

        runs.forEach((run, index) =>
            groups.set(root(index), [...(groups.get(root(index)) ?? []), run]),
        );

        for (const members of groups.values()) {
            if (members.length < 2) {
                continue;
            }

            members.sort(
                (a, b) =>
                    crossings(a, b) - crossings(b, a) ||
                    a.across - b.across ||
                    a.line - b.line,
            );

            // As far apart as a LANE, or as the room they share allows
            const least = Math.max(...members.map((run) => run.least));
            const most = Math.min(...members.map((run) => run.most));
            const middle =
                members.reduce((sum, run) => sum + run.across, 0) /
                members.length;
            const count = members.length - 1;
            const gap =
                most > least ? Math.min(LANE, (most - least) / count) : LANE;
            const first = Math.min(
                Math.max(middle - (gap * count) / 2, least),
                most - gap * count,
            );

            members.forEach((run, index) => {
                const to = Math.min(
                    Math.max(
                        Number.isFinite(first)
                            ? first + gap * index
                            : run.across,
                        run.least,
                    ),
                    run.most,
                );
                const path = paths[run.line];

                for (const point of [path[run.at], path[run.at + 1]]) {
                    if (upright) {
                        point.x = to;
                    } else {
                        point.y = to;
                    }
                }
            });
        }
    }
};

/**
 * Where a label goes: halfway along its line, unless an item is there, then
 * the nearest place along the line -- or beside it -- that is clear. Failing
 * that, wherever it covers least, beside the line rather than over it.
 */
const labelPlace = (
    item: Item,
    points: number[],
    near: (box: Box) => Obstacle[],
): Point => {
    const middle = midpointOf(points, isSwept(item, points));

    if (isSwept(item, points)) {
        return middle;
    }

    const width = labelWidth(item);
    const chipAt = (at: Point): Box => ({
        left: at.x - width / 2,
        top: at.y - LABEL_HEIGHT / 2,
        right: at.x + width / 2,
        bottom: at.y + LABEL_HEIGHT / 2,
    });
    const items = near(
        grown(spanOf(pointsOf(points)), Math.max(width, LABEL_HEIGHT)),
    );
    const covered = (at: Point) =>
        items.reduce((sum, obstacle) => {
            const chip = chipAt(at);
            const across =
                Math.min(chip.right, obstacle.box.right) -
                Math.max(chip.left, obstacle.box.left);
            const down =
                Math.min(chip.bottom, obstacle.box.bottom) -
                Math.max(chip.top, obstacle.box.top);

            return sum + (across > 0 && down > 0 ? across * down : 0);
        }, 0);

    if (!covered(middle)) {
        return middle;
    }

    // Along the line, out from the middle
    const path = pointsOf(points);
    const lengths = path
        .slice(1)
        .map((point, index) =>
            Math.hypot(point.x - path[index].x, point.y - path[index].y),
        );
    const total = lengths.reduce((sum, length) => sum + length, 0);
    const along = (distance: number) => {
        let walked = 0;

        for (let index = 0; index < lengths.length; index++) {
            if (walked + lengths[index] >= distance) {
                const into = (distance - walked) / (lengths[index] || 1);
                const a = path[index];
                const b = path[index + 1];

                return {
                    point: {
                        x: a.x + (b.x - a.x) * into,
                        y: a.y + (b.y - a.y) * into,
                    },
                    lying: Math.abs(b.y - a.y) < Math.abs(b.x - a.x),
                };
            }

            walked += lengths[index];
        }

        return { point: path[path.length - 1], lying: true };
    };

    // Covering something either way, it had better not cover its own line
    // too: of places that cover as much, one beside the line wins
    let fallback = { at: middle, covers: covered(middle), onLine: true };

    for (const share of [0.5, 0.4, 0.6, 0.3, 0.7, 0.2, 0.8]) {
        const { point, lying } = along(total * share);
        // On the line, then beside it on either hand
        const beside = lying ? LABEL_HEIGHT / 2 + 4 : width / 2 + 4;

        for (const shift of [0, -beside, beside]) {
            const at = lying
                ? { x: point.x, y: point.y + shift }
                : { x: point.x + shift, y: point.y };
            const covers = covered(at);

            if (!covers) {
                return at;
            }

            if (
                covers < fallback.covers ||
                (covers === fallback.covers && fallback.onLine && shift)
            ) {
                fallback = { at, covers, onLine: !shift };
            }
        }
    }

    return fallback.at;
};

/**
 * Every connector's route on a board, by its id. Elbows go round the items
 * in their way and keep out of each other's lanes; straight lines, curves,
 * wires (on their pins' grid) and a sequence's messages keep the path they
 * take on their own. A line with a label gets a place for it clear of items.
 */
export const connectorRoutes = (
    items: Item[],
    byId: Map<string, Item>,
    attached: Map<string, Item[]>,
): Map<string, Route> => {
    const near = obstaclesOf(items);
    const sides = sidesById(items, byId);
    const routes = new Map<string, Route>();
    const elbows: { item: Item; path: Point[] }[] = [];

    for (const item of items) {
        if (!isConnector(item)) {
            continue;
        }

        const ends = endsOf(item, byId, attached, sides);

        if (!ends) {
            routes.set(item.id, { points: [], label: null });
            continue;
        }

        const plain = pathBetween(item, ends);

        if (
            item.routing !== 'elbow' ||
            ends.wire ||
            ends.along ||
            plain.length < 4
        ) {
            routes.set(item.id, { points: plain, label: null });
            continue;
        }

        const path = routeRound(ends, pointsOf(plain), near).map((point) => ({
            ...point,
        }));

        elbows.push({ item, path });
    }

    // Room each end of a line keeps for its stub and its head
    const heads = elbows.map(({ item }) => [
        Math.max(8, item.headSize + 4),
        Math.max(8, item.headSize + 4),
    ]);

    intoLanes(
        elbows.map(({ path }) => path),
        heads,
    );

    for (const { item, path } of elbows) {
        routes.set(item.id, {
            points: flatOf(simplified(path)),
            label: null,
        });
    }

    for (const item of items) {
        const route = routes.get(item.id);

        if (route && item.text && route.points.length >= 4) {
            route.label = labelPlace(item, route.points, near);
        }
    }

    return routes;
};
