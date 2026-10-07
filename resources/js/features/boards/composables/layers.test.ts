import { describe, expect, it } from 'vitest';
import type { Item, ItemKind } from './items';
import { makeItem } from './items';
import { keepOnFrames } from './layers';

const at = (id: string, kind: ItemKind, x: number, y: number): Item => ({
    ...makeItem(kind, x, y),
    id,
});

const ids = (items: Item[]) => items.map((item) => item.id);

// Two slides side by side: A at the origin, B to its right
const frameA = () => at('A', 'frame', 0, 0);
const frameB = () => at('B', 'frame', 1200, 0);

describe('keepOnFrames', () => {
    it('leaves a stack with nothing buried as it is', () => {
        const items = [frameA(), at('s', 'sticky', 100, 100), frameB()];

        expect(keepOnFrames(items)).toBe(items);
    });

    it('brings up what a frame was drawn over, keeping its order', () => {
        const items = [
            at('s1', 'sticky', 1300, 100),
            at('s2', 'rect', 1500, 100),
            at('loose', 'rect', -900, 0),
            frameA(),
            frameB(),
        ];

        expect(ids(keepOnFrames(items))).toEqual([
            'loose',
            'A',
            'B',
            's1',
            's2',
        ]);
    });

    it('lifts a shape sent to the back to the bottom of its own frame', () => {
        const items = [
            at('sent', 'rect', 1300, 100),
            frameA(),
            frameB(),
            at('other', 'rect', 1500, 100),
        ];

        expect(ids(keepOnFrames(items))).toEqual(['A', 'B', 'sent', 'other']);
    });

    it('puts one dropped on another frame on top of what is there', () => {
        // Made first, on A, and then dragged over to B
        const items = [
            frameA(),
            at('moved', 'sticky', 1300, 100),
            frameB(),
            at('there', 'rect', 1500, 100),
            at('elsewhere', 'rect', 100, 100),
        ];

        expect(ids(keepOnFrames(items, ['moved']))).toEqual([
            'A',
            'B',
            'there',
            'moved',
            'elsewhere',
        ]);
    });

    it('keeps several dropped together in their order', () => {
        const items = [
            at('one', 'rect', 1300, 100),
            at('two', 'rect', 1400, 100),
            frameA(),
            frameB(),
            at('there', 'rect', 1500, 100),
        ];

        expect(ids(keepOnFrames(items, ['one', 'two']))).toEqual([
            'A',
            'B',
            'there',
            'one',
            'two',
        ]);
    });

    it('leaves one dropped on empty board where it was', () => {
        const items = [frameA(), at('out', 'rect', -900, 0), frameB()];

        expect(keepOnFrames(items, ['out'])).toBe(items);
    });

    it('keeps a connector over the frame its shapes are on', () => {
        const from = at('p', 'rect', 1300, 100);
        const to = at('q', 'rect', 1600, 100);
        const line: Item = {
            ...at('line', 'arrow', 0, 0),
            from: { item: 'p', side: null, x: 0, y: 0 },
            to: { item: 'q', side: null, x: 0, y: 0 },
        };

        expect(ids(keepOnFrames([line, frameB(), from, to]))).toEqual([
            'B',
            'line',
            'p',
            'q',
        ]);
    });
});
