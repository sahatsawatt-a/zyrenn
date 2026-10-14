// The symbols a circuit is drawn with: what each looks like, where its pins
// are, and how it sits on the board once it is turned. One list (symbols.json)
// is read by the canvas, by the drawing a note makes from text, and by the
// server (App\Support\Board\Symbols), so a part is the same part everywhere.
import type { Item, Side } from '@/features/boards/composables/items';
import library from './symbols.json';

/** Where a symbol's name and value are written, as it is drawn unturned. */
export type LabelPlace = 'split' | 'top' | 'right' | 'bottom' | 'left' | 'none';

export type PinDef = {
    name: string;
    x: number;
    y: number;
    // The way a wire leaves it; a junction's one pin has none, and is left
    // by whichever way the wire is going
    side: Side | null;
    aliases?: string[];
};

export type SymbolDef = {
    id: string;
    label: string;
    group: string;
    // The letters its designator starts with: R for R1. None for a ground, a
    // rail or a terminal, whose words are the name of the net they are on
    prefix: string;
    prefixes?: string[];
    aliases: string[];
    role?:
        | 'ground'
        | 'power'
        | 'terminal'
        | 'label'
        | 'junction'
        // The ends of a logic circuit: set by hand, ticking, or lit by it
        | 'input'
        | 'clock'
        | 'output';
    // What it does when the board is run (circuit/logic.ts): a gate works
    // its output out from its inputs, an input is whatever it was set to
    logic?: string;
    // The part of it filled in while it is on: an input's box, a lamp
    lit?: string;
    // The parts lit one by one: a display's segments, by the pin that lights each
    segments?: Record<string, string>;
    // Drawn one way while it is off and another while on: a switch's knob
    states?: { off: string; on: string };
    // A board or a chip: a box with a named pin down each side, made from a
    // list of the names rather than drawn by hand
    module?: boolean;
    // Words that are part of the drawing: a module's pin names
    texts?: { text: string; x: number; y: number; align: 'left' | 'right' }[];
    // Stood upright with its + on top when a circuit is laid out from text
    source?: boolean;
    // What a bare number is a number of: "10k" on a resistor is 10 kΩ
    unit?: string;
    hint: string;
    width: number;
    height: number;
    // How far inside its box the drawing stops, on the side its words are on
    inset: number;
    labels: LabelPlace;
    path: string;
    // The parts drawn solid: an arrowhead, a junction's dot
    fill?: string;
    pins: PinDef[];
};

/** Pins sit on this grid, so wires between parts lined up on it run straight. */
export const GRID: number = library.grid;

// ------------------------------------------------------------------ Modules

/** A board or a chip as the library lists it: its pins by name, by side. */
type ModuleSpec = {
    id: string;
    label: string;
    group?: string;
    logic?: string;
    prefix: string;
    aliases: string[];
    hint: string;
    left: string[];
    right: string[];
};

/** Pin names inside a module are set this big, in board units. */
export const PIN_TEXT_SIZE = 10;

/** Other names a pin answers to: GPIO4 is also D4, IO4, GP4 and 4. */
const alsoCalled = (name: string): string[] => {
    const numbered = name.match(/^(?:GPIO|GP|IO|D)(\d+)$/i);

    return numbered
        ? ['gpio', 'gp', 'io', 'd', '']
              .map((letters) => `${letters}${numbered[1]}`)
              .filter((other) => other !== name.toLowerCase())
        : [];
};

/**
 * The drawing of a module: a box as tall as its longer side needs, a lead
 * for every pin 20 apart down each side, and each pin's name inside by it.
 * The server draws up the same box (App\Support\Board\Symbols::module).
 */
const moduleDef = (spec: ModuleSpec): SymbolDef => {
    const grid = library.grid;
    const rows = Math.max(spec.left.length, spec.right.length, 1);
    const longest = (names: string[]) =>
        Math.max(0, ...names.map((name) => name.length));
    const body =
        Math.ceil(
            Math.max(
                80,
                (longest(spec.left) + longest(spec.right)) * 6.2 + 28,
            ) / grid,
        ) * grid;
    const from = spec.left.length ? grid : 0;
    const to = from + body;
    const width = to + (spec.right.length ? grid : 0);
    const height = (rows + 1) * grid;
    const row = (index: number) => (index + 1) * grid;

    return {
        id: spec.id,
        label: spec.label,
        group: spec.group ?? 'IoT modules',
        logic: spec.logic,
        prefix: spec.prefix,
        aliases: spec.aliases,
        hint: spec.hint,
        module: true,
        width,
        height,
        inset: 0,
        labels: 'top',
        path: [
            `M${from},0 H${to} V${height} H${from} Z`,
            ...spec.left.map((_, index) => `M0,${row(index)} H${from}`),
            ...spec.right.map((_, index) => `M${to},${row(index)} H${width}`),
        ].join(' '),
        texts: [
            ...spec.left.map((text, index) => ({
                text,
                x: from + 5,
                y: row(index),
                align: 'left' as const,
            })),
            ...spec.right.map((text, index) => ({
                text,
                x: to - 5,
                y: row(index),
                align: 'right' as const,
            })),
        ],
        pins: [
            ...spec.left.map((name, index) => ({
                name,
                x: 0,
                y: row(index),
                side: 'left' as const,
                aliases: alsoCalled(name),
            })),
            ...spec.right.map((name, index) => ({
                name,
                x: width,
                y: row(index),
                side: 'right' as const,
                aliases: alsoCalled(name),
            })),
        ],
    };
};

/** The symbols drawn by hand, and the modules made from their pin lists. */
const DRAWN = library.symbols as unknown as SymbolDef[];

export const SYMBOLS: SymbolDef[] = [
    ...DRAWN,
    ...(library.modules as ModuleSpec[]).map(moduleDef),
];

const byId = new Map(SYMBOLS.map((symbol) => [symbol.id, symbol]));

/** A pin's name as a module keeps it: letters, digits, "_", "+" and "-". */
const pinName = (name: string) =>
    name
        .trim()
        .replace(/[^A-Za-z0-9_+-]+/g, '_')
        .slice(0, 12);

/**
 * The id of a chip with pins of your own: "chip:VCC,GND|OUT" has VCC and GND
 * down its left and OUT on its right. The id is all there is to it, so an
 * item that names one carries its own pins wherever it goes.
 */
export const chipId = (left: string[], right: string[] = []): string => {
    const seen = new Set<string>();
    // One pin of each name, whichever side it is first met on
    const tidy = (names: string[]) => {
        const kept: string[] = [];

        for (const name of names.map(pinName)) {
            if (name && !seen.has(name.toLowerCase()) && kept.length < 40) {
                seen.add(name.toLowerCase());
                kept.push(name);
            }
        }

        return kept;
    };
    const sides = [tidy(left), tidy(right)];

    return `chip:${sides[0].join(',')}${sides[1].length ? `|${sides[1].join(',')}` : ''}`;
};

/** A module's pins as they are typed: "VCC, GND | OUT". */
export const pinList = (symbol: SymbolDef): string => {
    const names = (side: Side) =>
        symbol.pins
            .filter((pin) => pin.side === side)
            .map((pin) => pin.name)
            .join(', ');

    return names('right')
        ? `${names('left')} | ${names('right')}`
        : names('left');
};

/** The chip those typed pins make: its id, for an item to name. */
export const chipFrom = (typed: string): string => {
    const [left = '', right = ''] = typed.split('|');
    const names = (side: string) => side.split(/[,\s]+/).filter(Boolean);

    return chipId(names(left), names(right));
};

// Chips of one's own are drawn up as they are met, and kept
const chips = new Map<string, SymbolDef>();

export const symbolOf = (
    id: string | null | undefined,
): SymbolDef | undefined => {
    const known = byId.get(id ?? '');

    if (known || !id?.startsWith('chip:')) {
        return known;
    }

    let chip = chips.get(id);

    if (!chip) {
        const [left = '', right = ''] = id.slice(5).split('|');
        const names = (side: string) => side.split(',').filter(Boolean);

        chip = moduleDef({
            id,
            label: 'Chip',
            prefix: 'U',
            aliases: [],
            hint: 'IC',
            left: names(left),
            right: names(right),
        });

        if (chips.size > 500) {
            chips.clear();
        }

        chips.set(id, chip);
    }

    return chip;
};

/** The library as the shape flyout lists it. */
export const SYMBOL_GROUPS = [...new Set(SYMBOLS.map((symbol) => symbol.group))]
    .map((name) => ({
        name,
        symbols: SYMBOLS.filter((symbol) => symbol.group === name),
    }))
    .filter((group) => group.symbols.length);

/** A symbol by what it is called: its id, or another name it goes by. */
export const findSymbol = (word: string): SymbolDef | undefined => {
    const name = word.trim().toLowerCase();
    const id = name.replace(/[\s-]+/g, '_');

    return (
        byId.get(id) ??
        SYMBOLS.find(
            (symbol) =>
                symbol.aliases.includes(name) ||
                symbol.label.toLowerCase() === name,
        )
    );
};

/** What a designator stands for, by its letters: R7 a resistor, SW1 a switch. */
export const symbolForDesignator = (name: string): SymbolDef | undefined => {
    const letters = name.match(/^[A-Za-z]+/)?.[0].toUpperCase();

    if (!letters) {
        return undefined;
    }

    // The hand-drawn parts first: S1 is a switch before it is a sensor
    return (
        DRAWN.find((symbol) => symbol.prefix === letters) ??
        DRAWN.find((symbol) => symbol.prefixes?.includes(letters)) ??
        SYMBOLS.find((symbol) => symbol.prefix === letters)
    );
};

export const isSymbol = (item: Pick<Item, 'kind'>): boolean =>
    item.kind === 'symbol';

/** A pin by its name on a drawing, or any other it answers to: 1, a, anode. */
export const pinNamed = (
    symbol: SymbolDef,
    name: string,
): PinDef | undefined => {
    const wanted = name.toLowerCase();

    return (
        symbol.pins.find((pin) => pin.name.toLowerCase() === wanted) ??
        symbol.pins.find((pin) => pin.aliases?.includes(wanted))
    );
};

// ---------------------------------------------------------------- Geometry

const SIDES: Side[] = ['top', 'right', 'bottom', 'left'];

/** How many quarter turns clockwise a rotation comes to. */
const quarters = (rotation: number) =>
    (((Math.round(rotation / 90) % 4) + 4) % 4) as 0 | 1 | 2 | 3;

/** The side a side has become once its symbol is turned. */
export const turnSide = (side: Side, rotation: number): Side =>
    SIDES[(SIDES.indexOf(side) + quarters(rotation)) % 4];

// A turn by 90 degrees should land on whole numbers, not on 1e-15
const tidy = (value: number) => Math.round(value * 1000) / 1000;

type Box = Pick<Item, 'x' | 'y' | 'width' | 'height' | 'rotation'>;

/**
 * A point of the item's own box, on the board. An item is turned about the
 * corner it is drawn from, as Konva turns it.
 */
const onBoard = (item: Box, x: number, y: number) => {
    const angle = (item.rotation * Math.PI) / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);

    return {
        x: tidy(item.x + x * cos - y * sin),
        y: tidy(item.y + x * sin + y * cos),
    };
};

/** A board point in the item's own box: the other way round. */
export const inItem = (item: Box, point: { x: number; y: number }) => {
    const angle = (-item.rotation * Math.PI) / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const dx = point.x - item.x;
    const dy = point.y - item.y;

    return { x: tidy(dx * cos - dy * sin), y: tidy(dx * sin + dy * cos) };
};

/** The box a symbol covers on the board, turned as it is. */
export const symbolBox = (item: Box) => {
    const corners = [
        onBoard(item, 0, 0),
        onBoard(item, item.width, 0),
        onBoard(item, item.width, item.height),
        onBoard(item, 0, item.height),
    ];
    const xs = corners.map((corner) => corner.x);
    const ys = corners.map((corner) => corner.y);

    return {
        x: Math.min(...xs),
        y: Math.min(...ys),
        width: Math.max(...xs) - Math.min(...xs),
        height: Math.max(...ys) - Math.min(...ys),
    };
};

export type Pin = { name: string; x: number; y: number; side: Side | null };

type Drawn = Box & Pick<Item, 'symbol'>;

/** A symbol's pins where they are on the board, and the way each faces. */
export const pinsOf = (item: Drawn): Pin[] => {
    const symbol = symbolOf(item.symbol);

    if (!symbol) {
        return [];
    }

    const across = item.width / symbol.width;
    const down = item.height / symbol.height;

    return symbol.pins.map((pin) => ({
        name: pin.name,
        ...onBoard(item, pin.x * across, pin.y * down),
        side: pin.side ? turnSide(pin.side, item.rotation) : null,
    }));
};

export const pinOf = (item: Drawn, name: string): Pin | undefined => {
    const symbol = symbolOf(item.symbol);
    const pin = symbol ? pinNamed(symbol, name) : undefined;

    return pin
        ? pinsOf(item).find((placed) => placed.name === pin.name)
        : undefined;
};

/** The pin of a symbol closest to a point: where a loose end goes. */
export const nearestPin = (
    item: Drawn,
    point: { x: number; y: number },
): Pin | undefined => {
    let best: Pin | undefined;
    let shortest = Infinity;

    for (const pin of pinsOf(item)) {
        const away = (pin.x - point.x) ** 2 + (pin.y - point.y) ** 2;

        if (away < shortest) {
            shortest = away;
            best = pin;
        }
    }

    return best;
};

/**
 * Where to put a symbol so its pins land on the grid. Parts then line up
 * with one another, and the wires between them run straight.
 */
export const snapped = (
    item: Drawn,
    at: { x: number; y: number } = { x: item.x, y: item.y },
) => {
    const [pin] = pinsOf({ ...item, ...at });

    if (!pin) {
        return at;
    }

    return {
        x: tidy(at.x + Math.round(pin.x / GRID) * GRID - pin.x),
        y: tidy(at.y + Math.round(pin.y / GRID) * GRID - pin.y),
    };
};

/**
 * A symbol turned about its middle, which is how a part is turned on a
 * drawing: the corner it is drawn from moves so the middle does not.
 */
export const turned = (item: Box, by = 90) => {
    const centre = onBoard(item, item.width / 2, item.height / 2);
    const rotation = (((item.rotation + by) % 360) + 360) % 360;
    const corner = onBoard(
        { ...item, x: 0, y: 0, rotation },
        item.width / 2,
        item.height / 2,
    );

    return {
        x: tidy(centre.x - corner.x),
        y: tidy(centre.y - corner.y),
        rotation,
    };
};

/**
 * The turn that leaves one of a symbol's pins facing a given way, and puts
 * that pin at a given point: how a part is stood on a drawing laid out from
 * text.
 */
export const facing = (
    symbol: SymbolDef,
    pinName: string,
    side: Side,
    at: { x: number; y: number },
) => {
    const pin = pinNamed(symbol, pinName) ?? symbol.pins[0];
    const turns = pin.side
        ? (SIDES.indexOf(side) - SIDES.indexOf(pin.side) + 4) % 4
        : 0;
    const box = {
        x: 0,
        y: 0,
        width: symbol.width,
        height: symbol.height,
        rotation: turns * 90,
    };
    const offset = onBoard(box, pin.x, pin.y);

    return { ...box, x: tidy(at.x - offset.x), y: tidy(at.y - offset.y) };
};

// -------------------------------------------------------------------- Words

/** Names and values are set this big, in board units, with lines this far apart. */
export const LABEL_SIZE = 13;
export const LABEL_LINE = 16;

/**
 * What is written beside a symbol: its designator on the first line and its
 * value after it. A ground, a rail or a terminal has one name, the net's.
 */
export const wordsOf = (
    item: Pick<Item, 'symbol' | 'text'>,
): { name: string; value: string } => {
    const [first = '', ...rest] = item.text.split('\n');

    return symbolOf(item.symbol)?.prefix
        ? { name: first.trim(), value: rest.join(' ').trim() }
        : { name: item.text.replace(/\s+/g, ' ').trim(), value: '' };
};

export const withWords = (name: string, value: string): string =>
    value.trim() ? `${name.trim()}\n${value.trim()}` : name.trim();

export type SymbolLabel = {
    text: string;
    x: number;
    y: number;
    align: 'left' | 'center' | 'right';
    baseline: 'top' | 'middle' | 'bottom';
    // The designator, set a little heavier than the value under it
    name: boolean;
    // Smaller than a name or a value: a module's pin names
    size?: number;
    // Something worked out about the part, not written on it: its current
    note?: boolean;
};

/**
 * A symbol's words, placed on the board beside it and always the right way
 * up: over and under a part lying flat, to one side of a part stood on end,
 * on whichever side has no pin for a wire to run through them.
 */
export const symbolLabels = (
    item: Item,
    // Lines to add under its own: what was worked out about it
    notes: string[] = [],
): SymbolLabel[] => {
    const symbol = symbolOf(item.symbol);

    if (!symbol || symbol.labels === 'none') {
        return [];
    }

    const { name, value } = wordsOf(item);
    const lines = [
        { text: name, name: true },
        { text: value, name: false },
        ...notes.map((text) => ({ text, name: false, note: true })),
    ].filter((line) => line.text);

    if (!lines.length) {
        return [];
    }

    const box = symbolBox(item);
    const inset =
        symbol.inset *
        Math.min(item.width / symbol.width, item.height / symbol.height);
    const gap = 4;
    const across = box.x + box.width / 2;
    const down = box.y + box.height / 2;
    const upright = quarters(item.rotation) % 2 === 1;

    const where: LabelPlace =
        symbol.labels === 'split'
            ? upright
                ? 'right'
                : 'split'
            : turnSide(symbol.labels, item.rotation);

    // Its name over it, and the rest under it
    if (where === 'split' && lines.length >= 2) {
        return [
            {
                ...lines[0],
                x: across,
                y: box.y + inset - gap,
                align: 'center',
                baseline: 'bottom',
            },
            ...lines.slice(1).map((line, index): SymbolLabel => ({
                ...line,
                x: across,
                y: box.y + box.height - inset + gap + index * LABEL_LINE,
                align: 'center',
                baseline: 'top',
            })),
        ];
    }

    if (where === 'top' || where === 'split') {
        return lines.map((line, index) => ({
            ...line,
            x: across,
            y: box.y + inset - gap - (lines.length - 1 - index) * LABEL_LINE,
            align: 'center',
            baseline: 'bottom',
        }));
    }

    if (where === 'bottom') {
        return lines.map((line, index) => ({
            ...line,
            x: across,
            y: box.y + box.height - inset + gap + index * LABEL_LINE,
            align: 'center',
            baseline: 'top',
        }));
    }

    return lines.map((line, index) => ({
        ...line,
        x:
            where === 'right'
                ? box.x + box.width - inset + gap + 2
                : box.x + inset - gap - 2,
        y: down + (index - (lines.length - 1) / 2) * LABEL_LINE,
        align: where === 'right' ? 'left' : 'right',
        baseline: 'middle',
    }));
};

/**
 * A module's pin names, where they are on the board: inside the box, each by
 * its pin, and the right way up when the module is turned round.
 */
export const pinTexts = (item: Item): SymbolLabel[] => {
    const symbol = symbolOf(item.symbol);

    if (!symbol?.texts) {
        return [];
    }

    const across = item.width / symbol.width;
    const down = item.height / symbol.height;
    const turns = quarters(item.rotation);

    return symbol.texts.map((text) => ({
        text: text.text,
        ...onBoard(item, text.x * across, text.y * down),
        align:
            turns % 2 === 1
                ? 'center'
                : (turns === 2) === (text.align === 'left')
                  ? 'right'
                  : 'left',
        baseline: 'middle',
        name: false,
        size: PIN_TEXT_SIZE,
    }));
};

/** How wide a label comes out, near enough to keep parts out of its way. */
export const labelWidth = (text: string) => text.length * LABEL_SIZE * 0.6;

/** The box a label covers on the board. */
export const labelBounds = (label: SymbolLabel) => {
    const width = labelWidth(label.text);

    return {
        x:
            label.align === 'left'
                ? label.x
                : label.align === 'right'
                  ? label.x - width
                  : label.x - width / 2,
        y:
            label.baseline === 'top'
                ? label.y
                : label.baseline === 'bottom'
                  ? label.y - LABEL_LINE
                  : label.y - LABEL_LINE / 2,
        width,
        height: LABEL_LINE,
    };
};

/** The next designator free on a board: R3 where R1 and R2 are taken. */
export const nextDesignator = (
    items: Pick<Item, 'kind' | 'symbol' | 'text'>[],
    prefix: string,
): string => {
    let highest = 0;

    for (const item of items) {
        if (!isSymbol(item)) {
            continue;
        }

        const number = wordsOf(item).name.match(
            new RegExp(`^${prefix}(\\d+)$`, 'i'),
        );

        if (number) {
            highest = Math.max(highest, Number(number[1]));
        }
    }

    return `${prefix}${highest + 1}`;
};

/** The first of these names nothing on the board goes by yet. */
const unused = (
    items: Pick<Item, 'kind' | 'symbol' | 'text'>[],
    names: string[],
): string | undefined => {
    const taken = new Set(
        items.filter(isSymbol).map((item) => wordsOf(item).name.toUpperCase()),
    );

    return names.find((name) => !taken.has(name));
};

/** What a new symbol says before anything is typed on it. */
export const firstWords = (
    symbol: SymbolDef,
    items: Pick<Item, 'kind' | 'symbol' | 'text'>[],
): string => {
    if (symbol.prefix) {
        // A module says which board or sensor it is, as its value
        return withWords(
            nextDesignator(items, symbol.prefix),
            symbol.module ? symbol.hint : '',
        );
    }

    switch (symbol.role) {
        case 'ground':
        case 'junction':
            return '';
        // The inputs of a logic circuit are A, B, C..., its outputs Y, Z...
        case 'input':
            return (
                unused(items, 'ABCDEFGHIJKLMNOP'.split('')) ??
                nextDesignator(items, 'IN')
            );
        case 'output':
            return (
                unused(items, 'YZWXQRST'.split('')) ??
                nextDesignator(items, 'OUT')
            );
        default:
            return symbol.hint;
    }
};

/**
 * A value as it is written on a drawing. A bare number on a part that has a
 * unit is that many of it -- "10k" on a resistor is 10 kΩ, "4k7" is 4.7 kΩ,
 * "100n" on a capacitor 100 nF -- and anything else is left as it was typed.
 */
export const writtenValue = (symbol: SymbolDef, value: string): string => {
    const typed = value.trim();

    if (!symbol.unit || !typed) {
        return typed;
    }

    // The unit may be typed as well, in either case: "9V", "100nF"
    const unit = symbol.unit.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const split = typed.match(/^(\d+)([pnuµmkMGR])(\d+)$/);
    const plain = typed.match(
        new RegExp(`^(\\d+(?:\\.\\d+)?)\\s*([pnuµmkMG]?)(?:${unit})?$`, 'i'),
    );
    const number = split ? `${split[1]}.${split[3]}` : plain?.[1];
    const scale = split ? split[2] : plain?.[2];

    if (number === undefined || scale === undefined) {
        return typed;
    }

    return `${number} ${scale === 'u' ? 'µ' : scale === 'R' ? '' : scale}${symbol.unit}`;
};
