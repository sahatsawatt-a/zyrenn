import { describe, expect, it } from 'vitest';
import library from '@/features/boards/lib/shapes.json';
import { lookOf, shapeOf } from './shapes';
import { KIT_PARTS, kitDrawing } from './uikit';

type KitShape = {
    kit?: string;
    path: string;
    solid?: string;
    defaults?: { width?: number; height?: number };
};

const kitShapes = Object.entries(
    library.shapes as Record<string, KitShape>,
).filter(([, shape]) => shape.kit);

/** Every number in a path, in the order written. */
const numbers = (path: string) =>
    [...path.matchAll(/-?\d+(\.\d+)?/g)].map((found) => Number(found[0]));

describe('the UI kit', () => {
    it('has a drawing for every part the library lists, and a part for each drawing', () => {
        const byName = (a?: string, b?: string) =>
            (a ?? '').localeCompare(b ?? '');

        expect(kitShapes.map(([, shape]) => shape.kit).sort(byName)).toEqual(
            [...KIT_PARTS].sort(byName),
        );
    });

    it("keeps each part's picture in the library as its drawing at its own size", () => {
        for (const [kind, shape] of kitShapes) {
            const drawing = kitDrawing(
                shape.kit!,
                shape.defaults!.width!,
                shape.defaults!.height!,
            );

            expect(shape.path, kind).toBe(drawing!.path);
            expect(shape.solid, kind).toBe(drawing!.solid);
        }
    });

    it('draws every part inside the box it is fitted to, at any size', () => {
        for (const part of KIT_PARTS) {
            for (const [width, height] of [
                [390, 44],
                [52, 32],
                [24, 24],
                [1200, 600],
            ]) {
                const drawing = kitDrawing(part, width, height)!;

                for (const value of numbers(drawing.path)) {
                    expect(
                        value,
                        `${part} ${width}x${height}`,
                    ).toBeGreaterThanOrEqual(-0.01);
                    expect(
                        value,
                        `${part} ${width}x${height}`,
                    ).toBeLessThanOrEqual(100.01);
                }
            }
        }
    });

    it("keeps a toggle's knob round however long the toggle is drawn", () => {
        for (const [width, height] of [
            [52, 32],
            [120, 32],
        ]) {
            const knob = kitDrawing('toggle', width, height)!.solid!;
            // An arc's two radii, in the box, back in pixels
            const [rx, ry] = numbers(knob.slice(knob.indexOf('A') + 1));

            expect((rx * width) / 100).toBeCloseTo((ry * height) / 100, 1);
            expect((ry * height) / 100).toBeCloseTo(height / 2 - 3, 1);
        }
    });

    it("keeps a button's corners at their size however wide it is", () => {
        for (const width of [120, 358, 900]) {
            const path = kitDrawing('button', width, 48)!.path;
            const [rx, ry] = numbers(path.slice(path.indexOf('A') + 1));

            expect((rx * width) / 100).toBeCloseTo(12, 1);
            expect((ry * 48) / 100).toBeCloseTo(12, 1);
        }
    });

    it('is drawn for its size where an item is drawn, and named with no part for nothing', () => {
        const look = lookOf({ kind: 'toggle', width: 120, height: 32 });

        expect(look.path).toBe(kitDrawing('toggle', 120, 32)!.path);
        expect(look.solidColour).toBe('#ffffff');
        expect(shapeOf('ui_card')?.group).toBe('UI kit');
        expect(kitDrawing('nothing', 10, 10)).toBeNull();
    });
});
