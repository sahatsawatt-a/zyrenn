import { describe, expect, it } from 'vitest';
import { strokesTouched } from './erase';
import type { Item } from './items';

const stroke = (id: string, points: number[], extra: Partial<Item> = {}) =>
    ({
        id,
        kind: 'draw',
        x: 100,
        y: 100,
        width: 0,
        height: 0,
        points,
        ...extra,
    }) as Item;

describe('the eraser', () => {
    const line = stroke('line', [0, 0, 100, 0]);

    it('takes a pencil stroke it passes over, and not one it misses', () => {
        expect(
            strokesTouched([line], { x: 150, y: 95 }, { x: 150, y: 95 }, 8),
        ).toEqual(['line']);
        expect(
            strokesTouched([line], { x: 150, y: 120 }, { x: 150, y: 120 }, 8),
        ).toEqual([]);
    });

    it('catches a stroke crossed by a quick sweep, between the points it was heard at', () => {
        expect(
            strokesTouched([line], { x: 150, y: 60 }, { x: 150, y: 140 }, 8),
        ).toEqual(['line']);
    });

    it('follows a stroke that has been turned', () => {
        // Turned a quarter: it now runs down from 100,100 to 100,200
        const turned = stroke('turned', [0, 0, 100, 0], { rotation: 90 });

        expect(
            strokesTouched([turned], { x: 102, y: 150 }, { x: 102, y: 150 }, 8),
        ).toEqual(['turned']);
        expect(
            strokesTouched([turned], { x: 150, y: 102 }, { x: 150, y: 102 }, 8),
        ).toEqual([]);
    });

    it('never takes anything but pencil ink, nor a locked or hidden stroke', () => {
        const others = [
            { ...line, id: 'shape', kind: 'rect' },
            { ...line, id: 'pen', kind: 'path' },
            { ...line, id: 'arrow', kind: 'arrow' },
            { ...line, id: 'locked', locked: true },
            { ...line, id: 'hidden', hidden: true },
        ] as Item[];

        expect(
            strokesTouched(others, { x: 150, y: 100 }, { x: 150, y: 100 }, 8),
        ).toEqual([]);
    });
});
