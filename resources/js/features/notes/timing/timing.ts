// A timing diagram drawn from a few lines of text: signals one under another,
// each a row of steps -- low, high, a clock's tick, a value held on a bus.
//
//     title: Writing a byte over SPI
//     CS:   1 0.......... 1
//     CLK:  0 pppppppp ... 0   (spaces are only for the eye)
//     MOSI: x =.=.=.=. x | D7 | D6 | D5 | D4
//     mark: 1 start, 9 done
//
// One character is one step of time: 0 low, 1 high, "." more of the same,
// p a clock pulse (n one that starts low), x unknown, z let go of (high
// impedance), = a value, named in order after a "|". Read by hand, never
// evaluated.

export type Run = {
    kind:
        | 'low'
        | 'high'
        | 'pulse'
        | 'pulse-low'
        | 'unknown'
        | 'floating'
        | 'value';
    // The steps it covers: from the start of one to the start of another
    from: number;
    to: number;
    // A value's name
    label?: string;
};

export type Signal = { name: string; runs: Run[]; steps: number };

export type Timing = {
    title: string;
    // A row to a signal; null is a gap left between two groups of them
    rows: (Signal | null)[];
    marks: { at: number; label: string }[];
    steps: number;
};

export type TimingRead =
    | { ok: true; timing: Timing }
    | { ok: false; error: string };

const KINDS: Record<string, Run['kind']> = {
    '0': 'low',
    l: 'low',
    '1': 'high',
    h: 'high',
    p: 'pulse',
    n: 'pulse-low',
    x: 'unknown',
    z: 'floating',
    '=': 'value',
};

/** One signal's steps as runs: a "." lengthens whatever came before it. */
const runsOf = (wave: string, labels: string[]): Run[] | string => {
    const runs: Run[] = [];
    let named = 0;

    // (a step is one of a handful of plain characters: split by character)
    for (const [at, step] of wave.toLowerCase().split('').entries()) {
        const last = runs[runs.length - 1];

        if (step === '.') {
            if (!last) {
                return 'a signal cannot start with “.”, which means “more of the same”';
            }

            last.to = at + 1;

            continue;
        }

        const kind = KINDS[step];

        if (!kind) {
            return `“${step}” is not something a signal can do: use 0, 1, p, n, x, z, = and “.” for more of the same`;
        }

        // The same again is the same run, but each "=" is a value of its own
        if (last && last.kind === kind && kind !== 'value') {
            last.to = at + 1;

            continue;
        }

        runs.push({
            kind,
            from: at,
            to: at + 1,
            ...(kind === 'value' ? { label: labels[named++] ?? '' } : {}),
        });
    }

    return runs;
};

/** Reads the text of a timing diagram, or says which line it could not read. */
export const parseTiming = (source: string): TimingRead => {
    const timing: Timing = { title: '', rows: [], marks: [], steps: 0 };

    for (const [index, raw] of source.split('\n').entries()) {
        const line = raw.trim();
        const fail = (problem: string): TimingRead => ({
            ok: false,
            error: `Line ${index + 1}: ${problem}.`,
        });

        if (line.startsWith('#')) {
            continue;
        }

        // An empty line between signals sets the groups apart
        if (!line) {
            if (timing.rows.length && timing.rows[timing.rows.length - 1]) {
                timing.rows.push(null);
            }

            continue;
        }

        const colon = line.indexOf(':');

        if (colon < 1) {
            return fail(
                'write a signal as its name, a colon and its steps: “CLK: p.......”',
            );
        }

        const name = line.slice(0, colon).trim();
        const rest = line.slice(colon + 1).trim();

        if (name.toLowerCase() === 'title') {
            timing.title = rest;

            continue;
        }

        if (name.toLowerCase() === 'mark') {
            for (const each of rest.split(',')) {
                const read = each.trim().match(/^(\d+(?:\.\d+)?)\s*(.*)$/);

                if (!read) {
                    return fail(
                        'a mark is the step it stands at, then its name: “mark: 2 start, 10 stop”',
                    );
                }

                timing.marks.push({ at: Number(read[1]), label: read[2] });
            }

            continue;
        }

        const [wave, ...labels] = rest.split('|').map((part) => part.trim());
        const steps = wave.replace(/\s+/g, '');

        if (!steps) {
            return fail(
                `${name} has no steps: write some after the colon, “${name}: 0101”`,
            );
        }

        const runs = runsOf(steps, labels);

        if (typeof runs === 'string') {
            return fail(runs);
        }

        timing.rows.push({ name, runs, steps: steps.length });
        timing.steps = Math.max(timing.steps, steps.length);
    }

    // No gap left hanging under the last signal
    while (timing.rows.length && !timing.rows[timing.rows.length - 1]) {
        timing.rows.pop();
    }

    if (!timing.rows.length) {
        return {
            ok: false,
            error: 'Nothing to draw yet: write a signal as its name and its steps, “CLK: p.......”.',
        };
    }

    return { ok: true, timing };
};

// ------------------------------------------------------------------ Drawing

const ROW = 34;
const GAP = 14;
const TALL = 18;
const FONT = 12;

const escape = (text: string) =>
    text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** About how wide words are, set at this size: near enough to make room. */
const wide = (text: string, size = FONT) => text.length * size * 0.6;

export type TimingDrawn =
    | { ok: true; svg: string; width: number; height: number }
    | { ok: false; error: string };

/**
 * The diagram as an SVG. Drawn in the colour of the text round it unless
 * given an ink and a paper of its own, as a file of it is.
 */
export const renderTiming = (
    source: string,
    { ink, paper }: { ink?: string; paper?: string } = {},
): TimingDrawn => {
    const read = parseTiming(source);

    if (!read.ok) {
        return read;
    }

    const { timing } = read;
    const signals = timing.rows.filter((row): row is Signal => !!row);
    // Wide enough to read a value's name in, narrow enough to fit a page
    const step = Math.max(14, Math.min(40, Math.round(620 / timing.steps)));
    const names = Math.ceil(
        Math.max(40, ...signals.map((signal) => wide(signal.name) + 18)),
    );
    const top = (timing.title ? 28 : 6) + (timing.marks.length ? 16 : 0);
    const width = names + timing.steps * step + 12;
    const parts: string[] = [];
    const x = (at: number) => names + at * step;

    if (timing.title) {
        parts.push(
            `<text x="${names}" y="16" font-size="13" font-weight="600" fill="currentColor">${escape(timing.title)}</text>`,
        );
    }

    let y = top;
    const lanes: { from: number; to: number }[] = [];

    for (const row of timing.rows) {
        if (!row) {
            y += GAP;

            continue;
        }

        const high = y + (ROW - TALL) / 2;
        const low = high + TALL;
        const middle = (high + low) / 2;
        // Where a run starts and ends up and down, for the edge between two
        const levels = (run: Run): [number | null, number | null] => {
            switch (run.kind) {
                case 'low':
                    return [low, low];
                case 'high':
                    return [high, high];
                case 'pulse':
                    return [high, low];
                case 'pulse-low':
                    return [low, high];
                case 'floating':
                    return [middle, middle];
                default:
                    return [null, null];
            }
        };

        lanes.push({ from: y, to: y + ROW });
        parts.push(
            `<text x="${names - 10}" y="${middle + FONT * 0.35}" font-size="${FONT}" text-anchor="end" fill="currentColor">${escape(row.name)}</text>`,
        );

        let line = '';
        let before: number | null = null;

        for (const run of row.runs) {
            const from = x(run.from);
            const to = x(run.to);
            const [starts, ends] = levels(run);

            // The edge from where the last run left off to where this begins
            if (starts !== null) {
                line +=
                    before !== null && line
                        ? ` L${from},${starts}`
                        : ` M${from},${starts}`;
            }

            if (run.kind === 'pulse' || run.kind === 'pulse-low') {
                const first = run.kind === 'pulse' ? high : low;
                const second = run.kind === 'pulse' ? low : high;

                for (let tick = run.from; tick < run.to; tick++) {
                    const half = x(tick) + step / 2;

                    line += ` L${x(tick)},${first} L${half},${first} L${half},${second} L${x(tick + 1)},${second}`;
                }
            } else if (run.kind === 'floating') {
                parts.push(
                    `<path d="M${from},${middle} H${to}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="3 3"/>`,
                );
            } else if (starts !== null) {
                line += ` L${to},${ends}`;
            } else {
                // A value, or something unknown: a box with pinched ends
                const pinch = Math.min(4, step / 4);
                const box = `M${from},${middle} L${from + pinch},${high} L${to - pinch},${high} L${to},${middle} L${to - pinch},${low} L${from + pinch},${low} Z`;

                parts.push(
                    `<path d="${box}" fill="${run.kind === 'unknown' ? 'url(#timing-unknown)' : 'none'}" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>`,
                );

                if (run.label) {
                    // As much of the name as its box has room for
                    const room = Math.max(
                        1,
                        Math.floor((to - from - pinch * 2) / (11 * 0.6)),
                    );
                    const shown =
                        run.label.length > room
                            ? `${run.label.slice(0, Math.max(1, room - 1))}…`
                            : run.label;

                    parts.push(
                        `<text x="${(from + to) / 2}" y="${middle + 4}" font-size="11" text-anchor="middle" fill="currentColor">${escape(shown)}</text>`,
                    );
                }
            }

            // A line let go of, a value or an unknown breaks the trace
            before = run.kind === 'floating' ? null : ends;

            if (before === null && line) {
                parts.push(
                    `<path d="${line.trim()}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>`,
                );
                line = '';
            }
        }

        if (line) {
            parts.push(
                `<path d="${line.trim()}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>`,
            );
        }

        y += ROW;
    }

    const height = y + 6;

    // Faint lines down each step, behind the signals, to read across by
    const grid = Array.from({ length: timing.steps + 1 }, (_, at) => at)
        .map(
            (at) =>
                `<path d="M${x(at)},${top} V${y}" stroke="currentColor" stroke-opacity="0.1" stroke-width="1"/>`,
        )
        .join('');

    const marks = timing.marks
        .map(
            ({ at, label }) =>
                `<path d="M${x(at)},${top - 2} V${y}" stroke="currentColor" stroke-opacity="0.7" stroke-width="1" stroke-dasharray="4 3"/>` +
                (label
                    ? `<text x="${x(at)}" y="${top - 6}" font-size="10.5" text-anchor="middle" fill="currentColor">${escape(label)}</text>`
                    : ''),
        )
        .join('');

    const svg =
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" font-family="Arial, sans-serif"${ink ? ` color="${ink}"` : ''} data-rows="${lanes.length}">` +
        `<defs><pattern id="timing-unknown" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><path d="M0,0 V6" stroke="currentColor" stroke-opacity="0.45" stroke-width="1.5"/></pattern></defs>` +
        (paper
            ? `<rect width="${width}" height="${height}" fill="${paper}"/>`
            : '') +
        grid +
        parts.join('') +
        marks +
        '</svg>';

    return { ok: true, svg, width, height };
};
