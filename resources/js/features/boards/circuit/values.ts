// A worked-out circuit as words on its drawing: what is written under each
// part and beside each wire. The same for a circuit drawn from text in a
// note and for one drawn by hand on a board.
import { connectorPoints } from '@/features/boards/composables/connectors';
import type { Item } from '@/features/boards/composables/items';
import { readCircuit, wiring } from './netlist';
import type { NetRow, PartRow } from './netlist';
import type { Net } from './parse';
import { solveCircuit, written } from './solve';
import type { PartAnswer, Solved } from './solve';
import { pinsOf } from './symbols';

type Answered = Extract<Solved, { ok: true }>;

/** Put before what a part is doing when that is more than it is good for. */
export const WARNED = '⚠ ';

/** What is written under a part: what flows through it, and what is across it. */
export const noteFor = (answer: PartAnswer, source: boolean): string => {
    const current = Math.abs(answer.current);
    const voltage = Math.abs(answer.voltage);
    const mark = answer.warning ? WARNED : '';

    if (answer.state === 'off') {
        return 'off';
    }

    if (current < 1e-12) {
        return voltage > 1e-9 ? written(voltage, 'V') : '';
    }

    // A wire, a closed switch, a fuse have nothing across them worth saying,
    // and a source already says its voltage
    return voltage > 1e-9 && !source
        ? `${mark}${written(current, 'A')} · ${written(voltage, 'V')}`
        : `${mark}${written(current, 'A')}`;
};

/**
 * Each net's voltage, written once: on the longest of its wires, where there
 * is most room. Ground is left unsaid -- it is what the rest are measured from.
 */
export const voltagesOn = (
    items: Item[],
    nets: Net[],
    solved: Answered,
    // The item a part of the circuit is drawn as, by the part's name
    itemOf: (part: string) => Item | undefined,
): Map<string, string> => {
    const { find, node, wires } = wiring(items);
    const byId = new Map(items.map((item) => [item.id, item]));

    // Which net of the circuit each joined-up group of pins on the drawing is
    const netOf = new Map<string, Net>();

    for (const net of nets) {
        for (const pin of net.pins) {
            const item = itemOf(pin.part);

            if (item && pinsOf(item).some((each) => each.name === pin.pin)) {
                netOf.set(find(node(item, pin.pin)), net);
            }
        }
    }

    const longest = new Map<Net, { wire: string; length: number }>();

    for (const [wire, pin] of wires) {
        const net = netOf.get(find(pin));
        const item = byId.get(wire);

        if (!net || !item || net === solved.ground || !solved.nets.has(net)) {
            continue;
        }

        const path = connectorPoints(item, byId);
        let length = 0;

        for (let index = 0; index + 3 < path.length; index += 2) {
            length += Math.hypot(
                path[index + 2] - path[index],
                path[index + 3] - path[index + 1],
            );
        }

        if (length > (longest.get(net)?.length ?? 0)) {
            longest.set(net, { wire, length });
        }
    }

    return new Map(
        [...longest].map(([net, { wire }]) => [
            wire,
            written(solved.nets.get(net) ?? 0, 'V'),
        ]),
    );
};

/**
 * Where a wire's voltage is written: beside the longest straight run of it,
 * clear of its corners.
 */
export const besideRun = (
    path: number[],
): { x: number; y: number; level: boolean } | null => {
    let run = { x1: 0, y1: 0, x2: 0, y2: 0, length: -1 };

    for (let index = 0; index + 3 < path.length; index += 2) {
        const [x1, y1, x2, y2] = path.slice(index, index + 4);
        const length = Math.hypot(x2 - x1, y2 - y1);

        if (length > run.length) {
            run = { x1, y1, x2, y2, length };
        }
    }

    if (run.length < 0) {
        return null;
    }

    const level = run.y1 === run.y2;

    return {
        x: (run.x1 + run.x2) / 2 + (level ? 0 : 6),
        y: (run.y1 + run.y2) / 2 + (level ? 13 : 4),
        level,
    };
};

// ------------------------------------------------------------------ a board

/** One part of a board's circuit, worked out. */
export type ValueRow = {
    name: string;
    item: string;
    voltage: string;
    current: string;
    power: string;
    warning?: string;
};

export type BoardValues = {
    // Under each part, by its item's id
    notes: Map<string, { text: string; warned: boolean }>;
    // Beside one wire of each net, by the wire's id
    wires: Map<string, string>;
    rows: ValueRow[];
    // What was assumed, what is over its rating, and what could not be
    // worked out, each in a sentence
    assumed: string[];
    warnings: string[];
    problems: string[];
};

/**
 * Everything on a board that can be worked out. A board can hold several
 * circuits, and other things besides: each set of parts wired together is
 * worked out on its own, so one that cannot be -- a microcontroller, a logic
 * gate -- does not stop the rest.
 */
export const boardValues = (items: Item[]): BoardValues => {
    const reading = readCircuit(items);
    const values: BoardValues = {
        notes: new Map(),
        wires: new Map(),
        rows: [],
        assumed: [],
        warnings: [],
        problems: [],
    };

    // Parts joined by a net are one circuit
    const group = new Map<string, string>();
    const find = (name: string): string => {
        const up = group.get(name) ?? name;

        if (up === name) {
            return name;
        }

        const root = find(up);

        group.set(name, root);

        return root;
    };

    for (const net of reading.nets) {
        const [first, ...rest] = net.pins;

        // A ground or a rail is one net across the whole board: it does not
        // make one circuit of everything that stands on it
        if (net.kind !== 'wire') {
            continue;
        }

        for (const pin of rest) {
            group.set(find(pin.part), find(first.part));
        }
    }

    const islands = new Map<string, PartRow[]>();

    for (const part of reading.parts) {
        const root = find(part.name);

        islands.set(root, [...(islands.get(root) ?? []), part]);
    }

    for (const parts of islands.values()) {
        const names = new Set(parts.map((part) => part.name));
        const nets: NetRow[] = reading.nets.flatMap((net) => {
            const pins = net.pins.filter((pin) => names.has(pin.part));

            return pins.length ? [{ ...net, pins }] : [];
        });

        // Logic is worked out as ones and noughts, as it is drawn; a board
        // or a sensor is not worked out at all. Neither is a fault.
        if (
            parts.every((part) => part.symbol.logic || part.symbol.module) ||
            !(
                parts.some((part) => part.symbol.source) ||
                nets.some((net) => net.kind === 'power')
            )
        ) {
            continue;
        }

        const solved = solveCircuit({ parts, nets });

        if (!solved.ok) {
            values.problems.push(solved.error);

            continue;
        }

        const byName = new Map(parts.map((part) => [part.name, part]));

        for (const [name, answer] of solved.parts) {
            const part = byName.get(name)!;
            const text = noteFor(answer, !!part.symbol.source);

            if (text) {
                values.notes.set(part.item.id, {
                    text,
                    warned: !!answer.warning,
                });
            }

            values.rows.push({
                name,
                item: part.item.id,
                voltage: written(Math.abs(answer.voltage), 'V'),
                current: written(Math.abs(answer.current), 'A'),
                power: written(Math.abs(answer.power), 'W'),
                ...(answer.warning ? { warning: answer.warning } : {}),
            });
        }

        for (const [wire, text] of voltagesOn(
            items.filter((item) => !item.hidden),
            nets,
            solved,
            (name) => byName.get(name)?.item,
        )) {
            values.wires.set(wire, text);
        }

        values.assumed.push(...solved.notes);
        values.warnings.push(...solved.warnings);
    }

    values.assumed = [...new Set(values.assumed)];

    return values;
};
