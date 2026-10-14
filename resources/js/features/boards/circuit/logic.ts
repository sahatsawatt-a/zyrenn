// A logic circuit run: every net high or low, worked out from its inputs
// through its gates. The drawing is the circuit -- the same pins and wires
// the netlist is read from -- so what is drawn is what runs.
import type { Item } from '@/features/boards/composables/items';
import { wiring } from './netlist';
import { symbolOf, wordsOf } from './symbols';

/** High, low, or not driven by anything. */
export type Level = 0 | 1 | null;

/**
 * What a run remembers from one moment to the next: what each output last
 * was, so a latch built of gates holds what it was set to, and each
 * flip-flop's bit with the clock it last saw.
 */
export type Memory = {
    last: Map<string, Level>;
    flops: Map<string, { q: 0 | 1; clock: Level }>;
};

export const newMemory = (): Memory => ({ last: new Map(), flops: new Map() });

export type Run = {
    // The beat every running clock on the board follows: high or low just now
    clock?: boolean;
    // Inputs set otherwise than the drawing has them, by item id
    inputs?: Map<string, boolean>;
    memory?: Memory;
};

export type Signals = {
    // What to show on each item: the level of a wire's net, of an input or
    // a clock itself, and of whatever an output is fed
    levels: Map<string, Level>;
    // For a display: the segments that are lit, by the pin that lights each
    lit: Map<string, string[]>;
    read: (item: Pick<Item, 'id'>, pin: string) => Level;
};

/** The segments that make each digit, 0 to F, on a 7-segment display. */
const DIGITS = [
    'abcdef',
    'bc',
    'abdeg',
    'abcdg',
    'bcfg',
    'acdfg',
    'acdefg',
    'abc',
    'abcdefg',
    'abcdfg',
    'abcefg',
    'cdefg',
    'adef',
    'bcdeg',
    'adefg',
    'aefg',
];

const not = (level: Level): Level => (level === null ? null : level ? 0 : 1);

/** What a gate puts out for what comes in; nothing sure from an unknown. */
const GATES: Record<string, (inputs: Level[]) => Level> = {
    and: (inputs) =>
        inputs.includes(0) ? 0 : inputs.includes(null) ? null : 1,
    or: (inputs) => (inputs.includes(1) ? 1 : inputs.includes(null) ? null : 0),
    xor: (inputs) =>
        inputs.includes(null)
            ? null
            : inputs.filter((level) => level === 1).length % 2
              ? 1
              : 0,
    buffer: ([input]) => input,
    not: ([input]) => not(input),
    nand: (inputs) => not(GATES.and(inputs)),
    nor: (inputs) => not(GATES.or(inputs)),
    xnor: (inputs) => not(GATES.xor(inputs)),
};

/** A part that answers a click: an input flips, a clock starts or stops. */
export const isOperable = (item: Pick<Item, 'symbol'>): boolean => {
    const logic = symbolOf(item.symbol)?.logic;

    return logic === 'input' || logic === 'clock';
};

/** A part that is on only while it is held down: a push-button. */
export const isPressable = (item: Pick<Item, 'symbol'>): boolean =>
    symbolOf(item.symbol)?.logic === 'button';

/** Whether anything on the board works something out. */
export const hasLogic = (items: Item[]): boolean =>
    items.some((item) => !!symbolOf(item.symbol)?.logic);

/** Runs the logic drawn with these items for one moment. */
export const simulate = (items: Item[], run: Run = {}): Signals => {
    const { symbols, find, node, nodes, kinds, wires } = wiring(items);
    const parts = symbols.filter((item) => symbolOf(item.symbol)?.logic);
    const memory = run.memory ?? newMemory();

    // A rail is high and a ground low, whatever else is on them
    const held = new Map<string, Level>();

    for (const each of nodes) {
        const kind = kinds.get(each);

        if (kind === 'power' || kind === 'ground') {
            held.set(find(each), kind === 'power' ? 1 : 0);
        }
    }

    let nets = new Map(held);

    // Before anything is worked out, each output is what it last was
    for (const [pin, level] of memory.last) {
        if (!nets.has(find(pin))) {
            nets.set(find(pin), level);
        }
    }

    const read = (item: Pick<Item, 'id'>, pin: string): Level =>
        nets.get(find(node(item, pin))) ?? null;

    /** What a part drives its output pins to, as things stand. */
    const driven = (item: Item): [string, Level][] => {
        const symbol = symbolOf(item.symbol)!;

        switch (symbol.logic) {
            case 'input':
                return [['y', (run.inputs?.get(item.id) ?? item.on) ? 1 : 0]];
            // High only while it is held down: nothing of it is kept
            case 'button':
                return [['y', run.inputs?.get(item.id) ? 1 : 0]];
            case 'seg7':
            case 'bcd7':
                return [];
            // A clock ticks only while it is switched on
            case 'clock':
                return [['y', item.on && run.clock ? 1 : 0]];
            case 'output':
                return [];
            case 'dff': {
                const q = memory.flops.get(item.id)?.q ?? 0;

                return [
                    ['Q', q],
                    ['QN', q ? 0 : 1],
                ];
            }
            default: {
                const gate = GATES[symbol.logic ?? ''];
                const inputs = symbol.pins
                    .filter((pin) => pin.name !== 'y')
                    .map((pin) => read(item, pin.name));

                return gate ? [['y', gate(inputs)]] : [];
            }
        }
    };

    /** Lets the gates settle: round and round until nothing changes. */
    const settle = () => {
        for (let round = 0; round < 64; round++) {
            const next = new Map(held);
            const outputs: [string, Level][] = [];

            for (const item of parts) {
                for (const [pin, level] of driven(item)) {
                    const net = find(node(item, pin));

                    outputs.push([node(item, pin), level]);

                    // Two outputs on one net: high wins, as a wired OR would
                    if (level !== null && next.get(net) !== 1) {
                        next.set(net, level);
                    }
                }
            }

            const same =
                next.size === nets.size &&
                [...next].every(([net, level]) => nets.get(net) === level);

            nets = next;

            if (same) {
                memory.last = new Map(outputs);

                return;
            }
        }
    };

    settle();

    // A flip-flop takes in what is on D as its clock goes from low to high
    let clocked = false;

    for (const item of parts) {
        if (symbolOf(item.symbol)?.logic !== 'dff') {
            continue;
        }

        const clock = read(item, 'CLK');
        const was = memory.flops.get(item.id) ?? { q: 0 as const, clock };
        const q =
            was.clock === 0 && clock === 1 ? (read(item, 'D') ? 1 : 0) : was.q;

        clocked ||= q !== was.q;
        memory.flops.set(item.id, { q, clock });
    }

    if (clocked) {
        settle();
    }

    // Only wires that are part of the logic are shown live: a wire between
    // two resistors on the same board is not high or low
    const logical = new Set(
        parts.flatMap((item) =>
            symbolOf(item.symbol)!.pins.map((pin) =>
                find(node(item, pin.name)),
            ),
        ),
    );
    const levels = new Map<string, Level>();

    for (const [wire, pin] of wires) {
        if (logical.has(find(pin))) {
            levels.set(wire, nets.get(find(pin)) ?? null);
        }
    }

    for (const item of parts) {
        const symbol = symbolOf(item.symbol)!;

        if (symbol.lit) {
            levels.set(item.id, read(item, symbol.pins[0].name));
        }
    }

    // A display shows the pins that are high, or the digit they count up to
    const lit = new Map<string, string[]>();

    for (const item of parts) {
        const symbol = symbolOf(item.symbol)!;

        if (symbol.logic === 'seg7') {
            lit.set(
                item.id,
                symbol.pins
                    .filter((pin) => read(item, pin.name) === 1)
                    .map((pin) => pin.name),
            );
        }

        if (symbol.logic === 'bcd7') {
            const bits = symbol.pins.map((pin) => read(item, pin.name));

            // Nothing sure to show until every bit is driven
            lit.set(
                item.id,
                bits.includes(null)
                    ? []
                    : DIGITS[
                          bits.reduce<number>(
                              (sum, bit, place) => sum + (bit ? 2 ** place : 0),
                              0,
                          )
                      ].split(''),
            );
        }
    }

    return { levels, lit, read };
};

export type TruthTable = {
    inputs: string[];
    outputs: string[];
    rows: { inputs: (0 | 1)[]; outputs: Level[] }[];
};

/**
 * What a circuit puts out for everything that can be put in: a row for each
 * way its inputs can be set. Nothing for a circuit with a clock or a
 * flip-flop, whose answer depends on what came before, nor for one with
 * more inputs than make a table worth reading.
 */
export const truthTable = (items: Item[]): TruthTable | null => {
    const of = (logic: string) =>
        items
            .filter(
                (item) =>
                    !item.hidden && symbolOf(item.symbol)?.logic === logic,
            )
            .sort((a, b) =>
                wordsOf(a).name.localeCompare(wordsOf(b).name, undefined, {
                    numeric: true,
                }),
            );
    // A push-button is an input too: held down, or not
    const inputs = [...of('input'), ...of('button')].sort((a, b) =>
        wordsOf(a).name.localeCompare(wordsOf(b).name, undefined, {
            numeric: true,
        }),
    );
    const outputs = of('output');

    if (
        !inputs.length ||
        !outputs.length ||
        inputs.length > 6 ||
        of('clock').length ||
        of('dff').length
    ) {
        return null;
    }

    const rows = Array.from({ length: 2 ** inputs.length }, (_, row) => {
        // The first input is the highest bit, as a truth table is written
        const set = inputs.map(
            (_, index) => ((row >> (inputs.length - 1 - index)) & 1) as 0 | 1,
        );
        const signals = simulate(items, {
            inputs: new Map(
                inputs.map((input, index) => [input.id, set[index] === 1]),
            ),
        });

        return {
            inputs: set,
            outputs: outputs.map((output) => signals.read(output, 'a')),
        };
    });

    return {
        inputs: inputs.map((item) => wordsOf(item).name),
        outputs: outputs.map((item) => wordsOf(item).name),
        rows,
    };
};
