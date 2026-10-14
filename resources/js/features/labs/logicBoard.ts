import { layOut } from '@/features/boards/circuit/layout';
import { parseCircuit } from '@/features/boards/circuit/parse';
import { pinOf, symbolOf } from '@/features/boards/circuit/symbols';
import { makeItem } from '@/features/boards/composables/items';
import type { Item } from '@/features/boards/composables/items';

// The board the labs page opens: the logic parts being tried out, each wired
// up and ready to click -- three-input gates, a push-button, a 7-segment
// display. Placed by hand, on the grid.

const part = (
    symbol: string,
    x: number,
    y: number,
    text = '',
    on = false,
): Item => {
    const drawn = symbolOf(symbol);

    return {
        ...makeItem('symbol', x, y, { symbol }),
        width: drawn?.width ?? 80,
        height: drawn?.height ?? 40,
        text,
        on,
    };
};

const wire = (from: Item, pin: string, to: Item, other: string): Item => {
    const end = (item: Item, name: string) => {
        const at = pinOf(item, name);

        return {
            item: item.id,
            pin: name,
            side: null,
            x: item.x + (at?.x ?? 0),
            y: item.y + (at?.y ?? 0),
        };
    };

    return {
        ...makeItem('arrow', 0, 0),
        x: 0,
        y: 0,
        stroke: '#0f172a',
        from: end(from, pin),
        to: end(to, other),
        startHead: 'none',
        endHead: 'none',
    };
};

const words = (x: number, y: number, text: string, width = 520): Item => ({
    ...makeItem('text', x, y),
    width,
    height: 60,
    text,
    rich: true,
    align: 'left',
});

/** A circuit written as text, laid out with its top left corner at a point. */
const drawn = (text: string, x: number, y: number): Item[] => {
    const read = parseCircuit(text);

    if (!read.ok) {
        return [];
    }

    const items = layOut(read.circuit);
    const parts = items.filter((item) => item.kind === 'symbol');
    const dx = x - Math.min(...parts.map((item) => item.x));
    const dy = y - Math.min(...parts.map((item) => item.y));
    const moved = <T extends { x: number; y: number }>(at: T): T => ({
        ...at,
        x: at.x + dx,
        y: at.y + dy,
    });

    return items.map((item) =>
        item.kind === 'symbol'
            ? moved(item)
            : {
                  ...item,
                  from: item.from && moved(item.from),
                  to: item.to && moved(item.to),
              },
    );
};

export const logicBoard = (): Item[] => {
    // Three switches into a three-input AND and a three-input OR
    const a = part('input', 0, 100, 'A', true);
    const b = part('input', 0, 180, 'B', true);
    const c = part('input', 0, 260, 'C');
    const and = part('and3', 200, 80, 'AND');
    const or = part('or3', 200, 240, 'OR');
    const all = part('output', 380, 100, 'All three');
    const any = part('output', 380, 260, 'Any');

    // A push-button: on only while it is held. Clocking a flip-flop, each
    // press copies the switch to the lamp, which then remembers it.
    const press = part('press', 0, 500, 'Hold me');
    const held = part('output', 380, 500, 'While held');
    const data = part('input', 0, 580, 'Data', true);
    const flip = part('dff', 200, 580, 'FF1');
    const kept = part('output', 380, 580, 'Kept at each press');

    // Four switches spell a number in binary; the display shows it
    const bits = [1, 2, 4, 8].map((bit, index) =>
        part('input', 0, 820 + index * 60, String(bit), bit === 1 || bit === 4),
    );
    const display = part('bcd7', 200, 840, 'DS1');

    return [
        words(0, 0, '# Three-input gates\nClick A, B and C.'),
        a,
        b,
        c,
        and,
        or,
        all,
        any,
        wire(a, 'y', and, 'a'),
        wire(b, 'y', and, 'b'),
        wire(c, 'y', and, 'c'),
        wire(a, 'y', or, 'a'),
        wire(b, 'y', or, 'b'),
        wire(c, 'y', or, 'c'),
        wire(and, 'y', all, 'a'),
        wire(or, 'y', any, 'a'),

        words(0, 400, '# Push-button\nOn only while it is held down.'),
        press,
        held,
        data,
        flip,
        kept,
        wire(press, 'y', held, 'a'),
        wire(press, 'y', flip, 'CLK'),
        wire(data, 'y', flip, 'D'),
        wire(flip, 'Q', kept, 'a'),

        words(
            0,
            720,
            '# 7-segment display\nSwitches 1, 2, 4, 8: it shows their sum.',
        ),
        ...bits,
        display,
        ...bits.map((bit) => wire(bit, 'y', display, bit.text)),

        words(0, 1080, '# A circuit, worked out\nClick Values, at the top.'),
        // An LED with enough resistance in line with it, and a resistor
        // asked for more than it is good for
        ...drawn(
            'V1: battery 9V\nR1: 470\nD1: led red\nV1 -- R1 -- D1 -- V1',
            0,
            1180,
        ),
        ...drawn('V2: battery 9V\nR2: 100\nV2 -- R2 -- V2', 320, 1180),
    ];
};
