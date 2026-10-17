// Lines drawn as by hand: what an item's outline is (its figure), and that
// outline gone over twice with an unsteady hand by Rough.js -- the same
// wobble every time it is drawn, since the hand is seeded by the item's id,
// so it holds still through a pan, a zoom and every redraw.
import type { Drawable, Op, OpSet } from 'roughjs/bin/core';
import { RoughGenerator } from 'roughjs/bin/generator';
import { polygonPoints } from '../model/geometry';
import type { Item } from '../model/items';
import { PATHS, POLYGONS, isPath } from '../model/items';
import { hasHeader } from '../model/rows';
import { lookOf, shapeOf } from '../model/shapes';
import { CARD_RADIUS } from '../model/structure';
import { mapPoints, parsePath, pathString } from '../pen/pathData';

/**
 * An outline, in the item's own units from its top left: a box with round
 * corners, an ellipse round a middle, a closed polygon, SVG path data, or a
 * line through points -- curved, it runs through them as Konva's bezier does.
 */
export type Figure =
    | { type: 'rect'; width: number; height: number; radius: number }
    | { type: 'ellipse'; x: number; y: number; width: number; height: number }
    | { type: 'polygon'; points: number[] }
    | { type: 'path'; d: string }
    | { type: 'line'; points: number[]; curved: boolean };

/** Path data put through a scale, so the wobble is in board units. */
const scaled = (d: string, x: number, y: number) =>
    pathString(mapPoints(parsePath(d), (px, py) => ({ x: px * x, y: py * y })));

/** A five-pointed star, as Konva draws one: its first point straight up. */
const starPoints = (item: Item): number[] => {
    const outer = Math.min(item.width, item.height) / 2;
    const points: number[] = [];

    for (let n = 0; n < 10; n++) {
        const radius = n % 2 === 0 ? outer : outer / 2;
        const angle = (n * Math.PI) / 5;

        points.push(
            item.width / 2 + radius * Math.sin(angle),
            item.height / 2 - radius * Math.cos(angle),
        );
    }

    return points;
};

/**
 * The outline an item is drawn by hand along -- null for one drawn crisp
 * whatever: a lifeline is a box over a dashed line, kept as it is.
 */
export const figureOf = (item: Item): Figure | null => {
    const { width, height } = item;
    const box = (radius: number): Figure => ({
        type: 'rect',
        width,
        height,
        radius: Math.max(0, Math.min(radius, width / 2, height / 2)),
    });

    switch (item.kind) {
        case 'frame':
            return box(item.radius ?? 0);
        case 'sticky':
            return box(item.radius ?? 4);
        case 'rect':
            return box(item.radius ?? 8);
        case 'pill':
            return box(Math.min(width, height) / 2);
        case 'ellipse':
            return {
                type: 'ellipse',
                x: width / 2,
                y: height / 2,
                width,
                height,
            };
        case 'star':
            return { type: 'polygon', points: starPoints(item) };
        case 'path':
            return {
                type: 'path',
                d: scaled(
                    item.d ?? '',
                    width / Math.max(1, item.viewWidth ?? width),
                    height / Math.max(1, item.viewHeight ?? height),
                ),
            };
    }

    if (POLYGONS.includes(item.kind)) {
        return { type: 'polygon', points: polygonPoints(item) };
    }

    if (!isPath(item.kind) || shapeOf(item.kind)?.slide) {
        return null;
    }

    const look = lookOf(item);

    if (hasHeader(item)) {
        return box(CARD_RADIUS);
    }

    if (look.frame === 'card') {
        return box(Math.min(14, width / 4, height / 4));
    }

    if (look.frame === 'badge') {
        const round = Math.min(width, height);

        return {
            type: 'ellipse',
            x: width / 2,
            y: height - round / 2,
            width: round,
            height: round,
        };
    }

    return {
        type: 'path',
        d: scaled(
            look.path ?? PATHS[item.kind] ?? '',
            width / 100,
            height / 100,
        ),
    };
};

/** A box with round corners, as path data. */
const roundedBox = ({
    width: w,
    height: h,
    radius: r,
}: {
    width: number;
    height: number;
    radius: number;
}) =>
    r <= 0
        ? `M0 0 L${w} 0 L${w} ${h} L0 ${h} Z`
        : `M${r} 0 L${w - r} 0 Q${w} 0 ${w} ${r} L${w} ${h - r} Q${w} ${h} ${w - r} ${h} ` +
          `L${r} ${h} Q0 ${h} 0 ${h - r} L0 ${r} Q0 0 ${r} 0 Z`;

/** A line through points, as path data: straight runs, or Konva's bezier. */
const linePath = (points: number[], curved: boolean) => {
    if (points.length < 4) {
        return '';
    }

    const parts = [`M${points[0]} ${points[1]}`];

    if (curved && (points.length - 2) % 6 === 0) {
        for (let at = 2; at < points.length; at += 6) {
            parts.push(`C${points.slice(at, at + 6).join(' ')}`);
        }
    } else {
        for (let at = 2; at < points.length; at += 2) {
            parts.push(`L${points[at]} ${points[at + 1]}`);
        }
    }

    return parts.join(' ');
};

/**
 * The figure as crisp path data: where it takes the pointer, whatever its
 * wobble.
 */
export const outlineOf = (figure: Figure): string => {
    switch (figure.type) {
        case 'rect':
            return roundedBox(figure);
        case 'ellipse': {
            const rx = figure.width / 2;
            const ry = figure.height / 2;

            return (
                `M${figure.x - rx} ${figure.y} A${rx} ${ry} 0 1 0 ${figure.x + rx} ${figure.y} ` +
                `A${rx} ${ry} 0 1 0 ${figure.x - rx} ${figure.y} Z`
            );
        }
        case 'polygon':
            return `${linePath(figure.points, false)} Z`;
        case 'path':
            return figure.d;
        case 'line':
            return linePath(figure.points, figure.curved);
    }
};

/** How the hand goes over it. */
export type Hand = {
    // Its seed: the same one draws the same wobble
    seed: number;
    // A little unsteady, or a lot
    level: 1 | 2;
    // How heavy its line is; none at all, at 0
    lineWidth: number;
    // Whether it has an inside to fill, and with what
    filled: boolean;
    hatch: 'solid' | 'hachure' | 'cross';
};

/**
 * A seed from an item's id: a whole number from 1 up (Rough.js reaches for
 * Math.random at 0), the same for the same id on every machine.
 */
export const seedOf = (id: string): number => {
    let hash = 2166136261;

    for (let at = 0; at < id.length; at++) {
        hash = Math.imul(hash ^ id.charCodeAt(at), 16777619);
    }

    return ((hash >>> 0) % 2147483646) + 1;
};

const generator = new RoughGenerator();

/** The parts of a drawing: a line to stroke, an inside to fill, hatching. */
export type Sketched = OpSet[];

const drawn = (figure: Figure, hand: Hand): Drawable => {
    // Options are made new for every drawing: Rough.js keeps its random
    // sequence on them, and a sequence carried over would wobble differently
    const options = {
        seed: hand.seed,
        roughness: hand.level === 2 ? 2.4 : 1.1,
        bowing: hand.level === 2 ? 2 : 1,
        strokeWidth: Math.max(0.5, hand.lineWidth),
        // Colours are put on when it is painted (SketchShape); these only
        // say there is a line and an inside
        stroke: hand.lineWidth > 0 ? 'ink' : 'none',
        fill: hand.filled ? 'fill' : undefined,
        fillStyle:
            hand.hatch === 'cross'
                ? 'cross-hatch'
                : hand.hatch === 'hachure'
                  ? 'hachure'
                  : 'solid',
        fillWeight: Math.max(1, hand.lineWidth / 2),
        hachureGap: Math.max(6, hand.lineWidth * 4),
    };

    switch (figure.type) {
        case 'ellipse':
            return generator.ellipse(
                figure.x,
                figure.y,
                figure.width,
                figure.height,
                options,
            );
        case 'polygon': {
            const pairs: [number, number][] = [];

            for (let at = 0; at + 1 < figure.points.length; at += 2) {
                pairs.push([figure.points[at], figure.points[at + 1]]);
            }

            return generator.polygon(pairs, options);
        }
        case 'line':
            return generator.path(outlineOf(figure), {
                ...options,
                fill: undefined,
            });
        default:
            return generator.path(outlineOf(figure), options);
    }
};

/** Drawings are kept by what they depend on; resizing makes many. */
const MAX_KEPT = 2000;
const kept = new Map<string, Sketched>();

/**
 * The figure gone over by hand: its line, its fill and any hatching, as
 * Rough.js's moves and curves. The same figure and hand give back the same
 * drawing, worked out once.
 */
export const sketchOf = (figure: Figure, hand: Hand): Sketched => {
    const key = `${JSON.stringify(figure)}|${hand.seed}|${hand.level}|${hand.lineWidth}|${hand.filled}|${hand.hatch}`;
    const found = kept.get(key);

    if (found) {
        return found;
    }

    if (kept.size > MAX_KEPT) {
        kept.clear();
    }

    const sets = drawn(figure, hand).sets.filter(
        // A line of no weight is no line
        (set) => set.type !== 'path' || hand.lineWidth > 0,
    );

    kept.set(key, sets);

    return sets;
};

/** One part's moves and curves, as path data a Path2D takes. */
export const opsPath = (ops: Op[]): string =>
    ops
        .map(({ op, data }) =>
            op === 'move'
                ? `M${data[0]} ${data[1]}`
                : op === 'lineTo'
                  ? `L${data[0]} ${data[1]}`
                  : `C${data.join(' ')}`,
        )
        .join(' ');
