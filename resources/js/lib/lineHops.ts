// Where lines cross, one of them hops over the other with a small bump, so
// two lines that cross read as two lines and not as a junction. Worked out
// here once, from straight runs, for the board's connectors and the
// workflow editor's edges alike; each draws the bump its own way.

export type Point = { x: number; y: number };

/** A straight run of a line, from one point to the next. */
export type Run = { from: Point; to: Point };

/** One line: its straight runs, in order, and how big its bumps are. */
export type HopLine = { id: string; runs: Run[]; radius: number };

/** Where on a line's runs it hops: points, by the index of the run they are on. */
export type Hops = Map<number, Point[]>;

/** Lying more across than up and down. */
const flat = (run: Run) =>
    Math.abs(run.to.x - run.from.x) >= Math.abs(run.to.y - run.from.y);

const length = (run: Run) =>
    Math.hypot(run.to.x - run.from.x, run.to.y - run.from.y);

/**
 * Where two runs cross, if they do somewhere inside both -- not at either
 * end, nor so near one that a bump would not fit before it: lines that meet
 * at an end are joined there, not crossing.
 */
const crossing = (a: Run, b: Run, room: number): Point | null => {
    const ax = a.to.x - a.from.x;
    const ay = a.to.y - a.from.y;
    const bx = b.to.x - b.from.x;
    const by = b.to.y - b.from.y;
    const across = ax * by - ay * bx;

    // Side by side: they never cross, however close
    if (Math.abs(across) < 1e-9) {
        return null;
    }

    const dx = b.from.x - a.from.x;
    const dy = b.from.y - a.from.y;
    const onA = (dx * by - dy * bx) / across;
    const onB = (dx * ay - dy * ax) / across;
    const lengthA = length(a);
    const lengthB = length(b);

    if (
        onA * lengthA <= room ||
        (1 - onA) * lengthA <= room ||
        onB * lengthB <= room ||
        (1 - onB) * lengthB <= room
    ) {
        return null;
    }

    return { x: a.from.x + ax * onA, y: a.from.y + ay * onA };
};

const boxOf = (runs: Run[]) => {
    const xs = runs.flatMap((run) => [run.from.x, run.to.x]);
    const ys = runs.flatMap((run) => [run.from.y, run.to.y]);

    return {
        left: Math.min(...xs),
        right: Math.max(...xs),
        top: Math.min(...ys),
        bottom: Math.max(...ys),
    };
};

/**
 * Where each line hops, by its id. At a crossing the line lying across hops
 * over the one standing up -- the way a drawing office does it, so every
 * bump on a page faces the same way; where both lie the same way, the line
 * listed later hops over the one before it. Two bumps closer than a bump's
 * width are one.
 */
export const lineHops = (lines: HopLine[]): Map<string, Hops> => {
    const found = new Map<string, Hops>();
    const boxes = lines.map((line) => boxOf(line.runs));

    const add = (line: HopLine, run: number, at: Point) => {
        const hops = found.get(line.id) ?? new Map<number, Point[]>();

        hops.set(run, [...(hops.get(run) ?? []), at]);
        found.set(line.id, hops);
    };

    for (let i = 0; i < lines.length; i++) {
        for (let j = i + 1; j < lines.length; j++) {
            const [a, b] = [boxes[i], boxes[j]];

            // Nowhere near one another: nothing to look at
            if (
                !a ||
                !b ||
                a.right < b.left ||
                b.right < a.left ||
                a.bottom < b.top ||
                b.bottom < a.top
            ) {
                continue;
            }

            for (const [ri, first] of lines[i].runs.entries()) {
                for (const [rj, second] of lines[j].runs.entries()) {
                    const room =
                        Math.max(lines[i].radius, lines[j].radius) * 1.5;
                    const at = crossing(first, second, room);

                    if (!at) {
                        continue;
                    }

                    if (flat(first) && !flat(second)) {
                        add(lines[i], ri, at);
                    } else {
                        add(lines[j], rj, at);
                    }
                }
            }
        }
    }

    // Along each run in the order they come, and never two in one place
    for (const [id, hops] of found) {
        const line = lines.find((each) => each.id === id)!;

        for (const [index, points] of hops) {
            const run = line.runs[index];
            const along = (point: Point) =>
                Math.hypot(point.x - run.from.x, point.y - run.from.y);
            const sorted = [...points].sort((p, q) => along(p) - along(q));

            hops.set(
                index,
                sorted.filter(
                    (point, at) =>
                        at === 0 ||
                        along(point) - along(sorted[at - 1]) >
                            line.radius * 2.2,
                ),
            );
        }
    }

    return found;
};

/** Whether b lies on the straight way from a to c, going on the same way. */
export const straightOn = (a: Point, b: Point, c: Point): boolean => {
    const across = (b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x);
    const onwards = (b.x - a.x) * (c.x - b.x) + (b.y - a.y) * (c.y - b.y);

    return Math.abs(across) < 1e-6 && onwards >= 0;
};

/**
 * A polyline's runs -- [x1, y1, x2, y2, ...] a pair of points at a time --
 * with a point the line only passes straight through left out, so a line
 * crossed near the end of a short stub, where it carries straight on, is
 * still crossed in the middle of a run.
 */
export const runsOf = (points: number[]): Run[] => {
    const corners: Point[] = [];

    for (let at = 0; at + 1 < points.length; at += 2) {
        const point = { x: points[at], y: points[at + 1] };
        const last = corners[corners.length - 1];

        // Nowhere new: no run of no length
        if (last && last.x === point.x && last.y === point.y) {
            continue;
        }

        if (
            corners.length >= 2 &&
            straightOn(corners[corners.length - 2], last, point)
        ) {
            corners[corners.length - 1] = point;
        } else {
            corners.push(point);
        }
    }

    return corners.slice(1).map((to, index) => ({ from: corners[index], to }));
};

/**
 * Which way round a bump over this run goes: true for clockwise on the
 * screen. A run lying across bumps up, one standing up bumps to the left,
 * whichever way along it the line is going.
 */
export const bumpsClockwise = (run: Run): boolean => {
    const dx = run.to.x - run.from.x;
    const dy = run.to.y - run.from.y;
    // The bump starts behind the crossing, at the angle pointing back along
    // the run; a quarter turn clockwise from there is the top of its arc
    const start = Math.atan2(-dy, -dx);
    const top = {
        x: Math.cos(start + Math.PI / 2),
        y: Math.sin(start + Math.PI / 2),
    };
    const wanted = flat(run) ? { x: 0, y: -1 } : { x: -1, y: 0 };

    return top.x * wanted.x + top.y * wanted.y > 0;
};

/**
 * The points a bump starts and ends at, either side of where the run is
 * crossed, and the angle the arc starts from, for drawing it.
 */
export const bumpAt = (run: Run, at: Point, radius: number) => {
    const span = length(run) || 1;
    const ux = (run.to.x - run.from.x) / span;
    const uy = (run.to.y - run.from.y) / span;

    return {
        before: { x: at.x - ux * radius, y: at.y - uy * radius },
        after: { x: at.x + ux * radius, y: at.y + uy * radius },
        start: Math.atan2(-uy, -ux),
        clockwise: bumpsClockwise(run),
    };
};

/**
 * Whether a hop found on a run still has room on it as drawn: a line cut
 * back for its arrowhead is shorter than the one the hops were found on.
 */
export const stillOn = (run: Run, at: Point, radius: number): boolean => {
    const span = length(run);
    const along =
        ((at.x - run.from.x) * (run.to.x - run.from.x) +
            (at.y - run.from.y) * (run.to.y - run.from.y)) /
        (span || 1);

    return along > radius && along < span - radius;
};
