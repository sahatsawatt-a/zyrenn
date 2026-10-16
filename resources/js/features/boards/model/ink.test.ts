import { describe, expect, it } from 'vitest';
import { heaviest, pressedWidths, pressureOf, pressed } from './ink';
import type { Item } from './items';

const stroke = (pressures: number[] | undefined, inkWidth?: number) =>
    ({
        kind: 'draw',
        points: (pressures ?? [0, 0]).flatMap((_, at) => [at * 10, 0]),
        pressures,
        inkWidth,
    }) as unknown as Item;

describe('pressed ink', () => {
    it('is heavier where pressed harder, and as heavy as plain ink at a usual press', () => {
        const widths = pressedWidths(stroke([0.1, 0.1, 0.1, 0.9, 0.9, 0.9]));

        expect(widths).toHaveLength(5);
        expect(widths[0]).toBeLessThan(widths[4]);
        expect(pressedWidths(stroke([0.5, 0.5]))[0]).toBeCloseTo(3);
    });

    it('smooths a single shaky reading rather than beading the line', () => {
        const widths = pressedWidths(stroke([0.5, 0.5, 1, 0.5, 0.5]));

        expect(Math.max(...widths) - Math.min(...widths)).toBeLessThan(1.2);
    });

    it('takes its weight from the stroke, and is reached from its heaviest', () => {
        expect(pressedWidths(stroke([0.5, 0.5], 10))[0]).toBeCloseTo(10);
        expect(heaviest(stroke([0.2, 1], 4))).toBeCloseTo(7);
        expect(heaviest(stroke(undefined, 4))).toBe(4);
    });

    it('is read from a pen alone, and only counts with a pressure for every point', () => {
        expect(
            pressureOf({ pointerType: 'mouse', pressure: 0.5 } as PointerEvent),
        ).toBeNull();
        expect(
            pressureOf({ pointerType: 'pen', pressure: 0 } as PointerEvent),
        ).toBe(0.05);
        expect(
            pressureOf({ pointerType: 'pen', pressure: 0.7 } as PointerEvent),
        ).toBe(0.7);
        expect(pressed(stroke([0.5, 0.5]))).toBe(true);
        expect(
            pressed({
                ...stroke([0.5, 0.5]),
                points: [0, 0, 1, 1, 2, 2],
            } as Item),
        ).toBe(false);
    });
});
