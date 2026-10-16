import { describe, expect, it } from 'vitest';
import {
    attachmentsOf,
    connectorPoints,
} from '@/features/boards/model/connectors';
import { isConnector, makeItem } from '@/features/boards/model/items';
import type { Item } from '@/features/boards/model/items';
import { circuitExamples } from './examples';
import { layOut } from './layout';
import { billOfMaterials, circuitText, readCircuit } from './netlist';
import { parseCircuit } from './parse';
import type { Circuit } from './parse';
import { renderCircuit } from './render';
import {
    GRID,
    SYMBOLS,
    chipFrom,
    isSymbol,
    pinList,
    pinNamed,
    pinTexts,
    nextDesignator,
    pinsOf,
    snapped,
    symbolBox,
    symbolLabels,
    turned,
    writtenValue,
    symbolOf,
} from './symbols';

const read = (source: string): Circuit => {
    const parsed = parseCircuit(source);

    if (!parsed.ok) {
        throw new Error(parsed.error);
    }

    return parsed.circuit;
};

const part = (symbol: string, x = 0, y = 0, text = ''): Item => ({
    ...makeItem('symbol', x, y, { symbol }),
    text,
});

/** A circuit's nets as sorted lists of "part.pin", whatever they are called. */
const joined = (nets: { pins: { part: string; pin: string }[] }[]) =>
    nets
        .map((net) =>
            net.pins
                .map((pin) => `${pin.part}.${pin.pin}`)
                .sort()
                .join(' '),
        )
        .sort();

describe('the symbol library', () => {
    it('keeps every pin on the grid, on the edge of its symbol', () => {
        for (const symbol of SYMBOLS) {
            for (const pin of symbol.pins) {
                if (symbol.role === 'junction') {
                    continue;
                }

                const onEdge =
                    pin.x === 0 ||
                    pin.x === symbol.width ||
                    pin.y === 0 ||
                    pin.y === symbol.height;

                expect(onEdge, `${symbol.id}.${pin.name}`).toBe(true);
                // Two pins of one part are whole grid steps apart
                expect(
                    Math.abs((pin.x - symbol.pins[0].x) % GRID),
                    `${symbol.id}.${pin.name}`,
                ).toBe(0);
                expect(
                    Math.abs((pin.y - symbol.pins[0].y) % GRID),
                    `${symbol.id}.${pin.name}`,
                ).toBe(0);
            }
        }
    });

    it('turns a part about its middle, and its pins with it', () => {
        const resistor = part('resistor', 100, 200);
        const upright = { ...resistor, ...turned(resistor) };

        expect(symbolBox(upright)).toEqual({
            x: 120,
            y: 180,
            width: 40,
            height: 80,
        });
        expect(pinsOf(upright)).toEqual([
            { name: '1', x: 140, y: 180, side: 'top' },
            { name: '2', x: 140, y: 260, side: 'bottom' },
        ]);
    });

    it('snaps a part so its pins land on the grid', () => {
        const transistor = part('npn', 0, 0);
        const at = snapped(transistor, { x: 47, y: 93 });

        for (const pin of pinsOf({ ...transistor, ...at })) {
            expect(Math.abs(pin.x % GRID)).toBe(0);
            expect(Math.abs(pin.y % GRID)).toBe(0);
        }
    });

    it("writes a part's words clear of its pins, the right way up", () => {
        const flat = part('resistor', 0, 0, 'R1\n10 kΩ');
        const [name, value] = symbolLabels(flat);

        expect(name).toMatchObject({ text: 'R1', align: 'center' });
        expect(name.y).toBeLessThan(20);
        expect(value.y).toBeGreaterThan(20);

        // Stood on end, both go to its right
        const stood = symbolLabels({ ...flat, ...turned(flat) });

        expect(stood.map((label) => label.align)).toEqual(['left', 'left']);
    });

    it('numbers the next part of a kind', () => {
        const items = [
            part('resistor', 0, 0, 'R1'),
            part('resistor', 0, 0, 'R7'),
        ];

        expect(nextDesignator(items, 'R')).toBe('R8');
        expect(nextDesignator(items, 'C')).toBe('C1');
    });

    it("writes a bare number as that many of the part's unit", () => {
        const resistor = symbolOf('resistor')!;

        expect(writtenValue(resistor, '10k')).toBe('10 kΩ');
        expect(writtenValue(resistor, '4k7')).toBe('4.7 kΩ');
        expect(writtenValue(resistor, '330')).toBe('330 Ω');
        expect(writtenValue(symbolOf('capacitor')!, '100n')).toBe('100 nF');
        expect(writtenValue(symbolOf('capacitor')!, '1u')).toBe('1 µF');
        expect(writtenValue(symbolOf('vsource')!, '9V')).toBe('9 V');
        // Anything else is left as it was typed
        expect(writtenValue(symbolOf('diode')!, '1N4148')).toBe('1N4148');
        expect(writtenValue(resistor, '10 kΩ 1%')).toBe('10 kΩ 1%');
    });
});

describe('boards, sensors and chips', () => {
    it('draws a board as a box with its pins by name, 20 apart', () => {
        const esp32 = symbolOf('esp32')!;

        // The server works out the same box (BoardSymbolsTest)
        expect([esp32.width, esp32.height]).toEqual([160, 320]);
        expect(esp32.pins).toHaveLength(30);
        expect(pinNamed(esp32, 'GPIO4')).toMatchObject({
            x: 160,
            y: 220,
            side: 'right',
        });
        // A pin answers to what is printed beside it on the board, too
        expect(pinNamed(esp32, 'D4')?.name).toBe('GPIO4');
        expect(pinNamed(esp32, 'io21')?.name).toBe('GPIO21');
    });

    it('makes a chip of the pins it is given', () => {
        const id = chipFrom('VCC, GND, vcc | OUT, a/b');
        const chip = symbolOf(id)!;

        // One of each name, and nothing a wire's end could not be written as
        expect(id).toBe('chip:VCC,GND|OUT,a_b');
        expect(chip.pins.map((pin) => [pin.name, pin.side])).toEqual([
            ['VCC', 'left'],
            ['GND', 'left'],
            ['OUT', 'right'],
            ['a_b', 'right'],
        ]);
        expect(pinList(chip)).toBe('VCC, GND | OUT, a_b');
        expect(symbolOf('chip:')).toMatchObject({ pins: [] });
    });

    it('writes its pin names the right way up when it is turned round', () => {
        const chip = part(chipFrom('A | B'), 0, 0);
        const names = (item: Item) =>
            pinTexts(item).map((text) => [text.text, text.align]);

        expect(names(chip)).toEqual([
            ['A', 'left'],
            ['B', 'right'],
        ]);
        expect(names({ ...chip, ...turned(chip, 180) })).toEqual([
            ['A', 'right'],
            ['B', 'left'],
        ]);
    });

    it('reads a board by name and a chip by its bracketed pins', () => {
        const circuit = read(`
            U1: esp32
            S1: dht22
            U2: chip 74HC595 [SER, SRCLK | QA, QB]
            U1.D4 -- S1.DATA
            U1.GPIO23 -- U2.SER
        `);

        expect(
            circuit.parts.map((each) => [
                each.name,
                each.symbol.id,
                each.value,
            ]),
        ).toEqual([
            ['U1', 'esp32', 'ESP32'],
            ['S1', 'dht22', 'DHT22'],
            ['U2', 'chip:SER,SRCLK|QA,QB', '74HC595'],
        ]);
        expect(joined(circuit.nets)).toEqual([
            'S1.DATA U1.GPIO4',
            'U1.GPIO23 U2.SER',
        ]);

        const parsed = parseCircuit('R1: resistor [A, B]');

        expect(!parsed.ok && parsed.error).toMatch(/only a chip lists them/);
    });

    it('joins two boards by name, not by a wire run between their pins', () => {
        const items = layOut(
            read(`
                U1: esp32
                S1: dht22
                DS1: oled
                U1.GPIO4 -- S1.DATA
                U1.GPIO21 -- DS1.SDA
                U1.GPIO22 -- DS1.SCL
                S1.VCC -- 3V3
                DS1.VCC -- 3V3
                U1.3V3 -- 3V3
            `),
        );
        const byId = new Map(items.map((item) => [item.id, item]));
        const isModule = (id: string | null | undefined) =>
            !!symbolOf(byId.get(id ?? '')?.symbol)?.module;

        // No wire has a board at both ends
        expect(
            items
                .filter(isConnector)
                .some(
                    (line) =>
                        isModule(line.from?.item) && isModule(line.to?.item),
                ),
        ).toBe(false);

        // Each signal is a label on the board's pin and the same on the sensor's
        const labels = items
            .filter((item) => item.symbol === 'label')
            .map((item) => item.text)
            .sort();

        expect(labels).toEqual([
            'GPIO21',
            'GPIO21',
            'GPIO22',
            'GPIO22',
            'GPIO4',
            'GPIO4',
        ]);
        // ...and the rail is drawn at each pin that is on it
        expect(
            items
                .filter((item) => item.symbol === 'power')
                .map((item) => item.text),
        ).toEqual(['3V3', '3V3', '3V3']);

        // Read back off the drawing, the names are the joins
        expect(joined(readCircuit(items).nets)).toEqual([
            'DS1.SCL U1.GPIO22',
            'DS1.SDA U1.GPIO21',
            'DS1.VCC S1.VCC U1.3V3',
            'S1.DATA U1.GPIO4',
        ]);
    });

    it('takes a label, a rail and the words on a wire of one name as one net', () => {
        const r1 = part('resistor', 0, 0, 'R1');
        const r2 = part('resistor', 0, 100, 'R2');
        const r3 = part('resistor', 0, 200, 'R3');
        const r4 = part('resistor', 0, 300, 'R4');
        const tag = part('label', -40, 0, 'BUS');
        const rail = part('power', -40, 100, 'bus');
        const pinned = (
            from: Item,
            fromPin: string,
            to: Item,
            toPin: string,
        ) => ({
            ...makeItem('arrow', 0, 0),
            from: { item: from.id, pin: fromPin, side: null, x: 0, y: 0 },
            to: { item: to.id, pin: toPin, side: null, x: 0, y: 0 },
        });
        const reading = readCircuit([
            r1,
            r2,
            r3,
            r4,
            tag,
            rail,
            pinned(r1, '1', tag, '1'),
            pinned(r2, '1', rail, '1'),
            { ...pinned(r3, '1', r4, '1'), text: 'Bus' },
        ]);

        expect(reading.nets).toMatchObject([
            { name: 'BUS', kind: 'power', named: true },
        ]);
        expect(joined(reading.nets)).toEqual(['R1.1 R2.1 R3.1 R4.1']);
    });

    it("does not call a board's unused pins a problem", () => {
        const reading = readCircuit(
            layOut(read('U1: esp32\nR1: 220\nU1.GPIO2 -- R1 -- gnd')),
        );

        expect(reading.problems).toEqual([]);
        expect(circuitText(reading)).toContain('U1: esp32 ESP32');
    });

    it("writes a chip of one's own out with its pins", () => {
        const source =
            'U1: chip 555 [GND, TRIG | OUT]\nR1: 1k\nU1.OUT -- R1 -- gnd';
        const text = circuitText(readCircuit(layOut(read(source))));

        expect(text).toContain('U1: chip 555 [GND, TRIG | OUT]');
        expect(joined(read(text).nets)).toEqual(joined(read(source).nets));
    });
});

describe('circuit text', () => {
    it('reads parts by their names, or by what they are said to be', () => {
        const circuit = read(`
            %% a comment
            R1: 10k
            C1: electrolytic 100u
            D1: led red
            Q1: BC547
            X1: resistor 5
        `);

        expect(
            circuit.parts.map((each) => [
                each.name,
                each.symbol.id,
                each.value,
            ]),
        ).toEqual([
            ['R1', 'resistor', '10 kΩ'],
            ['C1', 'capacitor_polarized', '100 µF'],
            ['D1', 'led', 'red'],
            ['Q1', 'npn', 'BC547'],
            ['X1', 'resistor', '5 Ω'],
        ]);
    });

    it('runs a chain through a part, in by its first pin and out by its second', () => {
        const circuit = read(`
            V1: 9V
            R1: 330
            D1: led
            V1 -- R1 -- D1 -- V1
        `);

        expect(joined(circuit.nets)).toEqual([
            'D1.a R1.2',
            'D1.k V1.-',
            'R1.1 V1.+',
        ]);
    });

    it('joins every end written with one net name', () => {
        const circuit = read(`
            R1: 1k
            R2: 1k
            C1: 1u
            R1.2 -- out
            out -- R2.1
            C1.1 -- out
            R2.2 -- gnd
            C1.2 -- GND
            R1.1 -- +5V
        `);
        const net = (name: string) =>
            circuit.nets.find((each) => each.name.toLowerCase() === name);

        expect(net('out')?.pins).toHaveLength(3);
        expect(net('gnd')).toMatchObject({ kind: 'ground' });
        expect(net('gnd')?.pins).toHaveLength(2);
        expect(net('+5v')).toMatchObject({ kind: 'power' });
    });

    it('says what is wrong, and on which line', () => {
        const error = (source: string) => {
            const parsed = parseCircuit(source);

            return parsed.ok ? '' : parsed.error;
        };

        expect(error('R1: 10k\nR1 -- Q7.b')).toMatch(
            /^Line 2: Q7 is not a part/,
        );
        expect(error('Q1: npn\nR1: 1k\nR1 -- Q1 -- R1')).toMatch(
            /^Line 3: Q1 has pins b, c, e/,
        );
        expect(error('R1: 1k\nR1.9 -- gnd')).toMatch(
            /^Line 2: R1 has no pin "9"\. Its pins are 1, 2/,
        );
        expect(error('Z1: 5')).toMatch(/^Line 1: don't know what Z1 is/);
        expect(error('R1: 1k\nR1: 2k')).toMatch(
            /^Line 2: R1 is already a part/,
        );
        expect(error('hello there')).toMatch(/^Line 1: can't read/);
    });
});

describe('a circuit laid out from text', () => {
    it.each(circuitExamples)(
        'draws "$label" as the circuit it is',
        (example) => {
            const circuit = read(example.source);
            const items = layOut(circuit);
            const byId = new Map(items.map((item) => [item.id, item]));
            const attached = attachmentsOf(items);

            // Read back off the drawing, it is the circuit that was written
            expect(joined(readCircuit(items).nets)).toEqual(
                joined(
                    circuit.nets.filter(
                        (net) => net.pins.length > 1 || net.kind !== 'wire',
                    ),
                ),
            );
            expect(readCircuit(items).problems).toEqual([]);

            for (const item of items) {
                if (isSymbol(item)) {
                    // Every pin is on the grid
                    for (const pin of pinsOf(item)) {
                        expect(
                            Math.abs(pin.x % GRID),
                            `${item.text} ${pin.name}`,
                        ).toBe(0);
                        expect(
                            Math.abs(pin.y % GRID),
                            `${item.text} ${pin.name}`,
                        ).toBe(0);
                    }
                }

                if (isConnector(item)) {
                    // Every wire runs level or upright, never at a slant
                    const path = connectorPoints(item, byId, attached);

                    for (let index = 0; index + 3 < path.length; index += 2) {
                        const level = path[index + 1] === path[index + 3];
                        const upright = path[index] === path[index + 2];

                        expect(level || upright).toBe(true);
                    }
                }
            }

            // No part is put down on top of another
            const parts = items.filter(
                (item) => isSymbol(item) && !symbolOf(item.symbol)?.role,
            );

            for (const [index, one] of parts.entries()) {
                for (const other of parts.slice(index + 1)) {
                    const a = symbolBox(one);
                    const b = symbolBox(other);
                    const apart =
                        a.x >= b.x + b.width ||
                        b.x >= a.x + a.width ||
                        a.y >= b.y + b.height ||
                        b.y >= a.y + a.height;

                    expect(apart, `${one.text} and ${other.text}`).toBe(true);
                }
            }
        },
    );

    it('stands the source on the left and brings the loop back along the bottom', () => {
        const items = layOut(
            read('V1: 9V\nR1: 330\nD1: led\nV1 -- R1 -- D1 -- V1'),
        );
        const find = (name: string) =>
            items.find((item) => item.text.startsWith(name))!;

        // The source upright, the resistor lying flat to its right
        expect(find('V1').rotation).toBe(0);
        expect(find('R1').rotation).toBe(0);
        expect(symbolBox(find('R1')).x).toBeGreaterThan(
            symbolBox(find('V1')).x,
        );
        // The LED hangs down the right-hand side, anode up
        expect(pinsOf(find('D1')).map((pin) => pin.side)).toEqual([
            'top',
            'bottom',
        ]);
        // Three wires close the loop, with no dot needed
        expect(items.filter(isConnector)).toHaveLength(3);
        expect(items.filter((item) => item.symbol === 'junction')).toHaveLength(
            0,
        );
    });

    it('puts a dot where three wires meet, and one ground on the way back', () => {
        const items = layOut(
            read(
                'V1: 12V\nR1: 10k\nR2: 4k7\nC1: 100n\nV1 -- R1 -- out\nout -- R2 -- gnd\nout -- C1 -- gnd\nV1.- -- gnd',
            ),
        );

        expect(items.filter((item) => item.symbol === 'ground')).toHaveLength(
            1,
        );
        expect(
            items.filter((item) => item.symbol === 'junction').length,
        ).toBeGreaterThanOrEqual(2);
    });

    it('turns a part the way the text asks', () => {
        const items = layOut(read('R1: 1k\nR2: 1k @down\nR1 -- R2'));
        const turnedDown = items.find((item) => item.text.startsWith('R2'))!;

        expect(pinsOf(turnedDown).map((pin) => pin.side)).toEqual([
            'top',
            'bottom',
        ]);
    });

    it('draws it, or says what is wrong', () => {
        const drawn = renderCircuit('V1: 9V\nR1: 330\nV1 -- R1 -- V1');

        expect(drawn.ok && drawn.svg).toContain('<svg');
        expect(drawn.ok && drawn.svg).toContain('330 Ω');
        expect(renderCircuit('R1 -- ')).toMatchObject({ ok: false });
        // Words are written into the drawing as text, never as markup
        const sly = renderCircuit('D1: led <b>x</b>\nR1: 1\nD1 -- R1');

        expect(sly.ok && sly.svg).toContain('&lt;b&gt;x&lt;/b&gt;');
    });
});

describe('a circuit read off a board', () => {
    const wire = (
        from: Item,
        fromPin: string,
        to: Item,
        toPin: string,
    ): Item => ({
        ...makeItem('arrow', 0, 0),
        x: 0,
        y: 0,
        from: { item: from.id, pin: fromPin, side: null, x: 0, y: 0 },
        to: { item: to.id, pin: toPin, side: null, x: 0, y: 0 },
    });

    it('takes a wire between two pins as a net, and says what is left open', () => {
        const r1 = part('resistor', 0, 0, 'R1\n10k');
        const c1 = part('capacitor', 200, 0, 'C1\n100n');
        const reading = readCircuit([r1, c1, wire(r1, '2', c1, '1')]);

        expect(joined(reading.nets)).toEqual(['C1.1 R1.2']);
        expect(reading.problems).toEqual([
            'R1 pin 1 is not connected.',
            'C1 pin 2 is not connected.',
        ]);
    });

    it('joins everything on a ground, and everything on a rail of one name', () => {
        const r1 = part('resistor', 0, 0, 'R1');
        const r2 = part('resistor', 0, 100, 'R2');
        const earth = [part('ground', 0, 0), part('ground', 0, 100)];
        const rail = [part('power', 0, 0, 'VCC'), part('power', 0, 100, 'VCC')];
        const reading = readCircuit([
            r1,
            r2,
            ...earth,
            ...rail,
            wire(r1, '1', rail[0], '1'),
            wire(r2, '1', rail[1], '1'),
            wire(r1, '2', earth[0], '1'),
            wire(r2, '2', earth[1], '1'),
        ]);

        expect(reading.nets).toMatchObject([
            { name: 'gnd', kind: 'ground' },
            { name: 'VCC', kind: 'power' },
        ]);
        expect(joined(reading.nets)).toEqual(['R1.1 R2.1', 'R1.2 R2.2']);
        expect(reading.problems).toEqual([]);
    });

    it('counts alike parts together, and catches a name used twice', () => {
        const reading = readCircuit([
            part('resistor', 0, 0, 'R1\n10k'),
            part('resistor', 0, 100, 'R2\n10k'),
            part('resistor', 0, 200, 'R2\n1k'),
        ]);

        expect(reading.problems).toContain('Two parts are called R2.');
        expect(
            billOfMaterials(reading).map((row) => [
                row.value,
                row.names.length,
            ]),
        ).toEqual([
            ['10k', 2],
            ['1k', 1],
        ]);
    });

    it('writes the drawing out as text that draws the same circuit', () => {
        const circuit = read(
            circuitExamples.find((each) => each.id === 'amplifier')!.source,
        );
        const text = circuitText(readCircuit(layOut(circuit)));

        expect(joined(read(text).nets)).toEqual(joined(circuit.nets));
        expect(
            read(text)
                .parts.map((each) => each.name)
                .sort(),
        ).toEqual(circuit.parts.map((each) => each.name).sort());
    });
});

describe('a wire on the canvas', () => {
    it('turns one corner between a pin that points up and one that points across', () => {
        const source = part('vsource', 0, 40);
        const resistor = part('resistor', 80, 0);
        const line: Item = {
            ...makeItem('arrow', 0, 0),
            from: { item: source.id, pin: '+', side: null, x: 0, y: 0 },
            to: { item: resistor.id, pin: '1', side: null, x: 0, y: 0 },
        };
        const byId = new Map(
            [source, resistor, line].map((item) => [item.id, item]),
        );

        // Up out of the source, round, and straight into the resistor
        expect(connectorPoints(line, byId)).toEqual([20, 40, 20, 20, 80, 20]);
    });

    it('stays on its pin when the part is turned', () => {
        const a = part('resistor', 0, 0);
        const b = part('resistor', 200, 0);
        const line: Item = {
            ...makeItem('arrow', 0, 0),
            from: { item: a.id, pin: '2', side: null, x: 0, y: 0 },
            to: { item: b.id, pin: '1', side: null, x: 0, y: 0 },
        };
        const stood = { ...b, ...turned(b) };
        const path = connectorPoints(
            line,
            new Map([a, stood, line].map((item) => [item.id, item])),
        );
        const [pin] = pinsOf(stood);

        expect(path.slice(-2)).toEqual([pin.x, pin.y]);
    });
});
