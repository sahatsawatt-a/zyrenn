import { describe, expect, it } from 'vitest';
import { hydrate } from './items';
import type { Item } from './items';
import { itemsOfShape, savedOf } from './myShapes';
import type { MyShape } from './myShapes';

const eye = (id: string, x: number): Item =>
    hydrate({ id, kind: 'ellipse', x, y: 0, width: 40, height: 40 });

const line = (id: string, from: string, to: string): Item =>
    hydrate({
        id,
        kind: 'arrow',
        from: { item: from, side: 'right', x: 40, y: 20 },
        to: { item: to, side: 'left', x: 80, y: 20 },
    });

const shape = (items: Item[]): MyShape => ({
    ref_id: 'abc',
    name: 'Eyes',
    items,
    width: 120,
    height: 40,
    preview: null,
    shared: false,
    can_change: true,
});

describe('itemsOfShape', () => {
    it('makes every item new, one group, the line joined to the copies', () => {
        const placed = itemsOfShape(
            shape([eye('i1', 0), eye('i2', 80), line('i3', 'i1', 'i2')]),
        );

        const ids = placed.map((item) => item.id);
        expect(ids).not.toContain('i1');
        expect(new Set(ids).size).toBe(3);
        expect(new Set(placed.map((item) => item.group)).size).toBe(1);
        expect(placed[0].group).toMatch(/^g/);
        expect([placed[2].from?.item, placed[2].to?.item]).toEqual([
            ids[0],
            ids[1],
        ]);
    });

    it('places a shape twice as two groups', () => {
        const kept = shape([eye('i1', 0), eye('i2', 80)]);

        const first = itemsOfShape(kept);
        const second = itemsOfShape(kept);

        expect(first[0].group).not.toBe(second[0].group);
        expect(first[0].id).not.toBe(second[0].id);
    });

    it('leaves a line to something not in the shape on nothing', () => {
        const placed = itemsOfShape(
            shape([eye('i1', 0), line('i2', 'i1', 'gone')]),
        );

        expect(placed[1].to?.item).toBeNull();
        expect(placed[1].from?.item).toBe(placed[0].id);
    });
});

describe('savedOf', () => {
    it('takes along a line that runs only between what is picked', () => {
        const board = [
            eye('i1', 0),
            eye('i2', 80),
            eye('i4', 200),
            line('i3', 'i1', 'i2'),
            line('i5', 'i2', 'i4'),
        ];

        const saved = savedOf([board[0], board[1]], board);

        expect(saved.map((item) => item.id)).toEqual(['i1', 'i2', 'i3']);
    });
});
