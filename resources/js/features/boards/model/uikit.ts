// The UI kit's parts -- a status bar, a button, a toggle, a list row -- drawn
// for the size they are. A library shape is a path in a 100 by 100 box
// stretched to its item, which would squash a toggle's knob and round a
// button's corners into ovals; a kit part is worked out at its item's own
// width and height instead, in pixels, and handed over in that same 100 by
// 100 box, so the canvas, the hand-drawn look and pictures of the board draw
// it as they draw any shape -- and its corners stay round.
//
// Each part names its drawing in lib/shapes.json ("kit"); the path kept there
// is this drawing at the part's own size, for its picture in the library.

/** An outline, and the parts drawn solid over it, in the 100 by 100 box. */
export type KitDrawing = { path: string; solid?: string };

type Point = [number, number];

/**
 * Draws in the item's pixels and writes in the 100 by 100 box: x scaled by
 * 100 / width, y by 100 / height, and a circle's radii each its own way so
 * it is round again once stretched back.
 */
class Pen {
    // Written out, not as parameter properties: Node runs this file as it is
    // (collab/circuit.mjs), and strips types without compiling anything
    private width: number;
    private height: number;

    constructor(width: number, height: number) {
        this.width = width;
        this.height = height;
    }

    private x = (value: number) => +((value * 100) / this.width).toFixed(2);
    private y = (value: number) => +((value * 100) / this.height).toFixed(2);
    private at = ([px, py]: Point) => `${this.x(px)},${this.y(py)}`;
    private arc = (radius: number, sweep: 0 | 1, to: Point) =>
        `A${this.x(radius)},${this.y(radius)} 0 0 ${sweep} ${this.at(to)}`;

    /** A box with round corners, clockwise -- or the other way, for a hole. */
    box(
        left: number,
        top: number,
        width: number,
        height: number,
        radius: number,
        hole = false,
    ): string {
        const r = Math.max(0, Math.min(radius, width / 2, height / 2));
        const right = left + width;
        const bottom = top + height;

        if (!r) {
            const corners: Point[] = [
                [left, top],
                [right, top],
                [right, bottom],
                [left, bottom],
            ];

            return this.shape(hole ? corners.reverse() : corners);
        }

        return hole
            ? `M${this.at([left + r, top])} ${this.arc(r, 0, [left, top + r])} L${this.at([left, bottom - r])} ${this.arc(r, 0, [left + r, bottom])} L${this.at([right - r, bottom])} ${this.arc(r, 0, [right, bottom - r])} L${this.at([right, top + r])} ${this.arc(r, 0, [right - r, top])} Z`
            : `M${this.at([left + r, top])} L${this.at([right - r, top])} ${this.arc(r, 1, [right, top + r])} L${this.at([right, bottom - r])} ${this.arc(r, 1, [right - r, bottom])} L${this.at([left + r, bottom])} ${this.arc(r, 1, [left, bottom - r])} L${this.at([left, top + r])} ${this.arc(r, 1, [left + r, top])} Z`;
    }

    /** A circle, clockwise -- or the other way, for a hole. */
    circle(cx: number, cy: number, radius: number, hole = false): string {
        const sweep = hole ? 0 : 1;

        return `M${this.at([cx - radius, cy])} ${this.arc(radius, sweep, [cx + radius, cy])} ${this.arc(radius, sweep, [cx - radius, cy])} Z`;
    }

    /** The top half of a circle, standing on its flat side. */
    dome(cx: number, foot: number, radius: number): string {
        return `M${this.at([cx - radius, foot])} ${this.arc(radius, 1, [cx + radius, foot])} Z`;
    }

    /** A box rounded along its top only: a sheet up from the foot. */
    topRounded(width: number, height: number, radius: number): string {
        const r = Math.max(0, Math.min(radius, width / 2, height));

        return `M${this.at([0, height])} L${this.at([0, r])} ${this.arc(r, 1, [r, 0])} L${this.at([width - r, 0])} ${this.arc(r, 1, [width, r])} L${this.at([width, height])} Z`;
    }

    /** A closed shape through these points. */
    shape(points: Point[]): string {
        return `M${points.map(this.at).join(' L')} Z`;
    }

    /**
     * A line `thickness` wide through these points, as a shape to fill: a
     * chevron, a tick. Each corner is mitred, as a pen would draw it.
     */
    line(points: Point[], thickness: number): string {
        const half = thickness / 2;
        const normals = points.slice(1).map(([x2, y2], index) => {
            const [x1, y1] = points[index];
            const length = Math.hypot(x2 - x1, y2 - y1) || 1;

            return [-(y2 - y1) / length, (x2 - x1) / length] as Point;
        });

        // Each point pushed out along the mean of the normals either side of it
        const offset = (side: 1 | -1) =>
            points.map(([px, py], index): Point => {
                const before = normals[Math.max(0, index - 1)];
                const after = normals[Math.min(normals.length - 1, index)];
                const nx = (before[0] + after[0]) / 2;
                const ny = (before[1] + after[1]) / 2;
                const scale =
                    half / Math.max(0.35, nx * after[0] + ny * after[1]);

                return [px + side * nx * scale, py + side * ny * scale];
            });

        return this.shape([...offset(1), ...offset(-1).reverse()]);
    }
}

/** A chevron as a line: pointing right, left or down, `size` across. */
const chevron = (
    pen: Pen,
    cx: number,
    cy: number,
    size: number,
    toward: 'right' | 'left' | 'down',
    thickness = 2.2,
): string => {
    const half = size / 2;
    const depth = size / 4;
    const points: Point[] =
        toward === 'down'
            ? [
                  [cx - half, cy - depth],
                  [cx, cy + depth],
                  [cx + half, cy - depth],
              ]
            : toward === 'right'
              ? [
                    [cx - depth, cy - half],
                    [cx + depth, cy],
                    [cx - depth, cy + half],
                ]
              : [
                    [cx + depth, cy - half],
                    [cx - depth, cy],
                    [cx + depth, cy + half],
                ];

    return pen.line(points, thickness);
};

const DRAWINGS: Record<
    string,
    (pen: Pen, w: number, h: number, on: boolean) => KitDrawing
> = {
    // The time on the left; signal and battery on the right
    status_bar: (pen, w, h) => {
        const battery = w - 16 - 25;
        const bars = [4, 6, 8, 10].map((tall, index) =>
            pen.box(battery - 26 + index * 5, h / 2 + 5 - tall, 3, tall, 1),
        );

        return {
            path: pen.box(0, 0, w, h, 0),
            solid: [
                ...bars,
                pen.box(battery, h / 2 - 6, 25, 12, 3.5),
                pen.box(battery + 2, h / 2 - 4, 21, 8, 2, true),
                pen.box(battery + 4, h / 2 - 2.5, 13, 5, 1),
                pen.box(battery + 26, h / 2 - 2.5, 2, 5, 1),
            ].join(' '),
        };
    },

    // Back on the left; the title is its label
    top_bar: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, 0),
        solid: chevron(pen, 22, h / 2, 16, 'left', 2.6),
    }),

    // Four tabs, each an icon over a word, and the home bar under them
    tab_bar: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, 0),
        solid: [0, 1, 2, 3]
            .flatMap((tab) => {
                const cx = (w * (tab + 0.5)) / 4;

                // The tab shown now filled in; the others outlined
                return [
                    pen.box(cx - 11, 10, 22, 22, 6),
                    ...(tab ? [pen.box(cx - 9, 12, 18, 18, 4.5, true)] : []),
                    pen.box(cx - 14, 38, 28, 3, 1.5),
                ];
            })
            .concat(pen.box(w / 2 - 67, h - 13, 134, 5, 2.5))
            .join(' '),
    }),

    button: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, Math.min(12, h / 2)),
    }),
    button_secondary: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, Math.min(12, h / 2)),
    }),
    button_text: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, Math.min(12, h / 2)),
    }),

    text_field: (pen, w, h) => ({ path: pen.box(0, 0, w, h, 10) }),

    // A magnifier, then what to search for
    search_field: (pen, w, h) => {
        const cx = 23;
        const cy = h / 2 - 1;

        return {
            path: pen.box(0, 0, w, h, Math.min(12, h / 2)),
            solid: [
                pen.circle(cx, cy, 6.5),
                pen.circle(cx, cy, 4.5, true),
                pen.line(
                    [
                        [cx + 4.4, cy + 4.4],
                        [cx + 8.4, cy + 8.4],
                    ],
                    2.2,
                ),
            ].join(' '),
        };
    },

    // A choice, and the chevron that opens the list
    dropdown: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, 10),
        solid: chevron(pen, w - 24, h / 2, 12, 'down'),
    }),

    // On: the knob at the right end of a coloured track; off, the left
    toggle: (pen, w, h, on) => ({
        path: pen.box(0, 0, w, h, h / 2),
        solid: pen.circle(on ? w - h / 2 : h / 2, h / 2, h / 2 - 3),
    }),

    // Ticked when on; an empty box when off
    checkbox: (pen, w, h, on) => ({
        path: pen.box(0, 0, w, h, Math.min(6, w / 4, h / 4)),
        solid: on
            ? pen.line(
                  [
                      [w * 0.26, h * 0.52],
                      [w * 0.43, h * 0.69],
                      [w * 0.75, h * 0.33],
                  ],
                  Math.max(2, Math.min(w, h) * 0.11),
              )
            : undefined,
    }),

    // The dot when it is the one chosen
    radio: (pen, w, h, on) => {
        const round = Math.min(w, h) / 2;

        return {
            path: pen.circle(w / 2, h / 2, round),
            solid: on ? pen.circle(w / 2, h / 2, round * 0.45) : undefined,
        };
    },

    // One of a row of tabs: its word, underlined when it is the one shown
    tab: (pen, w, h, on) => ({
        path: pen.box(0, 0, w, h, 0),
        solid: on ? pen.box(w * 0.12, h - 3, w * 0.76, 3, 1.5) : undefined,
    }),

    // Its words on the left, a chevron on the right
    list_row: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, 0),
        solid: chevron(pen, w - 22, h / 2, 13, 'right', 2.2),
    }),

    card: (pen, w, h) => ({ path: pen.box(0, 0, w, h, 16) }),

    // A head and shoulders on a disc
    avatar: (pen, w, h) => {
        const round = Math.min(w, h) / 2;
        const cx = w / 2;
        const cy = h / 2;
        const foot = cy + round * 0.72;

        return {
            path: pen.circle(cx, cy, round),
            solid: [
                pen.circle(cx, cy - round * 0.18, round * 0.34),
                pen.dome(cx, foot, round * 0.6),
            ].join(' '),
        };
    },

    // A picture yet to come: hills and a sun in the middle
    image_placeholder: (pen, w, h) => {
        const cx = w / 2;
        const cy = h / 2;

        return {
            path: pen.box(0, 0, w, h, 12),
            solid: [
                pen.shape([
                    [cx - 26, cy + 16],
                    [cx - 9, cy - 4],
                    [cx + 1, cy + 7],
                    [cx + 10, cy - 2],
                    [cx + 26, cy + 16],
                ]),
                pen.circle(cx + 11, cy - 14, 5.5),
            ].join(' '),
        };
    },

    badge: (pen, w, h) => ({ path: pen.box(0, 0, w, h, h / 2) }),

    dialog: (pen, w, h) => ({ path: pen.box(0, 0, w, h, 16) }),

    // A panel up from the foot of the screen, with its grabber
    sheet: (pen, w, h) => ({
        path: pen.topRounded(w, h, 20),
        solid: pen.box(w / 2 - 18, 8, 36, 5, 2.5),
    }),

    // A word that something worked, with a dot to say it did
    toast: (pen, w, h) => ({
        path: pen.box(0, 0, w, h, 12),
        solid: pen.circle(26, h / 2, 6),
    }),

    // Bars rising across a card, for a chart to come
    chart: (pen, w, h) => {
        const heights = [0.35, 0.55, 0.45, 0.7, 0.6, 0.85];
        const left = 24;
        const room = w - 48;
        const gap = room / heights.length;
        const floor = h - 24;
        const tallest = Math.max(10, h - 72);

        return {
            path: pen.box(0, 0, w, h, 12),
            solid: heights
                .map((share, index) =>
                    pen.box(
                        left + index * gap + gap * 0.18,
                        floor - tallest * share,
                        gap * 0.64,
                        tallest * share,
                        3,
                    ),
                )
                .join(' '),
        };
    },
};

/** The kit parts there are, by their drawing's name. */
export const KIT_PARTS = Object.keys(DRAWINGS);

/**
 * A kit part drawn at its item's size, in the 100 by 100 box every shape is
 * drawn in -- or nothing, for a name no part has.
 */
export const kitDrawing = (
    name: string,
    width: number,
    height: number,
    // A switch, a box, a radio or a tab: on (as new ones are) or off
    on = true,
): KitDrawing | null => {
    const draw = DRAWINGS[name];

    if (!draw) {
        return null;
    }

    const w = Math.max(1, width);
    const h = Math.max(1, height);

    return draw(new Pen(w, h), w, h, on);
};
