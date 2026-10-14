import { describe, expect, it } from 'vitest';
import { parseCircuit } from './parse';
import { renderCircuit } from './render';
import { quantity, solveCircuit, written } from './solve';

/** A circuit worked out, with each net by name and each part by name. */
const worked = (source: string) => {
    const parsed = parseCircuit(source);

    if (!parsed.ok) {
        throw new Error(parsed.error);
    }

    const solved = solveCircuit(parsed.circuit);

    if (!solved.ok) {
        throw new Error(solved.error);
    }

    return {
        ...solved,
        at: (net: string) =>
            [...solved.nets].find(
                ([each]) => each.name.toLowerCase() === net.toLowerCase(),
            )?.[1],
        of: (part: string) => solved.parts.get(part)!,
    };
};

const failure = (source: string) => {
    const parsed = parseCircuit(source);
    const solved = parsed.ok ? solveCircuit(parsed.circuit) : parsed;

    return solved.ok ? '' : solved.error;
};

describe('a circuit worked out', () => {
    it('reads a value as written', () => {
        expect(quantity('10 kΩ')).toBe(10000);
        expect(quantity('4.7 kΩ')).toBe(4700);
        expect(quantity('100 nF')).toBeCloseTo(1e-7);
        expect(quantity('9 V')).toBe(9);
        expect(quantity('1 mA')).toBe(0.001);
        expect(quantity('red')).toBeNull();
    });

    it('writes an answer the way an engineer would', () => {
        expect(written(0.003, 'A')).toBe('3 mA');
        expect(written(2.5, 'V')).toBe('2.5 V');
        expect(written(0.0136363, 'A')).toBe('13.6 mA');
        expect(written(4700, 'Ω')).toBe('4.7 kΩ');
        expect(written(0, 'A')).toBe('0 A');
        expect(written(-0.027, 'W')).toBe('-27 mW');
    });

    it('halves a voltage across two equal resistors', () => {
        const divider = worked(
            'V1: 5V\nR1: 10k\nR2: 10k\nT1: terminal\nV1 -- R1 -- out\nout -- R2 -- gnd\nout -- T1\nV1.- -- gnd',
        );

        expect(divider.at('out')).toBeCloseTo(2.5);
        expect(divider.of('R1').current).toBeCloseTo(0.00025);
        expect(divider.notes).toEqual([]);
    });

    it('shares a voltage between resistors in series by their size', () => {
        const series = worked('V1: 9V\nR1: 1k\nR2: 2k\nV1 -- R1 -- R2 -- V1');

        expect(series.of('R1').current).toBeCloseTo(0.003);
        expect(series.of('R1').voltage).toBeCloseTo(3);
        expect(series.of('R2').voltage).toBeCloseTo(6);
        // The source gives out what the resistors turn to heat
        expect(series.of('V1').power).toBeCloseTo(-0.027);
        expect(series.of('R1').power + series.of('R2').power).toBeCloseTo(
            0.027,
        );
        // With no ground, it is measured from the source's negative end
        expect(series.ground.pins.map((pin) => pin.part).sort()).toEqual([
            'R2',
            'V1',
        ]);
        expect(series.notes[0]).toMatch(/negative end of V1/);
    });

    it('splits a current between resistors in parallel', () => {
        const parallel = worked(
            'V1: 9V\nR1: 1k\nR2: 2k\nV1.+ -- R1 -- gnd\nV1.+ -- R2 -- gnd\nV1.- -- gnd',
        );

        expect(parallel.of('R1').current).toBeCloseTo(0.009);
        expect(parallel.of('R2').current).toBeCloseTo(0.0045);
        expect(parallel.of('V1').current).toBeCloseTo(0.0135);
    });

    it('takes an LED as a fixed drop once it is lit', () => {
        const lit = worked(
            'V1: 9V\nR1: 330\nD1: led red\nV1 -- R1 -- D1 -- V1',
        );

        expect(lit.of('D1').state).toBe('on');
        expect(lit.of('D1').voltage).toBeCloseTo(1.8);
        expect(lit.of('R1').current).toBeCloseTo((9 - 1.8) / 330);

        // The wrong way round, or with too little to light it, it passes nothing
        const backwards = worked(
            'V1: 9V\nR1: 330\nD1: led red\nV1 -- R1 -- D1.k\nD1.a -- V1.-',
        );

        expect(backwards.of('D1').state).toBe('off');
        expect(backwards.of('R1').current).toBeCloseTo(0);

        const starved = worked(
            'V1: 1V\nR1: 330\nD1: led blue\nV1 -- R1 -- D1 -- V1',
        );

        expect(starved.of('D1').state).toBe('off');
    });

    it('holds a zener at its voltage when it is fed backwards', () => {
        const held = worked(
            'V1: 12V\nR1: 1k\nD1: zener 5.1V\nV1 -- R1 -- out\nout -- D1.k\nD1.a -- gnd\nV1.- -- gnd',
        );

        expect(held.at('out')).toBeCloseTo(5.1);
        expect(held.of('D1').state).toBe('holding');
        expect(held.of('R1').current).toBeCloseTo((12 - 5.1) / 1000);
    });

    it('takes a rail at the voltage in its name', () => {
        const fed = worked(
            'R1: 1k\nR2: 1k\n3V3 -- R1 -- mid\nmid -- R2 -- gnd',
        );

        expect(fed.at('3V3')).toBeCloseTo(3.3);
        expect(fed.at('mid')).toBeCloseTo(1.65);
    });

    it('leaves a switch open as it is drawn, and closes it when told', () => {
        const open = worked(
            'V1: 9V\nSW1: switch\nR1: 1k\nV1 -- SW1 -- R1 -- V1',
        );
        const closed = worked(
            'V1: 9V\nSW1: switch closed\nR1: 1k\nV1 -- SW1 -- R1 -- V1',
        );

        expect(open.of('R1').current).toBeCloseTo(0);
        expect(open.notes.join(' ')).toMatch(/SW1 is taken as open/);
        expect(closed.of('R1').current).toBeCloseTo(0.009);
    });

    it('takes a charged capacitor as passing nothing', () => {
        const charged = worked(
            'V1: 5V\nR1: 1k\nC1: 100n\nV1 -- R1 -- out\nout -- C1 -- gnd\nV1.- -- gnd',
        );

        expect(charged.at('out')).toBeCloseTo(5);
        expect(charged.notes.join(' ')).toMatch(/C1 is taken as charged/);
    });

    it('is asked for its values in the text, shown or kept back', () => {
        const asks = (line: string) => {
            const read = parseCircuit(
                `${line}\nV1: 5V\nR1: 1k\nV1 -- R1 -- V1`,
            );

            return read.ok
                ? [read.circuit.values, read.circuit.valuesHidden]
                : read.error;
        };

        expect(asks('title Plain')).toEqual([false, false]);
        expect(asks('values')).toEqual([true, false]);
        expect(asks('values shown')).toEqual([true, false]);
        // Worked out when the reader asks, for a guess to be made first
        expect(asks('Values Hidden')).toEqual([false, true]);

        // What is drawn follows the reader over the text, either way
        const drawn = (text: string, values?: boolean) => {
            const made = renderCircuit(text, { values });

            return made.ok && made.svg.includes('5 mA');
        };
        const kept = 'values hidden\nV1: 5V\nR1: 1k\nV1 -- R1 -- V1';

        expect(drawn(kept)).toBe(false);
        expect(drawn(kept, true)).toBe(true);
        expect(drawn(kept.replace(' hidden', ''))).toBe(true);
        expect(drawn(kept.replace(' hidden', ''), false)).toBe(false);
    });

    it('says when a part is asked for more than it is good for', () => {
        const warned = (text: string) => {
            const read = parseCircuit(text);
            const answer = read.ok ? solveCircuit(read.circuit) : read;

            return answer.ok ? answer.warnings : [`(${answer.error})`];
        };

        // 9 V across 100 Ω is 810 mW, in a quarter-watt resistor
        expect(warned('V1: 9V\nR1: 100\nV1 -- R1 -- V1')).toEqual([
            'R1 turns 810 mW to heat: more than the 250 mW an ordinary resistor is good for.',
        ]);
        // ...which a bigger one takes, when its value says it is bigger
        expect(warned('V1: 9V\nR1: 100 1W\nV1 -- R1 -- V1')).toEqual([]);
        expect(warned('V1: 9V\nR1: 100 1/2W\nV1 -- R1 -- V1')).toEqual([
            'R1 turns 810 mW to heat: more than the 500 mW it is rated for.',
        ]);

        // (9 V - 1.8 V) through 100 Ω is 72 mA, in an LED good for 20
        expect(
            warned('V1: 9V\nR1: 100 1W\nD1: led red\nV1 -- R1 -- D1 -- V1'),
        ).toEqual([
            'D1 carries 72 mA: more than the 20 mA an ordinary LED is good for.',
        ]);
        expect(
            warned('V1: 9V\nR1: 470\nD1: led red\nV1 -- R1 -- D1 -- V1'),
        ).toEqual([]);

        // With no resistor at all there is nothing to limit it, and it is said
        expect(warned('V1: 9V\nD1: led red\nV1 -- D1 -- V1')[0]).toMatch(
            /LED may be straight across a source with no resistor in line/,
        );

        // On the drawing, the part's own line is marked and the reason is under it
        const drawn = renderCircuit('values\nV1: 9V\nR1: 100\nV1 -- R1 -- V1');

        expect(drawn.ok && drawn.svg).toContain('⚠ 90 mA · 9 V');
        expect(drawn.ok && drawn.svg).toContain('var(--destructive, #dc2626)');
    });

    it('says what it cannot work out, and why', () => {
        expect(failure('V1: 9V\nQ1: npn\nR1: 1k\nV1 -- R1 -- Q1.c')).toMatch(
            /Q1 \(Transistor, NPN\) is not worked out yet/,
        );
        expect(failure('V1: 9V\nR1: resistor\nV1 -- R1 -- V1')).toMatch(
            /R1 needs a value/,
        );
        expect(failure('R1: 1k\nvcc -- R1 -- gnd')).toMatch(
            /the rail "vcc" does not say its voltage/,
        );
        expect(failure('V1: ac 230V\nR1: 1k\nV1 -- R1 -- V1')).toMatch(
            /only DC/,
        );
        expect(failure('V1: 9V\nV2: 5V\nV1.+ -- V2.+\nV1.- -- V2.-')).toMatch(
            /no one answer/,
        );
    });
});
