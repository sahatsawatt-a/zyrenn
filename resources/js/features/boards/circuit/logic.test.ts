import { describe, expect, it } from 'vitest';
import { isConnector, makeItem } from '@/features/boards/composables/items';
import type { Item } from '@/features/boards/composables/items';
import { layOut } from './layout';
import { newMemory, simulate, truthTable } from './logic';
import { parseCircuit } from './parse';

/** A circuit from its text, as the items a board would hold. */
const drawn = (source: string): Item[] => {
    const parsed = parseCircuit(source);

    if (!parsed.ok) {
        throw new Error(parsed.error);
    }

    return layOut(parsed.circuit);
};

const named = (items: Item[], name: string) =>
    items.find((item) => item.text.split('\n')[0] === name)!;

/** The circuit with these inputs switched on, and the rest off. */
const set = (items: Item[], ...on: string[]) =>
    items.map((item) =>
        item.symbol === 'input' || item.symbol === 'clock'
            ? { ...item, on: on.includes(item.text) }
            : item,
    );

const GATE = (kind: string) =>
    `A: input\nB: input\nU1: ${kind}\nY: output\nA -- U1.a\nB -- U1.b\nU1.y -- Y`;

describe('logic on a board', () => {
    it.each([
        ['and', '0001'],
        ['or', '0111'],
        ['nand', '1110'],
        ['nor', '1000'],
        ['xor', '0110'],
        ['xnor', '1001'],
    ])('works out what an %s gate puts out', (kind, column) => {
        const table = truthTable(drawn(GATE(kind)))!;

        expect(table.inputs).toEqual(['A', 'B']);
        expect(table.outputs).toEqual(['Y']);
        // A is the high bit: 00, 01, 10, 11
        expect(table.rows.map((row) => row.inputs.join(''))).toEqual([
            '00',
            '01',
            '10',
            '11',
        ]);
        expect(table.rows.map((row) => row.outputs[0]).join('')).toBe(column);
    });

    it('runs a signal through gates one after another', () => {
        const items = drawn(
            'A: input\nU1: not\nU2: buffer\nY: output\nA -- U1 -- U2 -- Y',
        );

        expect(
            truthTable(items)!
                .rows.map((row) => row.outputs[0])
                .join(''),
        ).toBe('10');
    });

    it('adds two bits', () => {
        const table = truthTable(
            drawn(
                'A: input\nB: input\nU1: xor\nU2: and\nS: output\nC: output\nA -- U1.a\nB -- U1.b\nA -- U2.a\nB -- U2.b\nU1.y -- S\nU2.y -- C',
            ),
        )!;

        // Carry then sum, as the outputs sort: 0+0, 0+1, 1+0, 1+1
        expect(table.outputs).toEqual(['C', 'S']);
        expect(table.rows.map((row) => row.outputs.join(''))).toEqual([
            '00',
            '01',
            '01',
            '10',
        ]);
    });

    it('says which wires, switches and lamps are high', () => {
        const items = set(drawn(GATE('and')), 'A', 'B');
        const { levels } = simulate(items);

        expect(levels.get(named(items, 'A').id)).toBe(1);
        expect(levels.get(named(items, 'Y').id)).toBe(1);
        expect(
            items
                .filter(isConnector)
                .every((wire) => levels.get(wire.id) === 1),
        ).toBe(true);

        const off = simulate(set(items, 'A'));

        expect(off.levels.get(named(items, 'Y').id)).toBe(0);
    });

    it('leaves a wire that is no part of the logic alone', () => {
        const r1 = {
            ...makeItem('symbol', 0, 0, { symbol: 'resistor' }),
            text: 'R1',
        };
        const r2 = {
            ...makeItem('symbol', 200, 0, { symbol: 'resistor' }),
            text: 'R2',
        };
        const wire: Item = {
            ...makeItem('arrow', 0, 0),
            from: { item: r1.id, pin: '2', side: null, x: 0, y: 0 },
            to: { item: r2.id, pin: '1', side: null, x: 0, y: 0 },
        };
        const { levels } = simulate([
            ...set(drawn(GATE('or')), 'A'),
            r1,
            r2,
            wire,
        ]);

        expect(levels.has(wire.id)).toBe(false);
    });

    it('takes a rail as high and a ground as low', () => {
        const items = drawn(
            'U1: and\nU2: or\nY: output\nZ: output\nvcc -- U1.a\nvcc -- U1.b\nU1.y -- Y\ngnd -- U2.a\ngnd -- U2.b\nU2.y -- Z',
        );
        const { levels } = simulate(items);

        expect(levels.get(named(items, 'Y').id)).toBe(1);
        expect(levels.get(named(items, 'Z').id)).toBe(0);
    });

    it('does not guess at an input nothing drives', () => {
        const items = drawn(
            'A: input\nU1: and\nY: output\nA -- U1.a\nU1.y -- Y',
        );

        // Low on one input settles an AND; high leaves it waiting on the other
        expect(simulate(items).levels.get(named(items, 'Y').id)).toBe(0);
        expect(
            simulate(set(items, 'A')).levels.get(named(items, 'Y').id),
        ).toBeNull();
    });

    it('holds what a latch of two gates was last set to', () => {
        const items = drawn(
            'S: input\nR: input\nU1: nor\nU2: nor\nQ: output\nQN: output\nR -- U1.a\nU2.y -- U1.b\nU1.y -- U2.a\nS -- U2.b\nU1.y -- Q\nU2.y -- QN',
        );
        const memory = newMemory();
        const q = (...on: string[]) =>
            simulate(set(items, ...on), { memory }).levels.get(
                named(items, 'Q').id,
            );

        expect(q('S')).toBe(1);
        // Let go of, it stays set...
        expect(q()).toBe(1);
        expect(q('R')).toBe(0);
        // ...and stays cleared
        expect(q()).toBe(0);
    });

    it('clocks a flip-flop on the rising edge only', () => {
        const items = drawn(
            'D: input\nCLK: clock\nFF1: dff\nQ: output\nD -- FF1.D\nCLK -- FF1.CLK\nFF1.Q -- Q',
        );
        const memory = newMemory();
        const q = (clock: boolean, ...on: string[]) =>
            simulate(set(items, 'CLK', ...on), { clock, memory }).levels.get(
                named(items, 'Q').id,
            );

        expect(q(false, 'D')).toBe(0);
        // The edge takes D in
        expect(q(true, 'D')).toBe(1);
        // D changing while the clock stays high, or falls, changes nothing
        expect(q(true)).toBe(1);
        expect(q(false)).toBe(1);
        expect(q(true)).toBe(0);

        // A clock that is switched off never rises
        const stopped = newMemory();
        const still = (clock: boolean) =>
            simulate(set(items, 'D'), { clock, memory: stopped }).levels.get(
                named(items, 'Q').id,
            );

        expect([still(false), still(true), still(false), still(true)]).toEqual([
            0, 0, 0, 0,
        ]);
    });

    it('takes three inputs on a gate that has three', () => {
        const table = truthTable(
            drawn(
                'A: input\nB: input\nC: input\nU1: and3\nU2: nor3\nY: output\nZ: output\nA -- U1.a\nB -- U1.b\nC -- U1.c\nA -- U2.a\nB -- U2.b\nC -- U2.c\nU1.y -- Y\nU2.y -- Z',
            ),
        )!;

        // Y is all three; Z is none of them
        expect(table.rows.map((row) => row.outputs.join(''))).toEqual([
            '01',
            '00',
            '00',
            '00',
            '00',
            '00',
            '00',
            '10',
        ]);
    });

    it('holds a push-button high only while it is pressed', () => {
        const items = drawn('A: press\nY: output\nA -- Y');
        const lamp = (pressed: boolean) =>
            simulate(items, {
                inputs: new Map(pressed ? [[named(items, 'A').id, true]] : []),
            }).levels.get(named(items, 'Y').id);

        expect([lamp(false), lamp(true), lamp(false)]).toEqual([0, 1, 0]);
        // A button counts as an input: held down, or not
        expect(truthTable(items)!.rows.map((row) => row.outputs[0])).toEqual([
            0, 1,
        ]);
    });

    it('lights the segments of a display that are fed', () => {
        const items = set(
            drawn(
                'A: input\nB: input\nDS1: seg7\nA -- DS1.b\nA -- DS1.c\nB -- DS1.g',
            ),
            'A',
        );

        // b and c: a one
        expect(simulate(items).lit.get(named(items, 'DS1').id)).toEqual([
            'b',
            'c',
        ]);
    });

    it('shows the digit a display is given as a number', () => {
        const items = drawn(
            'A: input\nB: input\nC: input\nD: input\nDS1: bcd7\nA -- DS1.1\nB -- DS1.2\nC -- DS1.4\nD -- DS1.8',
        );
        const shows = (...on: string[]) =>
            simulate(set(items, ...on))
                .lit.get(named(items, 'DS1').id)!
                .join('');

        expect(shows()).toBe('abcdef');
        expect(shows('A')).toBe('bc');
        expect(shows('A', 'B', 'C')).toBe('abc');
        expect(shows('A', 'D')).toBe('abcdfg');

        // With a bit left undriven there is nothing sure to show
        const loose = drawn('A: input\nDS1: bcd7\nA -- DS1.1');

        expect(simulate(loose).lit.get(named(loose, 'DS1').id)).toEqual([]);
    });

    it('has no truth table for what depends on what came before', () => {
        expect(
            truthTable(
                drawn(
                    'D: input\nCLK: clock\nFF1: dff\nQ: output\nD -- FF1.D\nCLK -- FF1.CLK\nFF1.Q -- Q',
                ),
            ),
        ).toBeNull();
        expect(truthTable(drawn('R1: 1k\nR2: 1k\nR1 -- R2'))).toBeNull();
    });
});
