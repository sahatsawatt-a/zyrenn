// The shapes of things on the board: the box each item occupies, the anchors
// around it, and which of them faces a point.
import { isSymbol, pinsOf, symbolBox } from '@/features/boards/circuit/symbols';
import type { Item, ItemKind, Side } from './items';
import { isStroke } from './items';
import { rowAnchors } from './rows';
import { designOf, shapeOf } from './shapes';

/** The box an item occupies, including stroke items built from points. */
export const boundsOf = (item: Item) => {
    // A part is turned on end as often as not, and its pins with it: its box
    // is the one it covers as turned
    if (isSymbol(item)) {
        return symbolBox(item);
    }

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

/**
 * How far inside its box a shape's outline lies at the middle of each side, as
 * a share of the box. Most shapes are drawn out to the box, but a cloud's
 * bumps, a document's wave and a slanted side stop short of it -- and a line
 * ending on the box would stop short of the shape. Measured from the drawings
 * (PATHS, polygonPoints).
 */
const INSETS: Partial<Record<ItemKind, Partial<Record<Side, number>>>> = {
    cloud: { top: 0.22, bottom: 0.22, left: 0.11, right: 0.11 },
    document: { bottom: 0.12 },
    parallelogram: { left: 0.11, right: 0.11 },
    triangle: { left: 0.25, right: 0.25 },
};

/**
 * Where a side's anchor sits on a shape, in board coordinates: its middle, or
 * `along` that side from its top or left end (0 to 1).
 */
export const anchorAt = (item: Item, side: Side, along = 0.5) => {
    const { x, y, width, height } = boundsOf(item);
    // A design drawn its own way -- a card -- says where its outline is
    const inset =
        designOf(item)?.insets?.[side] ??
        INSETS[item.kind]?.[side] ??
        shapeOf(item.kind)?.insets?.[side] ??
        0;

    switch (side) {
        case 'top':
            return { x: x + width * along, y: y + height * inset };
        case 'bottom':
            return { x: x + width * along, y: y + height * (1 - inset) };
        case 'left':
            return { x: x + width * inset, y: y + height * along };
        default:
            return { x: x + width * (1 - inset), y: y + height * along };
    }
};

/**
 * Whether a side of a shape is a straight edge of its box, so points along it
 * are on the outline. A diamond's side is a single tip and an ellipse's a
 * curve: anywhere but the middle would be off the shape.
 */
export const isFlatSide = (item: Item, side: Side): boolean => {
    const design = designOf(item);

    if (design?.flat) {
        return design.flat.includes(side);
    }

    switch (item.kind) {
        case 'rect':
        case 'sticky':
        case 'frame':
        case 'text':
        case 'image':
        case 'video':
        case 'math':
        case 'process':
            return true;
        case 'cylinder':
            return side === 'left' || side === 'right';
        case 'pill':
        case 'parallelogram':
            return side === 'top' || side === 'bottom';
        case 'document':
            return side !== 'bottom';
        default:
            return shapeOf(item.kind)?.flat?.includes(side) ?? false;
    }
};

/** One of the dots a connector can hold on to: a face, or a symbol's pin. */
export type Anchor = {
    side: Side | null;
    pin?: string;
    x: number;
    y: number;
};

/** Where a connector can hold on: a shape's four faces, a symbol's pins, a table's rows. */
export const anchorsOf = (item: Item): Anchor[] =>
    isSymbol(item)
        ? pinsOf(item).map((pin) => ({
              side: pin.side,
              pin: pin.name,
              x: pin.x,
              y: pin.y,
          }))
        : [
              // Each row of a table or a class, on either edge: tried first,
              // so a row is what an end near one lands on
              ...rowAnchors(item),
              ...SIDES.map((side) => ({ side, ...anchorAt(item, side) })),
          ];

/** The way from one point to another, as the face that looks that way. */
export const sideTowards = (
    from: { x: number; y: number },
    to: { x: number; y: number },
): Side => {
    const dx = to.x - from.x;
    const dy = to.y - from.y;

    if (Math.abs(dx) >= Math.abs(dy)) {
        return dx >= 0 ? 'right' : 'left';
    }

    return dy >= 0 ? 'bottom' : 'top';
};

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

/** Keeps a picture to a sensible size on the board, in proportion. */
export const fitOnBoard = (width: number, height: number) => {
    const longest = Math.max(width, height);
    const factor = longest < 120 ? 200 / longest : Math.min(1, 480 / longest);

    return {
        width: Math.round(width * factor),
        height: Math.round(height * factor),
    };
};
