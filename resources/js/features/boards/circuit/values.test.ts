import { describe, expect, it } from 'vitest';
import type { Item } from '@/features/boards/model/items';
import { layOut } from './layout';
import { parseCircuit } from './parse';
import { besideRun, boardValues } from './values';

/** A circuit as it would be drawn on a board, moved along so several fit. */
const drawn = (text: string, shift = 0): Item[] => {
    const read = parseCircuit(text);

    if (!read.ok) {
        throw new Error(read.error);
    }

    return layOut(read.circuit).map((item) => ({
        ...item,
        id: `${item.id}-${shift}`,
        x: item.kind === 'symbol' ? item.x + shift : item.x,
        from: item.from && {
            ...item.from,
            item: item.from.item && `${item.from.item}-${shift}`,
            x: item.from.x + shift,
        },
        to: item.to && {
            ...item.to,
            item: item.to.item && `${item.to.item}-${shift}`,
            x: item.to.x + shift,
        },
    }));
};

const called = (items: Item[], name: string) =>
    items.find((item) => item.text.split('\n')[0] === name)!;

describe('a board worked out', () => {
    it('writes under each part what flows through it and is across it', () => {
        const items = drawn(
            'V1: battery 9V\nR1: 1k\nR2: 2k\nV1 -- R1 -- R2 -- V1',
        );
        const values = boardValues(items);

        expect(values.notes.get(called(items, 'R1').id)).toEqual({
            text: '3 mA · 3 V',
            warned: false,
        });
        expect(values.notes.get(called(items, 'R2').id)?.text).toBe(
            '3 mA · 6 V',
        );
        expect(values.notes.get(called(items, 'V1').id)?.text).toBe('3 mA');
        expect(values.rows.map((row) => row.name)).toEqual(['R1', 'R2', 'V1']);
        expect(values.problems).toEqual([]);

        // A voltage beside one wire of each net but the one measured from
        expect([...values.wires.values()].sort()).toEqual(['6 V', '9 V']);
        expect(
            [...values.wires.keys()].every((id) =>
                items.some((item) => item.id === id && item.kind === 'arrow'),
            ),
        ).toBe(true);
    });

    it('marks a part asked for more than it is good for', () => {
        const items = drawn('V1: battery 9V\nR1: 100\nV1 -- R1 -- V1');
        const values = boardValues(items);

        expect(values.notes.get(called(items, 'R1').id)).toEqual({
            text: '⚠ 90 mA · 9 V',
            warned: true,
        });
        expect(values.warnings).toEqual([
            'R1 turns 810 mW to heat: more than the 250 mW an ordinary resistor is good for.',
        ]);
        expect(values.rows.find((row) => row.name === 'R1')?.warning).toMatch(
            /810 mW/,
        );
    });

    it('works out each circuit on its own, whatever else is on the board', () => {
        const items = [
            ...drawn('V1: battery 9V\nR1: 1k\nV1 -- R1 -- V1'),
            // Logic is not this kind of circuit, and is no fault
            ...drawn('A: input\nG1: not\nL1: output\nA -- G1 -- L1', 1000),
            // A circuit that cannot be worked out says so, and stops nothing
            ...drawn(
                'V2: battery 5V\nQ1: npn\nR2: 1k\nV2.+ -- R2 -- Q1.c\nQ1.e -- V2.-',
                2000,
            ),
            // A box and an arrow are not a circuit at all
            {
                ...called(drawn('R9: 1k'), 'R9'),
                id: 'box',
                kind: 'rect' as const,
            },
        ];
        const values = boardValues(items);

        expect(values.notes.get(called(items, 'R1').id)?.text).toBe(
            '9 mA · 9 V',
        );
        expect(values.rows.map((row) => row.name)).toEqual(['R1', 'V1']);
        expect(values.problems).toHaveLength(1);
        expect(values.problems[0]).toMatch(/Q1 .* is not worked out yet/);
    });

    it('has nothing to say of a board with nothing to drive it', () => {
        const values = boardValues(drawn('R1: 1k\nR2: 2k\nR1 -- R2'));

        expect(values.rows).toEqual([]);
        expect(values.problems).toEqual([]);
    });

    it('writes a voltage beside the longest straight run of a wire', () => {
        // Along a level run: under its middle
        expect(besideRun([0, 0, 0, 20, 100, 20])).toEqual({
            x: 50,
            y: 33,
            level: true,
        });
        // Down an upright one: to its right
        expect(besideRun([0, 0, 0, 80, 20, 80])).toEqual({
            x: 6,
            y: 44,
            level: false,
        });
        expect(besideRun([])).toBeNull();
    });
});
