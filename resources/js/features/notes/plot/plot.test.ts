import { describe, expect, it } from 'vitest';
import { plotExamples } from './examples';
import { compile, number } from './expression';
import { drawPlot, heightsAt, parsePlot, short, written } from './plot';

const worked = (text: string, scope = {}) => compile(text).value(scope);

describe('a formula', () => {
    it('is worked out in the order arithmetic is', () => {
        expect(worked('1 + 2 * 3')).toBe(7);
        expect(worked('(1 + 2) * 3')).toBe(9);
        expect(worked('-2 ^ 2')).toBe(-4);
        expect(worked('2 ^ -1')).toBe(0.5);
        expect(worked('2 ^ 3 ^ 2')).toBe(512);
        expect(worked('10 - 4 - 3')).toBe(3);
    });

    it('knows its names, pi, and the usual functions', () => {
        const formula = compile('A * sin(2 * pi * f * t)');

        expect(formula.names).toEqual(['A', 'f', 't']);
        expect(formula.value({ A: 3, f: 1, t: 0.25 })).toBeCloseTo(3);
        expect(worked('max(1, 4, 2) + sqrt(16) + abs(-1)')).toBe(9);
        expect(worked('exp(ln(5))')).toBeCloseTo(5);
    });

    it('reads 4.7k and 100n as an engineer writes them', () => {
        expect(number('4.7k')).toBe(4700);
        expect(number('100n')).toBeCloseTo(1e-7);
        expect(number('2 * 1.5M')).toBe(3e6);
        expect(number('1e3')).toBe(1000);
    });

    it('says what is wrong with one it cannot read', () => {
        expect(() => compile('2 x')).toThrow(/write \* to multiply/);
        expect(() => compile('sin(')).toThrow(/missing|closed/);
        expect(() => compile('foo(2)')).toThrow(/foo\( \) is not known/);
        expect(() => compile('2 +')).toThrow(/missing before the end/);
        expect(() => compile('3 $ 4')).toThrow(/"\$" is not something/);
        expect(() => compile('min()')).toThrow(/takes 1 to 8 values/);
    });

    it('is never run as code', () => {
        expect(() => compile('alert(1)')).toThrow(/not known/);
        expect(() => compile('constructor.constructor')).toThrow();
        expect(worked('constructor')).toBeNaN();
    });
});

describe('a graph', () => {
    const rc = [
        'title: Charging',
        'x: Time (ms)',
        'y: Voltage (V), 0 to 6',
        't = 0 to 50',
        'R = 10 from 1 to 47',
        'C = 1',
        'tau = R * C',
        'Vc(t) = 5 * (1 - exp(-t / tau))',
    ].join('\n');

    it('is read from its lines', () => {
        const read = parsePlot(rc);

        expect(read.ok).toBe(true);

        if (!read.ok) return;

        expect(read.plot).toMatchObject({
            title: 'Charging',
            xLabel: 'Time (ms)',
            yLabel: 'Voltage (V)',
            variable: 't',
            from: 0,
            to: 50,
            range: [0, 6],
        });
        expect(read.plot.parameters).toEqual([
            { name: 'R', value: 10, min: 1, max: 47, step: 0.2 },
        ]);
        expect(read.plot.curves.map((each) => each.name)).toEqual(['Vc']);
    });

    it('moves with a value that is slid', () => {
        const read = parsePlot(rc);

        if (!read.ok) throw new Error(read.error);

        // One time constant in, a capacitor is 63% of the way
        expect(heightsAt(read.plot, written(read.plot), 10)[0]).toBeCloseTo(
            3.16,
            2,
        );
        expect(heightsAt(read.plot, { R: 20 }, 20)[0]).toBeCloseTo(3.16, 2);
    });

    it('is drawn with its title, its names and a curve', () => {
        const read = parsePlot(rc);

        if (!read.ok) throw new Error(read.error);

        const drawn = drawPlot(read.plot);

        expect(drawn.svg).toContain('Charging');
        expect(drawn.svg).toContain('Voltage (V)');
        expect(drawn.svg).toMatch(/<path d="M[\d. ]+L/);
        expect(drawn.geometry.y).toEqual([0, 6]);
        expect(drawn.names).toEqual(['Vc']);
        // One curve needs no legend; two get one
        expect(drawn.svg.match(/stroke-width="3"/g)).toBeNull();
    });

    it('keeps the height it has shown while a value is slid', () => {
        const read = parsePlot('x = 0 to 10\nk = 1 from 0 to 4\ny(x) = k * x');

        if (!read.ok) throw new Error(read.error);

        const first = drawPlot(read.plot);
        const smaller = drawPlot(read.plot, {
            values: { k: 0.5 },
            hold: first.geometry.y,
        });
        const bigger = drawPlot(read.plot, {
            values: { k: 4 },
            hold: first.geometry.y,
        });

        expect(first.geometry.y).toEqual([0, 10]);
        expect(smaller.geometry.y).toEqual([0, 10]);
        expect(bigger.geometry.y).toEqual([0, 40]);
    });

    it('draws the columns of a table as points, in fixed colours', () => {
        const read = parsePlot(
            'table: Readings\nx: Hour\ny: Voltage, Current\nfit(h) = 4 - h / 10',
        );

        if (!read.ok) throw new Error(read.error);

        expect(read.plot).toMatchObject({
            table: 'Readings',
            across: 'Hour',
            columns: ['Voltage', 'Current'],
        });

        const drawn = drawPlot(read.plot, {
            points: [
                {
                    name: 'Voltage',
                    points: [
                        [0, 4.1],
                        [1, 3.9],
                        [2, 3.8],
                    ],
                },
                {
                    name: 'Current',
                    points: [
                        [0, 0.5],
                        [1, 0.6],
                        [2, 0.4],
                    ],
                },
            ],
        });

        expect(drawn.names).toEqual(['Voltage', 'Current', 'fit']);
        expect(drawn.geometry.x).toEqual([0, 2]);
        expect(drawn.svg.match(/<circle cx/g)).toHaveLength(6);
        expect(drawn.svg).toContain('var(--plot-3, #1baf7a)');
    });

    it('leaves a gap where a curve has no value', () => {
        const read = parsePlot('x = -1 to 1\ny(x) = sqrt(x)');

        if (!read.ok) throw new Error(read.error);

        // Nothing is drawn left of zero: the path starts half-way along
        const [, start] = /<path d="M([\d.]+) /.exec(drawPlot(read.plot).svg)!;

        expect(Number(start)).toBeGreaterThan(300);
    });

    it('says what is wrong, and on which line', () => {
        const said = (text: string) => {
            const read = parsePlot(text);

            return read.ok ? '' : read.error;
        };

        expect(said('y(x) = 2 x')).toMatch(/^Line 1: .*write \* to multiply/);
        expect(said('x = 0 to 5')).toMatch(/no curve yet/);
        expect(said('y(x) = k * x')).toMatch(/uses k, which is not given/);
        expect(said('y(x) = x\nz(t) = t')).toMatch(/x, or t\?/);
        expect(said('k = 0 to 5\ny(x) = k * x')).toMatch(/k = 1 from 0 to 5/);
        expect(said('hello')).toMatch(/is not a line of a graph/);
        expect(said('table: Readings')).toMatch(/x: Time/);
        expect(said('R = 1 from 5 to 2\ny(x) = R')).toMatch(/nowhere/);
    });

    it('reads every example it offers', () => {
        for (const example of plotExamples) {
            const read = parsePlot(example.source);

            expect(read.ok, example.label).toBe(true);

            if (read.ok) {
                expect(drawPlot(read.plot).svg).toContain('<path');
            }
        }
    });

    it('writes numbers short', () => {
        expect(short(2.5)).toBe('2.5');
        expect(short(12000)).toBe('12K');
        expect(short(0.00012)).toBe('1.2e-4');
        expect(short(NaN)).toBe('–');
    });
});
