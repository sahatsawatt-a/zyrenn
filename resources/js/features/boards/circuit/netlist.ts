// What a drawing on a board says as a circuit: its parts, which pins are
// joined to which, and what looks wrong with it. Read from the symbols and
// the wires between their pins, so the drawing is the design.
import { pinFor } from '@/features/boards/model/connectors';
import { boundsOf } from '@/features/boards/model/geometry';
import { isConnector } from '@/features/boards/model/items';
import type { Endpoint, Item } from '@/features/boards/model/items';
import { netKind } from './parse';
import { isSymbol, pinList, pinsOf, symbolOf, wordsOf } from './symbols';
import type { SymbolDef } from './symbols';

export type PartRow = {
    name: string;
    value: string;
    symbol: SymbolDef;
    item: Item;
};

export type NetRow = {
    name: string;
    kind: 'ground' | 'power' | 'wire';
    // Given its name on the drawing -- by a ground, a rail, a net label or
    // the words on a wire -- rather than numbered here
    named: boolean;
    // The parts' pins on it; a terminal is on it by its one pin
    pins: { part: string; pin: string }[];
};

export type Reading = {
    parts: PartRow[];
    // Named ends of the circuit: where it meets whatever is not drawn
    terminals: string[];
    nets: NetRow[];
    problems: string[];
};

export type BomRow = {
    what: string;
    value: string;
    names: string[];
};

const natural = (a: string, b: string) =>
    a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });

/** A name the circuit text can carry: letters, digits and "_". */
const written = (name: string, fallback: string) => {
    const tidy = name.trim().replace(/[^A-Za-z0-9_]+/g, '_');

    return /^[A-Za-z_]/.test(tidy) ? tidy : `${fallback}${tidy}`;
};

/**
 * Which pins on a drawing are joined to which: by a wire between them, by
 * a ground, a rail or a net label of one name, or by the words on a wire.
 * `find` answers the net a pin is on, the same for every pin on it.
 */
export const wiring = (items: Item[]) => {
    const shown = items.filter((item) => !item.hidden);
    const byId = new Map(shown.map((item) => [item.id, item]));
    const symbols = shown.filter(
        (item) => isSymbol(item) && symbolOf(item.symbol),
    );

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
    const node = (item: Pick<Item, 'id'>, pin: string) =>
        `pin:${item.id}:${pin}`;

    const problems: string[] = [];
    const kinds = new Map<string, NetRow['kind']>();
    const named = new Map<string, string>();

    const RANK = { wire: 0, power: 1, ground: 2 };

    /**
     * Puts a pin on the net of this name. A ground, a rail, a net label and
     * the words on a wire all name nets, and one name is one net whichever
     * of them says it: a label reading 3V3 is on the 3V3 rail.
     */
    const label = (
        pin: string,
        name: string,
        kind: NetRow['kind'] = 'wire',
    ) => {
        const net = `net:${name.trim().toLowerCase()}`;
        const was = kinds.get(net);

        join(pin, net);
        named.set(net, named.get(net) ?? name.trim());
        kinds.set(net, was && RANK[was] > RANK[kind] ? was : kind);
    };

    for (const item of symbols) {
        const symbol = symbolOf(item.symbol)!;
        const { name } = wordsOf(item);
        const [only] = symbol.pins;

        for (const pin of pinsOf(item)) {
            find(node(item, pin.name));
        }

        if (symbol.role === 'ground' || symbol.role === 'power') {
            // Every ground is one net; so is every rail of one name
            label(
                node(item, only.name),
                name || (symbol.role === 'ground' ? 'gnd' : 'vcc'),
                symbol.role,
            );
        } else if (symbol.role === 'label') {
            // Every label of one name is the same net, wherever it is drawn
            if (name) {
                label(node(item, only.name), name);
            } else {
                problems.push('A net label has no name.');
            }
        } else if (symbol.role === 'terminal' && name) {
            // Two terminals of one name are the same point, drawn twice
            join(
                node(item, only.name),
                `net:terminal:${written(name, 'T').toLowerCase()}`,
            );
        }
    }

    const centre = (end: Endpoint | null) => {
        const host = end?.item ? byId.get(end.item) : undefined;

        if (!host) {
            return { x: end?.x ?? 0, y: end?.y ?? 0 };
        }

        const box = boundsOf(host);

        return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    };

    // The net each wire is part of, by the wire's id
    const wires = new Map<string, string>();
    let loose = 0;

    for (const item of shown) {
        if (!isConnector(item)) {
            continue;
        }

        const ends = (['from', 'to'] as const).map((which) => {
            const end = item[which];
            const host = end?.item ? (byId.get(end.item) ?? null) : null;
            const pin = pinFor(
                end,
                host,
                centre(which === 'from' ? item.to : item.from),
            );

            return host && pin ? node(host, pin.name) : null;
        });

        if (ends[0] && ends[1]) {
            join(ends[0], ends[1]);
            wires.set(item.id, ends[0]);

            // What is written on a wire is the name of its net
            if (item.text.trim()) {
                label(ends[0], item.text);
            }
        } else if (ends[0] || ends[1]) {
            wires.set(item.id, (ends[0] ?? ends[1])!);
            loose++;
        }
    }

    return {
        symbols,
        find,
        node,
        // Every pin and every name there is, for gathering them into nets
        nodes: [...parent.keys()],
        kinds,
        named,
        wires,
        loose,
        problems,
    };
};

/** Reads the circuit drawn with these items. */
export const readCircuit = (items: Item[]): Reading => {
    const {
        symbols,
        find,
        node,
        nodes: every,
        kinds,
        named,
        loose,
        problems,
    } = wiring(items);

    const parts: PartRow[] = [];
    const terminals: string[] = [];
    // What each pin of a part, or a terminal, is written as
    const called = new Map<string, { part: string; pin: string }>();
    const used = new Set<string>();
    const modules = new Set<string>();

    for (const item of symbols) {
        const symbol = symbolOf(item.symbol)!;
        const { name, value } = wordsOf(item);
        const [only] = symbol.pins;

        // These are the wiring itself, not parts of the circuit
        if (
            symbol.role === 'junction' ||
            symbol.role === 'ground' ||
            symbol.role === 'power' ||
            symbol.role === 'label'
        ) {
            continue;
        }

        let unique = written(
            name,
            symbol.prefix || (symbol.role === 'terminal' ? 'T' : 'X'),
        );

        if (!name) {
            problems.push(`A ${symbol.label.toLowerCase()} has no name.`);
            unique = `${symbol.prefix || 'X'}_${item.id}`;
        } else if (used.has(unique.toLowerCase())) {
            if (symbol.role === 'terminal') {
                continue;
            }

            problems.push(`Two parts are called ${name}.`);
            unique = `${unique}_${item.id}`;
        }

        used.add(unique.toLowerCase());

        if (symbol.role === 'terminal') {
            terminals.push(unique);
            called.set(node(item, only.name), { part: unique, pin: only.name });

            continue;
        }

        parts.push({ name: unique, value, symbol, item });

        if (symbol.module) {
            modules.add(unique);
        }

        for (const pin of symbol.pins) {
            called.set(node(item, pin.name), { part: unique, pin: pin.name });
        }
    }

    if (loose) {
        problems.push(
            loose === 1
                ? 'A wire has an end that is on no pin.'
                : `${loose} wires have an end that is on no pin.`,
        );
    }

    // --------------------------------------------------------------- Nets
    const groups = new Map<string, string[]>();

    for (const each of every) {
        const root = find(each);

        groups.set(root, [...(groups.get(root) ?? []), each]);
    }

    const nets: NetRow[] = [];
    let numbered = 0;

    for (const nodes of groups.values()) {
        const pins = nodes.flatMap((each) => {
            const pin = called.get(each);

            return pin ? [pin] : [];
        });

        if (!pins.length) {
            continue;
        }

        const labels = nodes.filter((each) => kinds.has(each));
        const of = (kind: NetRow['kind']) =>
            labels.find((each) => kinds.get(each) === kind);
        const kind: NetRow['kind'] = of('ground')
            ? 'ground'
            : of('power')
              ? 'power'
              : 'wire';

        // On its own, with nothing to say where it goes
        if (pins.length === 1 && kind === 'wire') {
            const [{ part, pin }] = pins;

            // A board has more pins than any one circuit uses
            if (!terminals.includes(part) && !modules.has(part)) {
                problems.push(`${part} pin ${pin} is not connected.`);
            }

            continue;
        }

        nets.push({
            // A ground's name before a rail's, a rail's before a label's
            name: labels.length
                ? named.get(of('ground') ?? of('power') ?? labels[0])!
                : `N${++numbered}`,
            kind,
            named: labels.length > 0,
            pins: pins.sort(
                (a, b) => natural(a.part, b.part) || natural(a.pin, b.pin),
            ),
        });
    }

    for (const part of parts) {
        if (part.symbol.pins.length !== 2) {
            continue;
        }

        const [first, second] = part.symbol.pins.map((pin) =>
            find(node(part.item, pin.name)),
        );

        if (first === second) {
            problems.push(
                `${part.name} is shorted: both its pins are on one net.`,
            );
        }
    }

    parts.sort((a, b) => natural(a.name, b.name));
    nets.sort(
        (a, b) =>
            Number(b.kind !== 'wire') - Number(a.kind !== 'wire') ||
            natural(a.name, b.name),
    );

    return { parts, terminals, nets, problems };
};

/** The parts to buy: alike ones counted together. */
export const billOfMaterials = (reading: Reading): BomRow[] => {
    const rows = new Map<string, BomRow>();

    for (const part of reading.parts) {
        const key = `${part.symbol.id}|${part.value.toLowerCase()}`;
        const row = rows.get(key) ?? {
            what: part.symbol.label,
            value: part.value,
            names: [],
        };

        row.names.push(part.name);
        rows.set(key, row);
    }

    return [...rows.values()];
};

/** The bill of materials as a spreadsheet reads it. */
export const bomCsv = (reading: Reading): string => {
    const cell = (text: string) =>
        /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;

    return [
        'Part,Value,Quantity,Designators',
        ...billOfMaterials(reading).map((row) =>
            [row.what, row.value, String(row.names.length), row.names.join(' ')]
                .map(cell)
                .join(','),
        ),
    ].join('\n');
};

/**
 * The circuit as text (parse.ts reads it back): what a note draws from, and
 * what another tool can be handed.
 */
export const circuitText = (reading: Reading): string => {
    const taken = new Set(
        [...reading.parts.map((part) => part.name), ...reading.terminals].map(
            (name) => name.toLowerCase(),
        ),
    );
    // A net's name as an end of a wire, when it can be one
    const end = (name: string) =>
        /^[+-]?[A-Za-z0-9_]+\+?$/.test(name) ? name : written(name, 'N');

    const lines = [
        ...reading.parts.map((part) =>
            // A chip of one's own is its pins: they are listed with it
            (part.symbol.id.startsWith('chip:')
                ? `${part.name}: chip ${part.value} [${pinList(part.symbol)}]`
                : `${part.name}: ${part.symbol.id} ${part.value}`
            )
                .replace(/\s+/g, ' ')
                .trim(),
        ),
        ...reading.terminals.map((name) => `${name}: terminal`),
        // A ground or a rail whose name does not say what it is
        ...reading.nets
            .filter(
                (net) => net.kind !== 'wire' && netKind(net.name) !== net.kind,
            )
            .map((net) => `${end(net.name)}: ${net.kind}`),
    ];

    if (lines.length && reading.nets.length) {
        lines.push('');
    }

    for (const net of reading.nets) {
        const ends = net.pins.map(({ part, pin }) =>
            reading.terminals.includes(part) ? part : `${part}.${pin}`,
        );

        // A ground, a rail or a named net is an end like any other
        if (
            net.kind !== 'wire' ||
            (net.named && !taken.has(end(net.name).toLowerCase()))
        ) {
            ends.unshift(end(net.name));
        }

        if (ends.length > 1) {
            lines.push(ends.join(' -- '));
        }
    }

    return lines.join('\n');
};
