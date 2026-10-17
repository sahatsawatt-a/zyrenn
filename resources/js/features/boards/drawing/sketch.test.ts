import { describe, expect, it, vi } from 'vitest';
import { makeItem } from '../model/items';
import type { Item } from '../model/items';
import { sketchLook } from '../model/sketch';
import type { Figure, Hand } from './sketch';
import { figureOf, opsPath, seedOf, sketchOf } from './sketch';

const at = (kind: Item['kind'], extra: Partial<Item> = {}): Item => ({
    ...makeItem(kind, 0, 0),
    ...extra,
});

const hand = (extra: Partial<Hand> = {}): Hand => ({
    seed: seedOf('i1'),
    level: 1,
    lineWidth: 2,
    filled: true,
    hatch: 'solid',
    ...extra,
});

const box: Figure = { type: 'rect', width: 200, height: 140, radius: 8 };

/** Every point a drawing's line passes, in order. */
const pointsOf = (figure: Figure, with_: Hand) =>
    sketchOf(figure, with_)
        .filter((set) => set.type === 'path')
        .flatMap((set) => set.ops.flatMap((op) => op.data));

describe('a line drawn by hand', () => {
    it('wobbles the same way every time, from a fresh start too', async () => {
        const first = JSON.stringify(sketchOf(box, hand()));

        expect(JSON.stringify(sketchOf({ ...box }, hand()))).toBe(first);

        // Nothing kept from before: a page loaded again draws it the same
        vi.resetModules();
        const again = await import('./sketch');

        expect(JSON.stringify(again.sketchOf(box, hand()))).toBe(first);
    });

    it('takes its seed from the id, never 0, the same on every machine', () => {
        expect(seedOf('i1')).toBe(seedOf('i1'));
        expect(seedOf('i1')).not.toBe(seedOf('i2'));
        expect(seedOf('')).toBeGreaterThan(0);
        expect(seedOf('i1')).toBe(205058226);
    });

    it('wobbles differently for another item, and more for "a lot"', () => {
        const outline = [0, 0, 200, 0, 200, 140, 0, 140];
        const square: Figure = { type: 'polygon', points: outline };
        const drift = (with_: Hand) => {
            const points = pointsOf(square, with_);
            // How far the line strays from the nearest side of the box
            const off = (x: number, y: number) =>
                Math.min(
                    Math.abs(x),
                    Math.abs(x - 200),
                    Math.abs(y),
                    Math.abs(y - 140),
                );
            let total = 0;

            for (let at = 0; at + 1 < points.length; at += 2) {
                total += off(points[at], points[at + 1]);
            }

            return total / (points.length / 2);
        };

        expect(pointsOf(square, hand({ seed: seedOf('i2') }))).not.toEqual(
            pointsOf(square, hand()),
        );
        expect(drift(hand({ level: 2 }))).toBeGreaterThan(drift(hand()));
    });

    it('is gone over twice, with a fill under it unless it is hollow', () => {
        const sets = sketchOf(box, hand());

        expect(sets.map((set) => set.type)).toEqual(['fillPath', 'path']);
        // Two moves: the doubled stroke
        expect(
            sets[1].ops.filter((op) => op.op === 'move').length,
        ).toBeGreaterThanOrEqual(2);
        expect(
            sketchOf(box, hand({ filled: false })).map((set) => set.type),
        ).toEqual(['path']);
        expect(
            sketchOf(box, hand({ lineWidth: 0 })).map((set) => set.type),
        ).toEqual(['fillPath']);
    });

    it('hatches the fill with lines, or crossed lines', () => {
        const lines = sketchOf(box, hand({ hatch: 'hachure' }));
        const crossed = sketchOf(box, hand({ hatch: 'cross' }));

        expect(lines.map((set) => set.type)).toEqual(['fillSketch', 'path']);
        expect(crossed[0].ops.length).toBeGreaterThan(lines[0].ops.length);
    });

    it('reads as path data', () => {
        expect(
            opsPath([
                { op: 'move', data: [1, 2] },
                { op: 'lineTo', data: [3, 4] },
                { op: 'bcurveTo', data: [5, 6, 7, 8, 9, 10] },
            ]),
        ).toBe('M1 2 L3 4 C5 6 7 8 9 10');
    });
});

describe('the outline an item is drawn along', () => {
    it('follows each kind of shape in its own box', () => {
        expect(figureOf(at('rect'))).toEqual({
            type: 'rect',
            width: 200,
            height: 140,
            radius: 8,
        });
        expect(figureOf(at('pill'))).toMatchObject({ radius: 70 });
        expect(figureOf(at('ellipse'))).toMatchObject({
            type: 'ellipse',
            x: 100,
            y: 100,
        });
        expect(figureOf(at('diamond'))).toEqual({
            type: 'polygon',
            points: [100, 0, 200, 70, 100, 140, 0, 70],
        });
        expect(
            (figureOf(at('star')) as { points: number[] }).points,
        ).toHaveLength(20);
    });

    it('scales a path to the box, so the wobble is in board units', () => {
        const figure = figureOf(at('parallelogram', { width: 300 }));

        expect(figure).toEqual({
            type: 'path',
            d: 'M66 0 L300 0 L234 140 L0 140 Z',
        });
    });

    it('leaves a lifeline as it is', () => {
        expect(figureOf(at('lifeline'))).toBeNull();
    });
});

describe('the sketch look', () => {
    const frame = at('frame', { id: 'f', x: 0, y: 0, width: 960, height: 540 });
    const onIt = at('rect', { id: 'r', x: 100, y: 100 });
    const words = at('text', { id: 't', x: 400, y: 100 });
    const away = at('ellipse', { id: 'e', x: 2000, y: 2000 });
    const picture = at('image', { id: 'p', x: 300, y: 300 });
    const items = [frame, onIt, words, away, picture];

    it('sketches a frame and everything on it, and writes them by hand', () => {
        const changes = sketchLook(items, [frame], true);

        expect(Object.fromEntries(changes)).toEqual({
            f: { sketch: 1, fontFamily: 'hand' },
            r: { sketch: 1, fontFamily: 'hand' },
            t: { fontFamily: 'hand' },
            // A picture is not drawn in lines; its caption is in words
            p: { fontFamily: 'hand' },
        });
    });

    it('goes over the whole board when nothing is named', () => {
        expect([...sketchLook(items, null, true).keys()]).toEqual([
            'f',
            'r',
            't',
            'e',
            'p',
        ]);
    });

    it('comes off again, a rougher sketch and a marker with it', () => {
        const sketched = [
            { ...onIt, sketch: 2 as const, fontFamily: 'marker' as const },
            { ...words, fontFamily: 'serif' as const },
        ];

        expect(Object.fromEntries(sketchLook(sketched, null, false))).toEqual({
            r: { sketch: 0, fontFamily: 'sans' },
        });
        // Put on again, a rough one stays as rough
        expect(sketchLook(sketched, null, true).get('r')).toBeUndefined();
    });
});
