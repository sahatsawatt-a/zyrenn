import type { GradientStop, Item } from './items';

type Painted = Pick<
    Item,
    | 'fill'
    | 'gradient'
    | 'gradientAngle'
    | 'gradientStops'
    | 'gradientType'
    | 'fillOpacity'
>;

type Lined = Pick<
    Item,
    | 'stroke'
    | 'strokeGradient'
    | 'strokeStops'
    | 'strokeAngle'
    | 'strokeOpacity'
>;

/**
 * A colour made see-through by `alpha` (0 to 1): a hex colour as rgba; any
 * other way of writing one left as it is.
 */
export function withAlpha(colour: string, alpha: number | undefined): string {
    if (alpha === undefined || alpha >= 1 || !colour.startsWith('#')) {
        return colour;
    }

    const hex = colour.slice(1);
    const full =
        hex.length === 3 || hex.length === 4
            ? hex
                  .split('')
                  .map((digit) => digit + digit)
                  .join('')
            : hex;
    const [r, g, b] = [0, 2, 4].map((at) =>
        parseInt(full.slice(at, at + 2), 16),
    );
    const own = full.length === 8 ? parseInt(full.slice(6, 8), 16) / 255 : 1;

    return Number.isNaN(r + g + b)
        ? colour
        : `rgba(${r}, ${g}, ${b}, ${Math.max(0, alpha) * own})`;
}

/** The colours a fade runs through: its stops, or from one colour to another. */
const stopsOf = (
    stops: GradientStop[] | undefined,
    from: string,
    to: string | undefined,
    alpha: number | undefined,
): (number | string)[] | null => {
    const list =
        stops && stops.length >= 2
            ? [...stops].sort((a, b) => a.at - b.at)
            : to
              ? [
                    { at: 0, color: from },
                    { at: 1, color: to },
                ]
              : null;

    return list
        ? list.flatMap((stop) => [
              Math.min(1, Math.max(0, stop.at)),
              withAlpha(stop.color, alpha),
          ])
        : null;
};

/** The two ends of a straight fade across a box, at an angle as CSS writes it. */
const ends = (
    angle: number,
    width: number,
    height: number,
    left: number,
    top: number,
) => {
    const turn = ((angle % 360) * Math.PI) / 180;
    const across = Math.sin(turn);
    const down = -Math.cos(turn);
    // Far enough along the angle that the fade spans the whole box
    const reach = (Math.abs(across) * width + Math.abs(down) * height) / 2;
    const middle = { x: left + width / 2, y: top + height / 2 };

    return {
        start: { x: middle.x - across * reach, y: middle.y - down * reach },
        end: { x: middle.x + across * reach, y: middle.y + down * reach },
        middle,
    };
};

/**
 * How an item's inside is painted: its fill, or a fade -- to a second colour
 * in `gradient`, or through `gradientStops` -- straight at `gradientAngle`
 * degrees the way CSS writes one (180, the default, top to bottom), or out
 * from the middle when `gradientType` is "radial". `width` and `height` are
 * the box the shape is drawn in -- for a shape drawn from a path, the path's
 * own -- and `left`, `top` where that box starts, for a shape drawn round its
 * middle. `fillOpacity` makes the inside see-through and not the line.
 */
export function paint(
    item: Painted,
    width: number,
    height: number,
    left = 0,
    top = 0,
): Record<string, unknown> {
    const fill = withAlpha(item.fill, item.fillOpacity);
    // A fade to one more colour needs a colour to start from; stops do not
    const stops = stopsOf(
        item.gradientStops,
        item.fill,
        item.fill && item.fill !== 'transparent' ? item.gradient : undefined,
        item.fillOpacity,
    );

    if (!stops) {
        return { fill };
    }

    const { start, end, middle } = ends(
        item.gradientAngle ?? 180,
        width,
        height,
        left,
        top,
    );

    if (item.gradientType === 'radial') {
        return {
            fill,
            fillPriority: 'radial-gradient',
            fillRadialGradientStartPoint: middle,
            fillRadialGradientEndPoint: middle,
            fillRadialGradientStartRadius: 0,
            fillRadialGradientEndRadius: Math.max(width, height) / 2,
            fillRadialGradientColorStops: stops,
        };
    }

    return {
        fill,
        fillPriority: 'linear-gradient',
        fillLinearGradientStartPoint: start,
        fillLinearGradientEndPoint: end,
        fillLinearGradientColorStops: stops,
    };
}

/**
 * How an item's line is painted: its colour, or a fade along it -- to
 * `strokeGradient`, or through `strokeStops` -- at `strokeAngle` (90, the
 * default, left to right) across its box; or, for a connector, from its
 * `start` point to its `end`. `strokeOpacity` makes the line see-through.
 */
export function strokePaint(
    item: Lined,
    width: number,
    height: number,
    along?: { start: { x: number; y: number }; end: { x: number; y: number } },
): Record<string, unknown> {
    const stroke = withAlpha(item.stroke, item.strokeOpacity);
    const stops = stopsOf(
        item.strokeStops,
        item.stroke,
        item.strokeGradient,
        item.strokeOpacity,
    );

    if (!stops) {
        return { stroke };
    }

    const { start, end } =
        along ?? ends(item.strokeAngle ?? 90, width, height, 0, 0);

    return {
        stroke,
        strokeLinearGradientStartPoint: start,
        strokeLinearGradientEndPoint: end,
        strokeLinearGradientColorStops: stops,
    };
}

/** The CSS for an item's paint, for what is drawn outside the canvas. */
export const cssPaint = (item: Painted): string => {
    const stops = stopsOf(
        item.gradientStops,
        item.fill,
        item.gradient,
        item.fillOpacity,
    );

    if (!stops) {
        return withAlpha(item.fill, item.fillOpacity);
    }

    const parts: string[] = [];

    for (let at = 0; at < stops.length; at += 2) {
        parts.push(`${stops[at + 1]} ${Number(stops[at]) * 100}%`);
    }

    return item.gradientType === 'radial'
        ? `radial-gradient(circle, ${parts.join(', ')})`
        : `linear-gradient(${item.gradientAngle ?? 180}deg, ${parts.join(', ')})`;
};
