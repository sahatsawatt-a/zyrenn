/**
 * Tidying an ER diagram Mermaid has laid out: lines that meet one side of a
 * box at the same spot are spread along it, so each relationship's markers
 * sit on their own line; and a label that another line runs through, or
 * that crowds another label, is slid along its own line until it is clear.
 * Pure geometry, on the points of each line, in the drawing's own units.
 */

export type Point = { x: number; y: number };
export type Box = { left: number; top: number; right: number; bottom: number };

type Side = 'top' | 'bottom' | 'left' | 'right';

/** How far apart the ends meeting one side are spread, at most. */
const GAP = 32;
/** Kept clear of a box's corners. */
const INSET = 14;
/** How far back from a line's end its marker reaches. */
const MARKER = 34;
/**
 * How far from its box a line first bends: a zero-or-more's circle sits 28
 * back with a 6 radius, and the bend after it needs its corner.
 */
export const MARKER_ROOM = 45;

/** Where a line's end meets a box: which side, judged by its last stretch. */
const sideOf = (points: Point[], at: 'start' | 'end'): Side | null => {
    const n = points.length;
    const end = at === 'end' ? points[n - 1] : points[0];
    const next = at === 'end' ? points[n - 2] : points[1];

    if (!end || !next) return null;

    if (Math.abs(end.x - next.x) < 0.5) {
        return next.y > end.y ? 'bottom' : 'top';
    }

    if (Math.abs(end.y - next.y) < 0.5) {
        return next.x > end.x ? 'right' : 'left';
    }

    return null;
};

/** The box an end touches, on the side it meets. */
const boxAt = (end: Point, side: Side, boxes: Box[]): number =>
    boxes.findIndex((box) => {
        const near = (a: number, b: number) => Math.abs(a - b) < 4;

        return side === 'top' || side === 'bottom'
            ? near(side === 'top' ? box.top : box.bottom, end.y) &&
                  end.x >= box.left - 1 &&
                  end.x <= box.right + 1
            : near(side === 'left' ? box.left : box.right, end.x) &&
                  end.y >= box.top - 1 &&
                  end.y <= box.bottom + 1;
    });

/**
 * Lines that meet the same side of a box bunched together, spread evenly
 * along it about its middle -- in the order they leave in, so they do not
 * cross. A line's last stretch moves with its end, and a line that was
 * straight from box to box gets a bend halfway to stay square. Answers with
 * whether anything moved.
 */
export function spreadEnds(lines: Point[][], boxes: Box[]): boolean {
    type End = { line: number; at: 'start' | 'end'; side: Side; box: number };
    const groups = new Map<string, End[]>();

    for (const [line, points] of lines.entries()) {
        if (points.length < 2) continue;

        for (const at of ['start', 'end'] as const) {
            const side = sideOf(points, at);
            const end = at === 'end' ? points[points.length - 1] : points[0];

            if (!side) continue;

            const box = boxAt(end, side, boxes);

            if (box < 0) continue;

            const key = `${box}:${side}`;
            groups.set(key, [
                ...(groups.get(key) ?? []),
                { line, at, side, box },
            ]);
        }
    }

    let moved = false;

    for (const ends of groups.values()) {
        if (ends.length < 2) continue;

        const { side, box: boxIndex } = ends[0];
        const box = boxes[boxIndex];
        const across = side === 'top' || side === 'bottom' ? 'x' : 'y';
        const endOf = (end: End) => {
            const points = lines[end.line];

            return end.at === 'end' ? points[points.length - 1] : points[0];
        };
        // Where the line heads once it leaves: the far end of the run
        // after its first stretch, or the line's other end
        const heading = (end: End) => {
            const points = lines[end.line];
            const n = points.length;
            const far =
                end.at === 'end'
                    ? (points[n - 3] ?? points[0])
                    : (points[2] ?? points[n - 1]);

            return far[across];
        };

        const places = ends
            .map((end) => endOf(end)[across])
            .sort((a, b) => a - b);
        const bunched = places.some(
            (place, at) => at > 0 && place - places[at - 1] < GAP * 0.75,
        );

        if (!bunched) continue;

        const low = across === 'x' ? box.left + INSET : box.top + INSET;
        const high = across === 'x' ? box.right - INSET : box.bottom - INSET;
        const gap = Math.min(GAP, (high - low) / (ends.length - 1));
        const middle = (low + high) / 2;
        const ordered = [...ends].sort(
            (a, b) =>
                heading(a) - heading(b) || endOf(a)[across] - endOf(b)[across],
        );

        for (const [index, end] of ordered.entries()) {
            const target = middle + (index - (ordered.length - 1) / 2) * gap;
            const points = lines[end.line];
            const at = end.at === 'end' ? points.length - 1 : 0;
            const next = end.at === 'end' ? at - 1 : 1;
            const shift = target - points[at][across];

            if (Math.abs(shift) < 0.5) continue;

            moved = true;

            if (points.length === 2) {
                // Straight from box to box: a bend halfway keeps it square
                const along = across === 'x' ? 'y' : 'x';
                const [first, last] = points;
                const half = (first[along] + last[along]) / 2;
                const bend = (value: number, other: number): Point =>
                    across === 'x'
                        ? { x: value, y: other }
                        : { x: other, y: value };

                if (end.at === 'end') {
                    lines[end.line] = [
                        first,
                        bend(first[across], half),
                        bend(target, half),
                        bend(target, last[along]),
                    ];
                } else {
                    lines[end.line] = [
                        bend(target, first[along]),
                        bend(target, half),
                        bend(last[across], half),
                        last,
                    ];
                }

                continue;
            }

            // Its last stretch moves with it; the run before stays square
            points[at][across] += shift;
            points[next][across] += shift;
        }

        moved = staggerRuns(ordered, lines, box) || moved;
    }

    return moved;
}

/** How far apart runs at different heights are, leaving one side. */
const STEP = 12;

/**
 * Lines leaving one side of a box turn into runs across, all at the same
 * height as Mermaid draws them -- where two of those runs overlap they read as
 * one line. Each run is given its own height: of the lines heading one way,
 * the one leaving furthest that way turns nearest the box, so none crosses
 * another. `ends` is in the order they leave the side.
 */
function staggerRuns(
    ends: { line: number; at: 'start' | 'end'; side: Side }[],
    lines: Point[][],
    box: Box,
): boolean {
    const { side } = ends[0];
    const across = side === 'top' || side === 'bottom' ? 'x' : 'y';
    const along = across === 'x' ? 'y' : 'x';
    // Away from the box: down from its bottom, right of its right side
    const away = side === 'bottom' || side === 'right' ? 1 : -1;
    const edge = box[side];
    const taken = new Set<number>();

    // Each end's run: the stretch after the one leaving the box, square to
    // it, followed by one leaving square again
    const runs = ends.flatMap((end) => {
        const points = lines[end.line];
        const n = points.length;
        const [first, second, third] =
            end.at === 'start' ? [1, 2, 3] : [n - 2, n - 3, n - 4];

        if (taken.has(end.line) || n < 4 || third < 0 || third >= n) {
            return [];
        }

        const [a, b, c] = [points[first], points[second], points[third]];

        if (
            Math.abs(a[along] - b[along]) > 0.5 ||
            Math.abs(b[across] - c[across]) > 0.5 ||
            Math.abs(a[across] - b[across]) < 1
        ) {
            return [];
        }

        taken.add(end.line);

        // Nearer than its marker needs it is pushed out to where it does
        // (makeRoomAtEnd), so it is judged from there
        const height =
            away * (a[along] - edge) < MARKER_ROOM
                ? edge + away * MARKER_ROOM
                : a[along];

        return [
            {
                a,
                b,
                height,
                low: Math.min(a[across], b[across]),
                high: Math.max(a[across], b[across]),
                heading: Math.sign(b[across] - a[across]),
            },
        ];
    });

    const overlapping = runs.some((run, at) =>
        runs.some(
            (other, before) =>
                before < at &&
                Math.abs(run.height - other.height) < 2 &&
                Math.min(run.high, other.high) - Math.max(run.low, other.low) >
                    1,
        ),
    );

    if (!overlapping) return false;

    // Heading back along the side: the first to leave turns nearest; heading
    // on, the last
    const level = runs.reduce((sum, run) => sum + run.height, 0) / runs.length;
    const depths = new Map<(typeof runs)[number], number>();

    for (const heading of [-1, 1]) {
        const way = runs.filter((run) => run.heading === heading);
        const order = heading < 0 ? way : [...way].reverse();

        for (const [index, run] of order.entries()) {
            depths.set(run, (index - (order.length - 1) / 2) * STEP);
        }
    }

    // None may turn inside the reach of its marker
    const nearest = Math.min(
        ...runs.map((run) => away * (level + away * depths.get(run)! - edge)),
    );
    const push = Math.max(0, MARKER_ROOM - nearest);

    for (const run of runs) {
        const height = level + away * (depths.get(run)! + push);

        run.a[along] = height;
        run.b[along] = height;
    }

    return true;
}

/** Whether a straight piece of line passes through a box. */
export function crosses(a: Point, b: Point, box: Box): boolean {
    // Liang-Barsky: clip the piece to the box and see what is left
    let low = 0;
    let high = 1;
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const edges: [number, number][] = [
        [-dx, a.x - box.left],
        [dx, box.right - a.x],
        [-dy, a.y - box.top],
        [dy, box.bottom - a.y],
    ];

    for (const [p, q] of edges) {
        if (p === 0) {
            if (q < 0) return false;

            continue;
        }

        const t = q / p;

        if (p < 0) {
            low = Math.max(low, t);
        } else {
            high = Math.min(high, t);
        }

        if (low > high) return false;
    }

    return true;
}

export type Label = { centre: Point; half: Point; line: number };

/**
 * Labels that another line runs through, or that lie over another label,
 * slid along their own line -- in steps, either way, along the piece of it
 * they sit on -- to the nearest place that is clear. One with nowhere clear
 * stays where it is. Answers with each label's centre.
 */
export function clearLabels(labels: Label[], lines: Point[][]): Point[] {
    const pad = 3;
    const boxOf = (centre: Point, half: Point): Box => ({
        left: centre.x - half.x - pad,
        top: centre.y - half.y - pad,
        right: centre.x + half.x + pad,
        bottom: centre.y + half.y + pad,
    });
    const overlap = (a: Box, b: Box) =>
        a.left < b.right &&
        b.left < a.right &&
        a.top < b.bottom &&
        b.top < a.bottom;
    const centres = labels.map((label) => ({ ...label.centre }));
    // Where each line meets its box, and its marker is drawn: kept clear
    const markers = lines.flatMap((points) =>
        points.length < 2
            ? []
            : (
                  [
                      [points[0], points[1]],
                      [points[points.length - 1], points[points.length - 2]],
                  ] as const
              ).map(([end, next]) => {
                  const length =
                      Math.hypot(next.x - end.x, next.y - end.y) || 1;
                  const reach = Math.min(MARKER, length);
                  const far = {
                      x: end.x + ((next.x - end.x) / length) * reach,
                      y: end.y + ((next.y - end.y) / length) * reach,
                  };

                  return {
                      left: Math.min(end.x, far.x) - 10,
                      top: Math.min(end.y, far.y) - 10,
                      right: Math.max(end.x, far.x) + 10,
                      bottom: Math.max(end.y, far.y) + 10,
                  };
              }),
    );

    const clear = (index: number, centre: Point) => {
        const box = boxOf(centre, labels[index].half);

        if (markers.some((marker) => overlap(box, marker))) return false;

        for (const [line, points] of lines.entries()) {
            if (line === labels[index].line) continue;

            for (let at = 1; at < points.length; at++) {
                if (crosses(points[at - 1], points[at], box)) return false;
            }
        }

        return labels.every(
            (other, at) =>
                at === index || !overlap(box, boxOf(centres[at], other.half)),
        );
    };

    for (const [index, label] of labels.entries()) {
        if (clear(index, centres[index])) continue;

        // The piece of its own line it sits on
        const points = lines[label.line] ?? [];
        let piece: [Point, Point] | null = null;
        let nearest = Infinity;

        for (let at = 1; at < points.length; at++) {
            const [a, b] = [points[at - 1], points[at]];
            const length = Math.hypot(b.x - a.x, b.y - a.y) || 1;
            const t = Math.max(
                0,
                Math.min(
                    1,
                    ((centres[index].x - a.x) * (b.x - a.x) +
                        (centres[index].y - a.y) * (b.y - a.y)) /
                        (length * length),
                ),
            );
            const distance = Math.hypot(
                a.x + (b.x - a.x) * t - centres[index].x,
                a.y + (b.y - a.y) * t - centres[index].y,
            );

            if (distance < nearest) {
                nearest = distance;
                piece = [a, b];
            }
        }

        if (!piece) continue;

        const [a, b] = piece;
        const length = Math.hypot(b.x - a.x, b.y - a.y) || 1;
        const step = {
            x: ((b.x - a.x) / length) * 8,
            y: ((b.y - a.y) / length) * 8,
        };

        let found: Point | undefined;

        for (let k = 1; k <= 20 && !found; k++) {
            found = [k, -k]
                .map((s) => ({
                    x: centres[index].x + step.x * s,
                    y: centres[index].y + step.y * s,
                }))
                .find((centre) => {
                    // Still on its own piece of line
                    const along =
                        ((centre.x - a.x) * (b.x - a.x) +
                            (centre.y - a.y) * (b.y - a.y)) /
                        (length * length);

                    return along >= 0 && along <= 1 && clear(index, centre);
                });
        }

        // Nowhere along it: just beside it, as near as is clear
        const side = { x: -step.y, y: step.x };

        for (let k = 1; k <= 16 && !found; k++) {
            found = [k, -k]
                .map((s) => ({
                    x: centres[index].x + side.x * s,
                    y: centres[index].y + side.y * s,
                }))
                .find((centre) => clear(index, centre));
        }

        if (found) {
            centres[index] = found;
        }
    }

    return centres;
}

/**
 * Where rows of boxes are closer than `room`, what is in and below the lower
 * row is moved down to open the gap -- so the lines into it, their markers
 * and their labels have room. Answers with each move, top first: from what
 * height down, and by how much.
 */
export function openRows(
    boxes: Box[],
    room = 150,
): { from: number; by: number }[] {
    // Rows by the middle of their boxes: a row's boxes are centred on it
    const rows: Box[][] = [];

    for (const box of [...boxes].sort(
        (a, b) => a.top + a.bottom - b.top - b.bottom,
    )) {
        const row = rows.at(-1);
        const middle = (box.top + box.bottom) / 2;

        if (row && Math.abs((row[0].top + row[0].bottom) / 2 - middle) < 4) {
            row.push(box);
        } else {
            rows.push([box]);
        }
    }

    const moves: { from: number; by: number }[] = [];
    let pushed = 0;

    for (let at = 1; at < rows.length; at++) {
        const above = Math.max(...rows[at - 1].map((box) => box.bottom));
        const below = Math.min(...rows[at].map((box) => box.top));
        const gap = below - above;

        if (gap > 0 && gap < room) {
            moves.push({ from: below + pushed - 0.5, by: room - gap });
            pushed += room - gap;
        }
    }

    return moves;
}
