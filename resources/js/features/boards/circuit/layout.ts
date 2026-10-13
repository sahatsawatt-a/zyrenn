// A circuit laid out the way one is drawn by hand: the source stood on the
// left, parts in series running to the right along a line, anything that goes
// to ground hanging down from it, and the way back along the bottom.
//
// What comes out is board items -- symbols, the wires between their pins and
// the dots where wires meet -- so the same circuit can be put on a board to be
// worked on, or drawn in a note (svg.ts), and reads the same in both.
import {
    attachmentsOf,
    connectorPoints,
} from '@/features/boards/composables/connectors';
import { makeItem } from '@/features/boards/composables/items';
import type { Endpoint, Item, Side } from '@/features/boards/composables/items';
import type { Circuit, Net, Part } from './parse';
import {
    GRID,
    facing,
    labelBounds,
    pinsOf,
    symbolBox,
    symbolLabels,
    symbolOf,
    withWords,
} from './symbols';
import type { Pin, PinDef, SymbolDef } from './symbols';

/** The wire between a part and whatever it is joined to. */
const LEAD = GRID * 2;
/** How far apart parts drawn one above another are. */
const ROW = GRID * 4;
/**
 * The room kept beside a module's pins, for the names, rails and grounds
 * written against them: what hangs off one of its pins starts beyond it.
 */
const BESIDE = GRID * 6;

const OPPOSITE: Record<Side, Side> = {
    top: 'bottom',
    bottom: 'top',
    left: 'right',
    right: 'left',
};

type Box = { x: number; y: number; width: number; height: number };

/** One pin of something already on the drawing. */
type Hold = { item: Item; pin: Pin };

/**
 * A net's line across the drawing: parts hang off it and stand on it, one
 * after another to the right, and the part that carries on from it starts
 * where they end.
 */
type Track = {
    y: number;
    left: number;
    right: number;
    // Which way it grows: away from the pin it starts at
    dir: 1 | -1;
    // How far from its end the first part along it starts
    lead: number;
    // Where the next part to hang off it is tried first
    next: number;
    // How many parts carry on along it, and where the first one starts
    along: number;
    alongAt: number;
    // One of a module's rows of pins, or a line carried on from one: the
    // rows are 20 apart, so everything on it is kept in line
    row: boolean;
};

/** How a part sits against the net it is reached by. */
type Stance = 'below' | 'above' | 'block' | 'along' | 'back' | 'end';

const ORDER: Stance[] = ['below', 'above', 'block', 'along', 'back', 'end'];

const overlap = (a: Box, b: Box) =>
    a.x < b.x + b.width &&
    a.x + a.width > b.x &&
    a.y < b.y + b.height &&
    a.y + a.height > b.y;

const key = (part: string, pin: string) => `${part}.${pin}`;

/** The circuit as items: its parts where they belong, wired up. */
export const layOut = (circuit: Circuit): Item[] => {
    const netAt = new Map<string, Net>();

    for (const net of circuit.nets) {
        for (const pin of net.pins) {
            netAt.set(key(pin.part, pin.pin), net);
        }
    }

    const netOf = (part: Part, pin: PinDef) =>
        netAt.get(key(part.name, pin.name));

    // The way back to the source runs along the bottom: the ground, or in
    // a circuit drawn without one, whatever the source's negative end is on
    const source = circuit.parts.find((part) => part.symbol.source);
    const back =
        circuit.nets.find((net) => net.kind === 'ground') ??
        (source ? netOf(source, source.symbol.pins[0]) : undefined);
    const rail = back && back.pins.length >= 2 ? back : null;

    /** A net parts are laid out along, rather than one drawn as a rail. */
    const tracked = (net: Net | undefined): net is Net =>
        !!net && net.kind === 'wire' && net !== rail;

    const placed = new Map<string, { part: Part; item: Item }>();
    const taken: Box[] = [];
    const tracks = new Map<Net, Track>();
    const queue: Net[] = [];
    const extras: Item[] = [];
    const wires: Item[] = [];
    // Modules met while following a net: put down afterwards, in a column
    const later: Part[] = [];
    // Parts on a module's row of pins, whose rails and grounds go in line
    const inRow = new Set<string>();

    // ------------------------------------------------------------ Placing

    const drafted = (
        part: Part,
        pin: PinDef,
        side: Side,
        at: { x: number; y: number },
    ): Item => ({
        ...makeItem('symbol', 0, 0, { symbol: part.symbol.id }),
        ...facing(part.symbol, pin.name, side, at),
        text: part.symbol.prefix ? withWords(part.name, part.value) : part.name,
    });

    /** What a part covers: itself, with a little air, and its words. */
    const footprint = (item: Item): Box[] => {
        const box = symbolBox(item);

        return [
            {
                x: box.x - GRID / 2,
                y: box.y,
                width: box.width + GRID,
                height: box.height,
            },
            ...symbolLabels(item).map(labelBounds),
        ];
    };

    const clear = (item: Item) =>
        footprint(item).every((box) =>
            taken.every((other) => !overlap(box, other)),
        );

    const trackFrom = (pin: Pin, module: boolean, row: boolean): Track => {
        const y =
            pin.side === 'top'
                ? pin.y - LEAD
                : pin.side === 'bottom'
                  ? pin.y + LEAD
                  : pin.y;
        const dir = pin.side === 'left' ? -1 : 1;
        const lead = module ? BESIDE : LEAD;

        return {
            y,
            left: pin.x,
            right: pin.x,
            dir,
            lead,
            // A pin on the line itself leaves no room at its own tip
            next: y === pin.y ? pin.x + dir * lead : pin.x,
            along: 0,
            alongAt: pin.x,
            row,
        };
    };

    const put = (part: Part, item: Item, row = false) => {
        const module = !!part.symbol.module;

        placed.set(part.name, { part, item });
        taken.push(...footprint(item));

        if (module || row) {
            inRow.add(item.id);
        }

        // Beside a module's pins is kept for what is written against them
        if (module) {
            const box = symbolBox(item);
            const sides = new Set(pinsOf(item).map((pin) => pin.side));

            for (const side of ['left', 'right'] as const) {
                if (sides.has(side)) {
                    taken.push({
                        x:
                            side === 'left'
                                ? box.x - BESIDE + GRID
                                : box.x + box.width,
                        y: box.y,
                        width: BESIDE - GRID,
                        height: box.height,
                    });
                }
            }
        }

        // Every net it reaches for the first time starts from that pin
        for (const pin of pinsOf(item)) {
            const net = netAt.get(key(part.name, pin.name));

            if (tracked(net) && !tracks.has(net)) {
                tracks.set(net, trackFrom(pin, module, module || row));
                queue.push(net);
            }
        }
    };

    /**
     * Puts a part down with one pin at a point, facing a way -- or as near
     * as there is room, a grid step at a time in the direction given.
     */
    const settle = (
        part: Part,
        pin: PinDef,
        side: Side,
        at: { x: number; y: number },
        step: { x: number; y: number },
        row = false,
    ): Item => {
        let item = drafted(part, pin, side, at);

        for (let tries = 0; tries < 400 && !clear(item); tries++) {
            at = { x: at.x + step.x, y: at.y + step.y };
            item = drafted(part, pin, side, at);
        }

        put(part, item, row);

        return item;
    };

    const stanceOf = (part: Part, pin: PinDef): Stance => {
        const pins = part.symbol.pins;

        if (pins.length === 1) {
            return 'end';
        }

        if (pins.length !== 2 || !pin.side) {
            return 'block';
        }

        if (part.turn) {
            // The way it runs is the way its second pin faces
            const mine = pin === pins[1] ? part.turn : OPPOSITE[part.turn];

            return mine === 'top'
                ? 'below'
                : mine === 'bottom'
                  ? 'above'
                  : mine === 'left'
                    ? 'along'
                    : 'back';
        }

        // A source stands with its positive end up
        if (part.symbol.source) {
            return pin === pins[1] ? 'below' : 'above';
        }

        const far = netOf(part, pin === pins[0] ? pins[1] : pins[0]);

        if (far && (far === rail || far.kind === 'ground')) {
            return 'below';
        }

        return far?.kind === 'power' ? 'above' : 'along';
    };

    /** A part hung under a net's line, or stood on it. */
    const tap = (track: Track, part: Part, pin: PinDef, side: Side) => {
        const item = settle(
            part,
            pin,
            side,
            { x: track.next, y: track.y + (side === 'top' ? LEAD : -LEAD) },
            { x: track.dir * GRID, y: 0 },
        );
        const at = pinsOf(item).find((each) => each.name === pin.name)!;

        track.next = at.x + track.dir * LEAD;
        track.lead = LEAD;
        track.left = Math.min(track.left, at.x);
        track.right = Math.max(track.right, at.x);
    };

    /**
     * A part carrying on along the line, the way the line grows -- or, for
     * one that has to face the other way, back from where the line began. A
     * second one going the same way is drawn above the first.
     */
    const along = (track: Track, part: Part, pin: PinDef, back = false) => {
        const dir = back ? -track.dir : track.dir;
        // The pin it is reached by looks back at the line
        const side = dir === 1 ? 'left' : 'right';
        const end = dir === 1 ? track.right : track.left;

        // A part with several pins on the side it is reached by -- a gate's
        // inputs -- is stood off a step for each, so the wires to them
        // can come up one beside another
        const crowd = part.symbol.module
            ? 0
            : part.symbol.pins.filter((each) => each.side === pin.side).length -
              1;

        if (back || track.along++ === 0) {
            const item = settle(
                part,
                pin,
                side,
                {
                    x: end + dir * ((back ? LEAD : track.lead) + crowd * GRID),
                    y: track.y,
                },
                { x: dir * GRID, y: 0 },
                track.row,
            );
            const at = pinsOf(item).find((each) => each.name === pin.name)!;

            if (back) {
                track.left = Math.min(track.left, at.x);
                track.right = Math.max(track.right, at.x);
            } else {
                track.alongAt = at.x;
            }

            return;
        }

        settle(
            part,
            pin,
            side,
            { x: track.alongAt, y: track.y - ROW * (track.along - 1) },
            { x: 0, y: -ROW },
            track.row,
        );
    };

    const follow = (net: Net) => {
        const track = tracks.get(net)!;

        const waiting = circuit.parts
            .filter((part) => !placed.has(part.name))
            .flatMap((part) => {
                const pin = part.symbol.pins.find(
                    (each) => netOf(part, each) === net,
                );

                return pin ? [{ part, pin, stance: stanceOf(part, pin) }] : [];
            })
            .sort((a, b) => ORDER.indexOf(a.stance) - ORDER.indexOf(b.stance));

        for (const { part, pin, stance } of waiting) {
            // A part with a pin on two of these nets is put down by the first
            if (placed.has(part.name)) {
                continue;
            }

            // Another board or sensor is not squeezed in here: it gets a
            // place of its own once this one's parts are down
            if (part.symbol.module) {
                if (!later.includes(part)) {
                    later.push(part);
                }

                continue;
            }

            // A transistor, an op-amp: drawn as it is, by the pin reached
            let side: Stance =
                stance !== 'block'
                    ? stance
                    : pin.side === 'top'
                      ? 'below'
                      : pin.side === 'bottom'
                        ? 'above'
                        : (pin.side === 'right') === (track.dir === 1)
                          ? 'back'
                          : 'along';

            // On a module's row there is no room above or below: a part
            // that would hang from the line lies along it instead
            if (track.row && (side === 'below' || side === 'above')) {
                side = 'along';
            }

            switch (side) {
                case 'below':
                    tap(track, part, pin, 'top');
                    break;
                case 'above':
                    tap(track, part, pin, 'bottom');
                    break;
                case 'back':
                    along(track, part, pin, true);
                    break;
                case 'end':
                    // A terminal ends the line, unless the line carries on
                    if (track.along && !track.row) {
                        tap(track, part, pin, 'bottom');
                    } else {
                        along(track, part, pin);
                    }

                    break;
                default:
                    along(track, part, pin);
            }
        }
    };

    /** The first part of a drawing, or of a piece of one joined to nothing yet. */
    const begin = (part: Part, at: { x: number; y: number }) => {
        const pins = part.symbol.pins;
        const step = { x: GRID, y: 0 };
        const down = { x: at.x, y: at.y + LEAD };

        if (pins.length === 1) {
            return settle(part, pins[0], 'right', at, step);
        }

        if (pins.length !== 2 || !pins[0].side) {
            return settle(part, pins[0], pins[0].side ?? 'left', at, step);
        }

        if (part.turn) {
            return settle(part, pins[1], part.turn, at, step);
        }

        if (part.symbol.source) {
            return settle(part, pins[1], 'top', down, step);
        }

        // Between a net and the ground, it stands on end with the net on top
        const [first, second] = pins.map((pin) => netOf(part, pin));
        const low = (net: Net | undefined) =>
            !!net && (net === rail || net.kind === 'ground');

        if (low(second) || first?.kind === 'power') {
            return settle(part, pins[0], 'top', down, step);
        }

        if (low(first) || second?.kind === 'power') {
            return settle(part, pins[1], 'top', down, step);
        }

        return settle(part, pins[0], 'left', at, step);
    };

    const drain = () => {
        while (queue.length) {
            follow(queue.shift()!);
        }
    };

    const edge = () =>
        Math.ceil(Math.max(...taken.map((box) => box.x + box.width)) / GRID) *
        GRID;

    let origin = { x: 0, y: 0 };

    for (;;) {
        // A board first, the one with the most pins: everything else hangs
        // off its rows. Failing one, a source: a drawing reads from where
        // the power comes in
        const waiting = circuit.parts.filter((part) => !placed.has(part.name));
        const next =
            waiting
                .filter((part) => part.symbol.module)
                .sort(
                    (a, b) => b.symbol.pins.length - a.symbol.pins.length,
                )[0] ??
            waiting.find((part) => part.symbol.source) ??
            waiting[0];

        if (!next) {
            break;
        }

        begin(next, origin);
        drain();

        // The other boards and sensors it is joined to, down a column to
        // its right with room beside their pins, each with its own parts
        let column: { x: number; y: number } | null = null;

        while (later.length) {
            const part = later.shift()!;

            if (placed.has(part.name)) {
                continue;
            }

            column ??= {
                x: edge() + BESIDE + LEAD,
                y:
                    Math.ceil(Math.min(...taken.map((box) => box.y)) / GRID) *
                        GRID +
                    LEAD,
            };

            const item = settle(
                part,
                part.symbol.pins[0],
                part.symbol.pins[0].side ?? 'left',
                {
                    x: column.x,
                    y: column.y + part.symbol.pins[0].y,
                },
                { x: 0, y: GRID },
            );
            const box = symbolBox(item);

            // Room under it for the next one's name and value
            column = { x: column.x, y: box.y + box.height + LEAD * 2 };
            drain();
        }

        // Whatever is joined to none of that starts again, further along
        origin = { x: edge() + BESIDE + LEAD * 2, y: 0 };
    }

    // ------------------------------------------------------------- Wiring

    const endOf = (hold: Hold, side: Side | null = null): Endpoint => ({
        item: hold.item.id,
        pin: hold.pin.name,
        side,
        x: hold.pin.x,
        y: hold.pin.y,
    });

    // The name the next wire is to carry: a wire's label names its net
    let naming = '';

    const wire = (from: Endpoint, to: Endpoint) => {
        wires.push({
            ...makeItem('arrow', 0, 0),
            x: 0,
            y: 0,
            from,
            to,
            text: naming,
            startHead: 'none',
            endHead: 'none',
        });
        naming = '';
    };

    /** A symbol that is part of the wiring: a dot, a ground, a rail. */
    const extra = (
        symbol: SymbolDef,
        side: Side,
        at: { x: number; y: number },
        text = '',
    ): Hold => {
        const item: Item = {
            ...makeItem('symbol', 0, 0, { symbol: symbol.id }),
            ...facing(symbol, symbol.pins[0].name, side, at),
            text,
        };

        extras.push(item);

        return { item, pin: pinsOf(item)[0] };
    };

    const dot = symbolOf('junction')!;
    const earth = symbolOf('ground')!;
    const supply = symbolOf('power')!;
    const tag = symbolOf('label')!;

    const AWAY: Record<Side, { x: number; y: number }> = {
        top: { x: 0, y: -1 },
        bottom: { x: 0, y: 1 },
        left: { x: -1, y: 0 },
        right: { x: 1, y: 0 },
    };

    /** A symbol straight out from a pin, a grid step away, facing it. */
    const inLine = (hold: Hold, symbol: SymbolDef, name = '') => {
        const side = hold.pin.side ?? 'right';

        wire(
            endOf(hold),
            endOf(
                extra(
                    symbol,
                    OPPOSITE[side],
                    {
                        x: hold.pin.x + AWAY[side].x * GRID,
                        y: hold.pin.y + AWAY[side].y * GRID,
                    },
                    name,
                ),
            ),
        );
    };

    /** A ground or a rail of its own for one pin, a short wire away. */
    const capped = (hold: Hold, symbol: SymbolDef, name = '') => {
        const { pin } = hold;

        // Among a module's rows of pins there is only room straight out
        if (
            inRow.has(hold.item.id) &&
            pin.side !== 'top' &&
            pin.side !== 'bottom'
        ) {
            inLine(hold, symbol, name);

            return;
        }

        const up = symbol === supply;
        const away = up ? -GRID : GRID;
        const straight = pin.side === (up ? 'top' : 'bottom');
        const x =
            pin.side === 'left'
                ? pin.x - LEAD
                : straight
                  ? pin.x
                  : pin.x + LEAD;

        wire(
            endOf(hold),
            endOf(
                extra(
                    symbol,
                    up ? 'bottom' : 'top',
                    { x, y: pin.y + away },
                    name,
                ),
            ),
        );
    };

    // The names nets are given where they are joined by name, not by wire:
    // none may be one a net in the text already goes by
    const tags = new Map<string, Net>(
        circuit.nets
            .filter((net) => net.named)
            .map((net) => [net.name.toLowerCase(), net]),
    );

    const tagFor = (net: Net, first: Hold): string => {
        // A board's pin is known by its own name; anything else by its part's
        const wanted = net.named
            ? net.name
            : symbolOf(first.item.symbol)?.module
              ? first.pin.name
              : first.item.text.split('\n')[0] || first.pin.name;
        let name = wanted;

        for (
            let count = 2;
            tags.has(name.toLowerCase()) &&
            tags.get(name.toLowerCase()) !== net;
            count++
        ) {
            name = `${wanted}_${count}`;
        }

        tags.set(name.toLowerCase(), net);

        return name;
    };

    /**
     * Joins pins along a level line: each comes to the line by the shortest
     * way, a dot goes wherever three wires meet, and where only two do they
     * are simply one wire turning a corner.
     */
    // The upright runs wires have taken, so that two nets are never drawn
    // one on top of the other and read as joined
    const lanes: { x: number; top: number; bottom: number; net: Net | null }[] =
        [];
    let routing: Net | null = null;

    /** The nearest x from `x`, stepping `step`, no other net runs up at. */
    const lane = (x: number, step: number, from: number, to: number) => {
        const top = Math.min(from, to);
        const bottom = Math.max(from, to);
        const busy = (at: number) =>
            lanes.some(
                (each) =>
                    each.x === at &&
                    each.net !== routing &&
                    each.top <= bottom &&
                    each.bottom >= top,
            );

        for (let tries = 0; step && tries < 8 && busy(x); tries++) {
            x += step;
        }

        lanes.push({ x, top, bottom, net: routing });

        return x;
    };

    const trunk = (holds: Hold[], y: number, earthed = false) => {
        type Node = {
            x: number;
            on: Hold | null;
            drops: Hold[];
            earth: boolean;
        };

        const nodes = new Map<number, Node>();
        const nodeAt = (x: number) => {
            if (!nodes.has(x)) {
                nodes.set(x, { x, on: null, drops: [], earth: false });
            }

            return nodes.get(x)!;
        };

        for (const hold of holds) {
            const { pin } = hold;
            const level = pin.side !== 'top' && pin.side !== 'bottom';

            if (level && pin.y === y && !nodes.get(pin.x)?.on) {
                nodeAt(pin.x).on = hold;
            } else {
                // Out from the pin a step, then up or down to the line
                const step =
                    pin.side === 'left'
                        ? -GRID
                        : pin.side === 'right'
                          ? GRID
                          : 0;

                nodeAt(lane(pin.x + step, step, pin.y, y)).drops.push(hold);
            }
        }

        const line = [...nodes.values()].sort((a, b) => a.x - b.x);

        if (earthed) {
            (line.find((node) => !node.on) ?? line[0]).earth = true;
        }

        // What each spot on the line is joined to its neighbours by: a pin
        // of its own, or the dot put there
        const ends = line.map(
            (node, index): { hold: Hold; dot: boolean } | null => {
                // A pin and something else at one spot: the dot goes on the pin
                if (node.on && (node.drops.length || node.earth)) {
                    node.drops.push(node.on);
                    node.on = null;
                }

                if (node.on) {
                    return { hold: node.on, dot: false };
                }

                const neighbours =
                    (index > 0 ? 1 : 0) + (index < line.length - 1 ? 1 : 0);
                const meeting =
                    node.drops.length + (node.earth ? 1 : 0) + neighbours;

                if (meeting < 3) {
                    // Two pins and nothing else: they are joined to each other
                    if (!neighbours) {
                        const [first, second] = [
                            ...node.drops,
                            ...(node.earth
                                ? [
                                      extra(earth, 'top', {
                                          x: node.x,
                                          y: y + GRID,
                                      }),
                                  ]
                                : []),
                        ];

                        if (first && second) {
                            wire(endOf(first), endOf(second));
                        }

                        return null;
                    }

                    // One pin and one neighbour: the wire just turns a corner
                    return { hold: node.drops[0], dot: false };
                }

                const join = extra(dot, 'top', { x: node.x, y });

                for (const hold of node.drops) {
                    // A pin that is itself where the dot is, is met head on
                    const side =
                        hold.pin.y === y
                            ? OPPOSITE[hold.pin.side ?? 'right']
                            : hold.pin.y < y
                              ? 'top'
                              : 'bottom';

                    wire(endOf(hold), endOf(join, side));
                }

                if (node.earth) {
                    wire(
                        endOf(join, 'bottom'),
                        endOf(extra(earth, 'top', { x: node.x, y: y + GRID })),
                    );
                }

                return { hold: join, dot: true };
            },
        );

        for (let index = 0; index < ends.length - 1; index++) {
            const from = ends[index];
            const to = ends[index + 1];

            if (from && to) {
                wire(
                    endOf(from.hold, from.dot ? 'right' : null),
                    endOf(to.hold, to.dot ? 'left' : null),
                );
            }
        }
    };

    /**
     * Joins a net's pins by name rather than by wire. What lies in line with
     * `first` is still wired to it, and that wire carries the name; every
     * other pin is given a label reading the same.
     */
    const named = (net: Net, holds: Hold[], first: Hold, boards: boolean) => {
        const name = tagFor(net, first);
        const before = { wires: wires.length, extras: extras.length };

        /** Wires these along the line, the wire carrying the name. */
        const drawn = (line: Hold[]) => {
            wires.length = before.wires;
            extras.length = before.extras;
            naming = line.length > 1 ? name : '';

            if (line.length === 2) {
                wire(endOf(line[0]), endOf(line[1]));
            } else if (line.length > 2) {
                trunk(line, first.pin.y);
            }

            naming = '';

            return !throughParts(wires.slice(before.wires));
        };

        // Nearest first, each pin in line is wired in if no part is in the
        // way of the wire to it
        const line = [first];
        const inLineWith = holds
            .filter(
                (hold) =>
                    hold !== first &&
                    hold.pin.y === first.pin.y &&
                    !(boards && symbolOf(hold.item.symbol)?.module) &&
                    hold.pin.side !== 'top' &&
                    hold.pin.side !== 'bottom',
            )
            .sort(
                (a, b) =>
                    Math.abs(a.pin.x - first.pin.x) -
                    Math.abs(b.pin.x - first.pin.x),
            );

        for (const hold of inLineWith) {
            if (drawn([...line, hold])) {
                line.push(hold);
            }
        }

        drawn(line);
        holds
            .filter((hold) => line.length < 2 || !line.includes(hold))
            .forEach((hold) => inLine(hold, tag, name));
    };

    /** Whether any of these wires, as it will be drawn, crosses a part. */
    const throughParts = (drawn: Item[]): boolean => {
        const bodies = [...placed.values()].map((each) => symbolBox(each.item));
        const all = [
            ...[...placed.values()].map((each) => each.item),
            ...extras,
            ...drawn,
        ];
        const byId = new Map(all.map((item) => [item.id, item]));
        const attached = attachmentsOf(all);

        return drawn.some((line) => {
            const path = connectorPoints(line, byId, attached);

            for (let index = 0; index + 3 < path.length; index += 2) {
                const [x1, y1, x2, y2] = path.slice(index, index + 4);

                // A wire may touch a part at its pin, on its edge; inside
                // its box it has no business
                const inside = bodies.some(
                    (box) =>
                        Math.max(x1, x2) > box.x + 2 &&
                        Math.min(x1, x2) < box.x + box.width - 2 &&
                        Math.max(y1, y2) > box.y + 2 &&
                        Math.min(y1, y2) < box.y + box.height - 2,
                );

                if (inside) {
                    return true;
                }
            }

            return false;
        });
    };

    for (const net of circuit.nets) {
        const holds: Hold[] = net.pins.flatMap((ref) => {
            const item = placed.get(ref.part)?.item;
            const pin = item
                ? pinsOf(item).find((each) => each.name === ref.pin)
                : undefined;

            return item && pin ? [{ item, pin }] : [];
        });

        routing = net;

        if (net.kind === 'power') {
            holds.forEach((hold) => capped(hold, supply, net.name));

            continue;
        }

        const hanging = holds.filter((hold) => hold.pin.side === 'bottom');
        const floor = Math.max(...hanging.map((hold) => hold.pin.y)) + LEAD;

        if (net.kind === 'ground') {
            // The pins that hang down share the way back, and one ground;
            // any other is given a ground of its own
            const shared = net === rail && hanging.length >= 2 ? hanging : [];

            if (shared.length) {
                trunk(shared, floor, true);
            }

            holds
                .filter((hold) => !shared.includes(hold))
                .forEach((hold) => capped(hold, earth));

            continue;
        }

        const level =
            net === rail && hanging.length
                ? floor
                : (tracks.get(net)?.y ?? holds[0]?.pin.y ?? 0);

        // Pins of two boards, or two of one board's that are not in line,
        // are not joined by a wire run between the rows: each is given the
        // net's name instead, and the name is the join. Whatever lies on the
        // net's own line is still wired along it, and that wire carries the
        // name.
        const onModules = holds.filter(
            (hold) => symbolOf(hold.item.symbol)?.module,
        );
        const apart =
            new Set(onModules.map((hold) => hold.item.id)).size > 1 ||
            (onModules.length > 1 &&
                onModules.some((hold) => hold.pin.y !== level));

        if (apart) {
            named(net, holds, onModules[0], true);

            continue;
        }

        // Wired the plain way -- unless that runs a wire through a part,
        // which reads as joined to it: then the pins are joined by name too
        const before = { wires: wires.length, extras: extras.length };

        if (holds.length === 2) {
            wire(endOf(holds[0]), endOf(holds[1]));
        } else if (holds.length > 2) {
            trunk(holds, level);
        }

        if (throughParts(wires.slice(before.wires))) {
            wires.length = before.wires;
            extras.length = before.extras;
            named(
                net,
                holds,
                holds.find((hold) => hold.pin.side === 'right') ?? holds[0],
                false,
            );
        }
    }

    return [
        ...[...placed.values()].map((each) => each.item),
        ...wires,
        ...extras,
    ];
};
