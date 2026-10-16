// A circuit written as text, the way a Mermaid diagram is: its parts, one to
// a line, and the wires between them.
//
//     V1: 9V
//     R1: 330
//     D1: led red
//     V1 -- R1 -- D1 -- V1
//
// A part is "NAME: [what] [value]". What it is can be left out when the
// name says so (R1 is a resistor, C3 a capacitor); the value is whatever
// is left. A wire is a chain of ends joined by "--". An end is a pin
// ("Q1.b"), a part with two pins, which the chain then runs through from its
// first pin to its second, or the name of a net ("gnd", "vcc", "out") --
// every end written with that name is the same piece of wire.
//
// A board or a sensor from the library has its pins by name ("U1: esp32",
// then "U1.GPIO4"); a chip of your own lists them in brackets, those down
// its left and after a "|" those down its right:
//
//     U2: chip 74HC595 [SER, SRCLK, RCLK | QA, QB, QC]
import type { Side } from '@/features/boards/model/items';
import {
    chipFrom,
    findSymbol,
    pinNamed,
    symbolForDesignator,
    symbolOf,
    writtenValue,
} from './symbols';
import type { SymbolDef } from './symbols';

export type Part = {
    name: string;
    symbol: SymbolDef;
    value: string;
    // Which way it runs, first pin to second, when the text says ("@down")
    turn: Side | null;
};

export type PinRef = { part: string; pin: string };

export type Net = {
    name: string;
    // Given a name in the text, rather than numbered here
    named: boolean;
    // A ground and a supply rail are drawn as their own symbols
    kind: 'ground' | 'power' | 'wire';
    pins: PinRef[];
};

export type Circuit = {
    title: string;
    parts: Part[];
    nets: Net[];
    // Asked for by a line reading "values": worked out, and written on the drawing
    values: boolean;
    // "values hidden": worked out, but kept back until the reader asks to see
    // them -- for a guess to be made first
    valuesHidden: boolean;
};

export type Parsed =
    | { ok: true; circuit: Circuit }
    | { ok: false; error: string };

const GROUNDS = /^(gnd|ground|earth|0|vss|agnd|dgnd|pe)$/i;
const RAILS = /^(vcc|vdd|vee|vbat|vbus|v\+|[+-]\d.*|\d+v\d*)$/i;

/** Which way a part runs, first pin to second, by the word after "@". */
const TURNS: Record<string, Side> = {
    right: 'right',
    left: 'left',
    down: 'bottom',
    up: 'top',
};

/** How a net is drawn, going by what it is called. */
export const netKind = (name: string): Net['kind'] =>
    GROUNDS.test(name) ? 'ground' : RAILS.test(name) ? 'power' : 'wire';

class Unreadable extends Error {}

const END = /^[+-]?[A-Za-z0-9_]+\+?(?:\.(?:[A-Za-z0-9_]+[+-]?|[+-]))?/;

/** The ends of one chain, in order: "V1.+ -- R1 -- gnd". */
const endsOf = (line: string): string[] => {
    const ends: string[] = [];
    let rest = line.trim();

    for (;;) {
        const end = rest.match(END)?.[0];

        if (!end) {
            throw new Unreadable(`can't read "${rest}" as the end of a wire`);
        }

        ends.push(end);
        rest = rest.slice(end.length).trimStart();

        if (!rest) {
            return ends;
        }

        // Mermaid's arrows too, for a hand that is used to them
        const join = rest.match(/^-{2,}>?/)?.[0];

        if (!join) {
            throw new Unreadable(`expected "--" before "${rest}"`);
        }

        rest = rest.slice(join.length).trimStart();
    }
};

/** Reads a circuit's text; the answer says what was wrong, and on which line. */
export const parseCircuit = (source: string): Parsed => {
    const parts = new Map<string, Part>();
    // A name declared as a ground or a rail, whatever it is called
    const declared = new Map<string, Net['kind']>();
    const chains: { line: number; ends: string[] }[] = [];
    let title = '';
    let values = false;
    let valuesHidden = false;
    let lineNumber = 0;

    try {
        for (const written of source.split('\n')) {
            lineNumber++;

            const line = written.replace(/%%.*$/, '').trim();

            if (!line || /^(#|\/\/)/.test(line) || /^circuit$/i.test(line)) {
                continue;
            }

            // "values": work the circuit out, and write the answers on it --
            // or, with "hidden", only once the reader asks for them
            const asked = line.match(/^values(?:\s+(hidden|shown))?$/i);

            if (asked) {
                valuesHidden = asked[1]?.toLowerCase() === 'hidden';
                values = !valuesHidden;

                continue;
            }

            const heading = line.match(/^title\b[:\s]*(.*)$/i);

            if (heading) {
                title = heading[1].trim();

                continue;
            }

            const part = line.match(/^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.*)$/);

            if (part) {
                const [, name, written] = part;
                // Pins in brackets make it a chip with those pins
                const listed = written.match(/\[([^\]]*)\]/);
                const body = listed ? written.replace(listed[0], ' ') : written;
                const words = body.split(/\s+/).filter(Boolean);
                let turn: Side | null = null;

                if (words.length && words[words.length - 1].startsWith('@')) {
                    const word = words.pop()!.slice(1).toLowerCase();

                    if (!(word in TURNS)) {
                        throw new Unreadable(
                            `"@${word}" is not a direction: use @right, @left, @up or @down`,
                        );
                    }

                    turn = TURNS[word];
                }

                const named = words.length ? findSymbol(words[0]) : undefined;
                const chip = listed ? symbolOf(chipFrom(listed[1])) : undefined;

                if (listed && !chip?.pins.length) {
                    throw new Unreadable(
                        `${name} has no pins between its brackets: list them, as in "[VCC, GND | OUT]"`,
                    );
                }

                if (chip && named && !named.module) {
                    throw new Unreadable(
                        `a ${named.label.toLowerCase()} has its own pins: only a chip lists them in brackets`,
                    );
                }

                const symbol = chip ?? named ?? symbolForDesignator(name);

                if (!symbol) {
                    throw new Unreadable(
                        `don't know what ${name} is: say it, as in "${name}: resistor 10k"`,
                    );
                }

                if (parts.has(name.toLowerCase())) {
                    throw new Unreadable(`${name} is already a part`);
                }

                if (symbol.role === 'ground' || symbol.role === 'power') {
                    declared.set(name.toLowerCase(), symbol.role);

                    continue;
                }

                // A net label is only a name, which any end may be
                if (symbol.role === 'label') {
                    continue;
                }

                if (symbol.role === 'junction') {
                    throw new Unreadable(
                        `a junction needs no line of its own: wires that share an end are joined`,
                    );
                }

                parts.set(name.toLowerCase(), {
                    name,
                    symbol,
                    // A board from the library says which it is, unless
                    // the text says more
                    value:
                        writtenValue(
                            symbol,
                            (named ? words.slice(1) : words).join(' '),
                        ) || (symbol.module && !chip ? symbol.hint : ''),
                    turn,
                });

                continue;
            }

            if (line.includes('--')) {
                const ends = endsOf(line);

                if (ends.length < 2) {
                    throw new Unreadable('a wire needs two ends');
                }

                chains.push({ line: lineNumber, ends });

                continue;
            }

            throw new Unreadable(
                `can't read "${line}". A part is written "R1: 10k", a wire "R1 -- C1"`,
            );
        }

        // ------------------------------------------------------------ Nets
        // Every end is a pin or a name; a wire makes its two ends one net
        const parent = new Map<string, string>();
        const find = (node: string): string => {
            const up = parent.get(node) ?? node;

            if (up === node) {
                parent.set(node, node);

                return node;
            }

            const root = find(up);
            parent.set(node, root);

            return root;
        };
        const join = (a: string, b: string) => parent.set(find(a), find(b));

        // Net names as first written, by their lower-case selves
        const names = new Map<string, string>();

        const pinNode = (part: Part, pin: string) =>
            `pin:${part.name}.${pinNamed(part.symbol, pin)!.name}`;

        /** The node a chain comes into an end by, and the one it leaves by. */
        const through = (end: string, first: boolean, last: boolean) => {
            const dot = end.indexOf('.');
            const name = dot < 0 ? end : end.slice(0, dot);
            const part = parts.get(name.toLowerCase());

            if (dot >= 0) {
                const pin = end.slice(dot + 1);

                if (!part) {
                    throw new Unreadable(
                        `${name} is not a part: write "${name}: …" on a line of its own first`,
                    );
                }

                if (!pinNamed(part.symbol, pin)) {
                    throw new Unreadable(
                        `${part.name} has no pin "${pin}". Its pins are ${part.symbol.pins.map((each) => each.name).join(', ')}`,
                    );
                }

                const node = pinNode(part, pin);

                return { in: node, out: node };
            }

            if (!part) {
                names.set(
                    name.toLowerCase(),
                    names.get(name.toLowerCase()) ?? name,
                );

                const node = `net:${name.toLowerCase()}`;

                return { in: node, out: node };
            }

            const pins = part.symbol.pins;

            if (pins.length === 1) {
                const node = pinNode(part, pins[0].name);

                return { in: node, out: node };
            }

            // The middle of a chain runs through a part, in by its first
            // pin and out by its second; with more pins, which is anyone's guess
            if (pins.length > 2 && !first && !last) {
                throw new Unreadable(
                    `${part.name} has pins ${pins.map((each) => each.name).join(', ')}: say which, as in ${part.name}.${pins[0].name}`,
                );
            }

            if (pins.length > 2) {
                throw new Unreadable(
                    `say which pin of ${part.name}: ${pins.map((each) => `${part.name}.${each.name}`).join(', ')}`,
                );
            }

            return {
                in: pinNode(part, pins[0].name),
                out: pinNode(part, pins[1].name),
            };
        };

        for (const chain of chains) {
            lineNumber = chain.line;

            const ends = chain.ends.map((end, index) =>
                through(end, index === 0, index === chain.ends.length - 1),
            );

            ends.forEach((end, index) => {
                find(end.in);
                find(end.out);

                if (index > 0) {
                    join(ends[index - 1].out, end.in);
                }
            });
        }

        const grouped = new Map<string, string[]>();

        for (const node of parent.keys()) {
            const root = find(node);

            grouped.set(root, [...(grouped.get(root) ?? []), node]);
        }

        let numbered = 0;
        const nets: Net[] = [];

        for (const nodes of grouped.values()) {
            const called = nodes
                .filter((node) => node.startsWith('net:'))
                .map((node) => node.slice(4));
            const pins = nodes
                .filter((node) => node.startsWith('pin:'))
                .map((node) => {
                    const [part, pin] = node.slice(4).split('.');

                    return { part, pin };
                });

            if (!pins.length) {
                continue;
            }

            const kinds = called.map(
                (name) => declared.get(name) ?? netKind(name),
            );

            nets.push({
                name: called.length ? names.get(called[0])! : `N${++numbered}`,
                named: called.length > 0,
                kind: kinds.includes('ground')
                    ? 'ground'
                    : kinds.includes('power')
                      ? 'power'
                      : 'wire',
                pins,
            });
        }

        return {
            ok: true,
            circuit: {
                title,
                parts: [...parts.values()],
                nets,
                values,
                valuesHidden,
            },
        };
    } catch (problem) {
        if (problem instanceof Unreadable) {
            return {
                ok: false,
                error: `Line ${lineNumber}: ${problem.message}.`,
            };
        }

        throw problem;
    }
};
