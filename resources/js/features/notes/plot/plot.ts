import { compile, number } from './expression';
import type { Expression, Scope } from './expression';

// A graph written as a few lines of text, the way a circuit is:
//
//     title: Charging a capacitor
//     x: Time (s)
//     y: Voltage (V), 0 to 6
//     t = 0 to 5
//     R = 1 from 0.2 to 5
//     C = 1
//     V(t) = 5 * (1 - exp(-t / (R * C)))
//
// "t = 0 to 5" is what runs along the bottom, "R = 1 from 0.2 to 5" a value
// the reader can slide between 0.2 and 5, "C = 1" a value that stays, and
// "V(t) = …" a curve. With "table: Readings", x and y name its columns.

export type Parameter = {
    name: string;
    value: number;
    min: number;
    max: number;
    step: number;
};

type Step = { name: string; formula: Expression };

export type Plot = {
    title: string;
    xLabel: string;
    yLabel: string;
    // What runs along the bottom, and between which values
    variable: string;
    from: number;
    to: number;
    // The height of the graph, when it is said and not fitted
    range: [number, number] | null;
    parameters: Parameter[];
    // Values worked out from others, in the order they are written
    steps: Step[];
    curves: Step[];
    // A table to draw from: its title, the column across and the columns up
    table: string | null;
    across: string;
    columns: string[];
};

export type Parsed = { ok: true; plot: Plot } | { ok: false; error: string };

/** A column of a table, as points to draw. */
export type Points = { name: string; points: [number, number][] };

const SETTING = /^(title|x|y|table)\s*:\s*(.*)$/i;
const CURVE =
    /^([A-Za-z_][A-Za-z0-9_]*)\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)\s*=\s*(.+)$/;
const VALUE = /^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+)$/;
const SPAN = /^(.+?)\s+to\s+(.+)$/i;
const SLIDE = /^(.+?)\s+from\s+(.+?)\s+to\s+(.+?)(?:\s+step\s+(.+))?$/i;

// A hundred stops or so between the ends, each a round number
const stepFor = (min: number, max: number): number => {
    const rough = (max - min) / 100;
    const power = 10 ** Math.floor(Math.log10(rough));
    const lead = rough / power;

    return (lead >= 5 ? 5 : lead >= 2 ? 2 : 1) * power;
};

export const parsePlot = (source: string): Parsed => {
    const plot: Plot = {
        title: '',
        xLabel: '',
        yLabel: '',
        variable: '',
        from: 0,
        to: 10,
        range: null,
        parameters: [],
        steps: [],
        curves: [],
        table: null,
        across: '',
        columns: [],
    };
    const spans = new Map<string, [number, number]>();
    const known = new Set<string>();
    let x = '';
    let y = '';
    let line = 0;

    try {
        for (const raw of source.split('\n')) {
            line += 1;
            const text = raw.trim();

            if (text === '' || text.startsWith('#')) {
                continue;
            }

            const setting = SETTING.exec(text);

            if (setting) {
                const value = setting[2].trim();

                switch (setting[1].toLowerCase()) {
                    case 'title':
                        plot.title = value;
                        break;
                    case 'table':
                        plot.table = value;
                        break;
                    case 'x':
                        x = value;
                        break;
                    default:
                        y = value;
                }

                continue;
            }

            const curve = CURVE.exec(text);

            if (curve) {
                if (plot.variable && plot.variable !== curve[2]) {
                    throw new Error(
                        `One thing runs along the bottom of a graph: ${plot.variable}, or ${curve[2]}?`,
                    );
                }

                plot.variable = curve[2];
                plot.curves.push({
                    name: curve[1],
                    formula: compile(curve[3]),
                });
                continue;
            }

            const value = VALUE.exec(text);

            if (!value) {
                throw new Error(
                    `"${text}" is not a line of a graph: "t = 0 to 5", "R = 1 from 0 to 5" and "V(t) = 5 * t" are`,
                );
            }

            const [, name, said] = value;
            const slide = SLIDE.exec(said);
            const span = slide ? null : SPAN.exec(said);

            if (slide) {
                const [min, max] = [number(slide[2]), number(slide[3])];

                if (!(max > min)) {
                    throw new Error(
                        `${name} runs from ${slide[2]} to ${slide[3]}: nowhere`,
                    );
                }

                plot.parameters.push({
                    name,
                    value: Math.min(max, Math.max(min, number(slide[1]))),
                    min,
                    max,
                    step: slide[4]
                        ? Math.abs(number(slide[4])) || stepFor(min, max)
                        : stepFor(min, max),
                });
            } else if (span) {
                const [from, to] = [number(span[1]), number(span[2])];

                if (!(to > from)) {
                    throw new Error(
                        `${name} runs from ${span[1]} to ${span[2]}: nowhere`,
                    );
                }

                spans.set(name, [from, to]);
                continue;
            } else {
                plot.steps.push({ name, formula: compile(said) });
            }

            known.add(name);
        }
    } catch (error) {
        return {
            ok: false,
            error: `Line ${line}: ${error instanceof Error ? error.message : String(error)}`,
        };
    }

    // With a table, x and y are its columns; without, what the axes are called
    // "Voltage (V), 0 to 6": how high the graph goes can follow the name
    const [, label = y, low, high] =
        /^(.*),\s*(\S+)\s+to\s+(\S+)$/i.exec(y) ?? [];

    try {
        if (low !== undefined && high !== undefined) {
            plot.range = [number(low), number(high)];

            if (!(plot.range[1] > plot.range[0])) {
                throw new Error(`y runs from ${low} to ${high}: nowhere`);
            }
        }
    } catch (error) {
        return {
            ok: false,
            error: `y: ${error instanceof Error ? error.message : String(error)}`,
        };
    }

    if (plot.table !== null) {
        plot.across = x;
        plot.columns = label
            .split(',')
            .map((each) => each.trim())
            .filter(Boolean);
        plot.xLabel = x;
        plot.yLabel = plot.columns.length === 1 ? plot.columns[0] : '';

        if (plot.table === '' || x === '' || plot.columns.length === 0) {
            return {
                ok: false,
                error: 'A graph of a table says which: "table: Readings", "x: Time" and "y: Voltage" -- the last two are its columns.',
            };
        }

        plot.variable ||= 'x';
    } else {
        plot.xLabel = x;
        plot.yLabel = label.trim();

        if (plot.curves.length === 0) {
            return {
                ok: false,
                error: 'There is no curve yet: write one as "y(x) = 2 * x + 1".',
            };
        }
    }

    const span = spans.get(plot.variable);

    if (span) {
        [plot.from, plot.to] = span;
        spans.delete(plot.variable);
    }

    // "k = 0 to 5" with no curve over k: a slider was meant, most likely
    const [stray] = spans.keys();

    if (stray !== undefined) {
        return {
            ok: false,
            error: `${stray} runs between two values but no curve is drawn over it. For a value to slide, write "${stray} = 1 from 0 to 5".`,
        };
    }

    // Every name a formula uses has to have been given a value
    known.add(plot.variable);

    for (const each of [...plot.steps, ...plot.curves]) {
        const missing = each.formula.names.find((name) => !known.has(name));

        if (missing !== undefined) {
            return {
                ok: false,
                error: `${each.name} uses ${missing}, which is not given a value: write "${missing} = 1".`,
            };
        }
    }

    return { ok: true, plot };
};

/** Each parameter's value as it is written. */
export const written = (plot: Plot): Scope =>
    Object.fromEntries(plot.parameters.map((each) => [each.name, each.value]));

/** The height of every curve at one place along the bottom. */
export const heightsAt = (plot: Plot, values: Scope, x: number): number[] => {
    const scope: Scope = { ...values, [plot.variable]: x };

    for (const step of plot.steps) {
        scope[step.name] = step.formula.value(scope);
    }

    return plot.curves.map((curve) => curve.formula.value(scope));
};

// ---------------------------------------------------------------- the drawing

export type Geometry = {
    // The plot area, in the drawing's own units
    left: number;
    right: number;
    top: number;
    bottom: number;
    x: [number, number];
    y: [number, number];
};

export type PlotOptions = {
    values?: Scope;
    points?: Points[];
    // The height to keep to at least: what was shown a moment ago, so a
    // slider moves the curve and not the numbers up the side
    hold?: [number, number] | null;
    ink?: string;
    paper?: string;
    // The colours of the first, second … thing drawn
    colours?: string[];
};

export type Drawn = {
    svg: string;
    width: number;
    height: number;
    geometry: Geometry;
    // What is drawn, in the order of its colours
    names: string[];
};

// In fixed order, never by rank: a curve keeps its colour as others come and go
export const COLOURS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];
export const MOST = COLOURS.length;

const WIDTH = 640;
const HEIGHT = 360;
const SAMPLES = 240;

const escape = (text: string): string =>
    text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

const ticks = (from: number, to: number, about: number): number[] => {
    const rough = (to - from) / about;
    const power = 10 ** Math.floor(Math.log10(rough));
    const lead = rough / power;
    const step = (lead >= 5 ? 10 : lead >= 2 ? 5 : lead >= 1.2 ? 2 : 1) * power;
    const found: number[] = [];

    for (
        let at = Math.ceil(from / step - 1e-9) * step;
        at <= to + step * 1e-9;
        at += step
    ) {
        found.push(Math.abs(at) < step * 1e-9 ? 0 : at);
    }

    return found;
};

const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumSignificantDigits: 4,
});

/** A number as short as it can be read: 2.5, 1.2K, 0.004. */
export const short = (value: number): string => {
    if (!Number.isFinite(value)) {
        return '–';
    }

    const size = Math.abs(value);

    return size !== 0 && size < 1e-3
        ? value.toExponential(2).replace(/\.?0+e/, 'e')
        : compact.format(value);
};

export const drawPlot = (plot: Plot, options: PlotOptions = {}): Drawn => {
    const ink = options.ink ?? 'currentColor';
    const colours =
        options.colours ??
        COLOURS.map((colour, index) => `var(--plot-${index + 1}, ${colour})`);
    const surface = options.paper ?? 'var(--background, #ffffff)';
    const values = { ...written(plot), ...options.values };
    const columns = (options.points ?? []).filter(
        (each) => each.points.length > 0,
    );

    // Along the bottom: as far as the table goes, or as the text says
    const across = columns.flatMap((each) => each.points.map(([x]) => x));
    let [from, to] =
        columns.length > 0
            ? [Math.min(...across), Math.max(...across)]
            : [plot.from, plot.to];

    if (!(to > from)) {
        [from, to] = [from - 1, to + 1];
    }

    const lines = plot.curves.map(() => [] as [number, number][]);

    for (let index = 0; index <= SAMPLES; index += 1) {
        const x = from + ((to - from) * index) / SAMPLES;

        heightsAt(plot, values, x).forEach((y, curve) =>
            lines[curve].push([x, y]),
        );
    }

    const series = [
        ...columns.map((each) => ({
            name: each.name,
            points: each.points,
            dots: true,
        })),
        ...plot.curves.map((each, index) => ({
            name: each.name,
            points: lines[index],
            dots: false,
        })),
    ].slice(0, MOST);

    // Up the side: what is said, or what holds everything drawn
    let [low, high] = plot.range ?? [Infinity, -Infinity];

    if (!plot.range) {
        for (const each of series) {
            for (const [, y] of each.points) {
                if (Number.isFinite(y)) {
                    low = Math.min(low, y);
                    high = Math.max(high, y);
                }
            }
        }

        if (options.hold) {
            low = Math.min(low, options.hold[0]);
            high = Math.max(high, options.hold[1]);
        }

        if (!Number.isFinite(low)) {
            [low, high] = [0, 1];
        }

        if (high === low) {
            [low, high] = [low - 1, high + 1];
        }

        // A graph of amounts stands on zero when zero is near
        if (low > 0 && low < (high - low) * 0.5) {
            low = 0;
        }
    }

    const upTicks = ticks(low, high, 5);

    if (!plot.range) {
        // Out to the round numbers either side
        const step = upTicks.length > 1 ? upTicks[1] - upTicks[0] : 1;

        low = Math.floor(low / step + 1e-9) * step;
        high = Math.ceil(high / step - 1e-9) * step;
    }

    const rows = ticks(low, high, 5);
    const marks = ticks(from, to, 7);
    const legend = series.length > 1;
    const top =
        (plot.title ? 30 : 8) + (legend ? 24 : 0) + (plot.yLabel ? 20 : 8);
    const area = {
        left: 16 + Math.max(...rows.map((each) => short(each).length)) * 7 + 8,
        right: WIDTH - 20,
        top,
        bottom: HEIGHT - (plot.xLabel ? 50 : 30),
    };
    const px = (x: number) =>
        area.left + ((x - from) / (to - from)) * (area.right - area.left);
    const py = (y: number) =>
        area.bottom - ((y - low) / (high - low)) * (area.bottom - area.top);
    const round = (value: number) => Math.round(value * 10) / 10;

    const parts: string[] = [];
    const muted = `fill="${ink}" fill-opacity="0.62" font-size="12"`;

    if (plot.title) {
        parts.push(
            `<text x="16" y="22" fill="${ink}" font-size="15" font-weight="600">${escape(plot.title)}</text>`,
        );
    }

    if (legend) {
        let at = 16;
        const y = (plot.title ? 30 : 8) + 12;

        for (const [index, each] of series.entries()) {
            parts.push(
                `<line x1="${at}" y1="${y}" x2="${at + 16}" y2="${y}" stroke="${colours[index]}" stroke-width="3" stroke-linecap="round"/>`,
                `<text x="${at + 22}" y="${y + 4}" fill="${ink}" font-size="12">${escape(each.name)}</text>`,
            );
            at += 22 + each.name.length * 7 + 18;
        }
    }

    if (plot.yLabel) {
        parts.push(
            `<text x="16" y="${area.top - 8}" ${muted}>${escape(plot.yLabel)}</text>`,
        );
    }

    if (plot.xLabel) {
        parts.push(
            `<text x="${(area.left + area.right) / 2}" y="${HEIGHT - 12}" text-anchor="middle" ${muted}>${escape(plot.xLabel)}</text>`,
        );
    }

    // The grid stays back: hairlines, with the line of zero a little firmer
    for (const each of rows) {
        const y = round(py(each));

        parts.push(
            `<line x1="${area.left}" y1="${y}" x2="${area.right}" y2="${y}" stroke="${ink}" stroke-opacity="${each === 0 ? 0.4 : 0.12}" stroke-width="1"/>`,
            `<text x="${area.left - 8}" y="${y + 4}" text-anchor="end" ${muted}>${escape(short(each))}</text>`,
        );
    }

    for (const each of marks) {
        const x = round(px(each));

        parts.push(
            `<line x1="${x}" y1="${area.bottom}" x2="${x}" y2="${area.bottom + 4}" stroke="${ink}" stroke-opacity="0.4" stroke-width="1"/>`,
            `<text x="${x}" y="${area.bottom + 18}" text-anchor="middle" ${muted}>${escape(short(each))}</text>`,
        );
    }

    if (!rows.includes(0)) {
        parts.push(
            `<line x1="${area.left}" y1="${area.bottom}" x2="${area.right}" y2="${area.bottom}" stroke="${ink}" stroke-opacity="0.4" stroke-width="1"/>`,
        );
    }

    // The curves, kept inside the plot; a place with no value is a gap
    const drawn: string[] = [];
    // A table's points, each whole: they are not cut off at the edge
    const dots: string[] = [];

    for (const [index, each] of series.entries()) {
        let path = '';
        let pen = false;

        for (const [x, y] of each.points) {
            if (!Number.isFinite(y) || !Number.isFinite(x)) {
                pen = false;
                continue;
            }

            // Far outside is far enough: a curve running off to infinity
            // still leaves by the right edge
            const height = Math.max(-HEIGHT, Math.min(2 * HEIGHT, py(y)));

            path += `${pen ? 'L' : 'M'}${round(px(x))} ${round(height)}`;
            pen = true;
        }

        drawn.push(
            `<path d="${path}" fill="none" stroke="${colours[index]}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`,
        );

        if (each.dots && each.points.length <= 60) {
            for (const [x, y] of each.points) {
                if (Number.isFinite(y) && y >= low && y <= high) {
                    dots.push(
                        `<circle cx="${round(px(x))}" cy="${round(py(y))}" r="4" fill="${colours[index]}" stroke="${surface}" stroke-width="2"/>`,
                    );
                }
            }
        }
    }

    // What the pointer is over: a line down, a dot on each curve and their
    // values, placed by whoever shows the drawing
    const hover = [
        `<line class="plot-hover-line" y1="${area.top}" y2="${area.bottom}" stroke="${ink}" stroke-opacity="0.45" stroke-width="1"/>`,
        ...series.map(
            (_, index) =>
                `<circle class="plot-hover-dot" r="4.5" fill="${colours[index]}" stroke="${surface}" stroke-width="2"/>`,
        ),
        `<text class="plot-hover-text" x="${area.right}" y="${area.top - 8}" text-anchor="end" fill="${ink}" font-size="12"></text>`,
    ];

    const name = plot.title || series.map((each) => each.name).join(', ');

    const svg = [
        `<svg xmlns="http://www.w3.org/2000/svg" class="plot-drawing" role="img" aria-label="${escape(`Graph: ${name}`)}" width="${WIDTH}" height="${HEIGHT}" viewBox="0 0 ${WIDTH} ${HEIGHT}" font-family="ui-sans-serif, system-ui, sans-serif">`,
        options.paper
            ? `<rect width="${WIDTH}" height="${HEIGHT}" fill="${options.paper}"/>`
            : '',
        `<defs><clipPath id="plot-area-${area.left}-${top}"><rect x="${area.left}" y="${area.top - 2}" width="${area.right - area.left}" height="${area.bottom - area.top + 4}"/></clipPath></defs>`,
        ...parts,
        `<g clip-path="url(#plot-area-${area.left}-${top})">${drawn.join('')}</g>`,
        ...dots,
        `<g class="plot-hover" visibility="hidden" pointer-events="none">${hover.join('')}</g>`,
        '</svg>',
    ].join('');

    return {
        svg,
        width: WIDTH,
        height: HEIGHT,
        geometry: { ...area, x: [from, to], y: [low, high] },
        names: series.map((each) => each.name),
    };
};
