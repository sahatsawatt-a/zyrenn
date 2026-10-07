import { describe, expect, it } from 'vitest';
import {
    adjustPixels,
    dragCrop,
    flip,
    fractionRatio,
    fullEdit,
    isUnedited,
    largestCrop,
    LOOKS,
    lookLight,
    outputSize,
    turn,
    UNEDITED,
} from './photo';
import type { PhotoEdit } from './photo';

const cropped: PhotoEdit = {
    ...UNEDITED,
    crop: { x: 0.1, y: 0.2, width: 0.3, height: 0.4 },
};

const close = (actual: Record<string, number>, expected: typeof actual) =>
    Object.entries(expected).forEach(([key, value]) =>
        expect(actual[key]).toBeCloseTo(value),
    );

describe('turning and flipping', () => {
    it('takes the crop round with the picture', () => {
        const turned = turn(cropped, true);

        expect(turned.rotate).toBe(90);
        // The top-left corner of the crop goes to the top-right
        close(turned.crop as never, {
            x: 0.4,
            y: 0.1,
            width: 0.4,
            height: 0.3,
        });
        close(turn(turned, false).crop as never, cropped.crop as never);
    });

    it('comes back to where it began after four quarter turns', () => {
        const edit = [1, 2, 3, 4].reduce((each) => turn(each, true), cropped);

        expect(edit.rotate).toBe(0);
        close(edit.crop as never, cropped.crop as never);
    });

    it('swaps the flips as it turns, a flip turned being the other flip', () => {
        const turned = turn({ ...cropped, flipX: true }, true);

        expect([turned.flipX, turned.flipY]).toEqual([false, true]);
    });

    it('mirrors the crop with the picture', () => {
        close(flip(cropped, 'x').crop as never, { x: 0.6, y: 0.2 });
        close(flip(cropped, 'y').crop as never, { x: 0.1, y: 0.4 });
        const back = flip(flip(cropped, 'x'), 'x');

        expect(back.flipX).toBe(false);
        close(back.crop as never, cropped.crop as never);
    });
});

describe('the crop box', () => {
    it('moves, staying on the picture', () => {
        close(dragCrop(cropped.crop, 'move', 0.9, -0.9) as never, {
            x: 0.7,
            y: 0,
            width: 0.3,
            height: 0.4,
        });
    });

    it('is resized from the side dragged, no further than the edge', () => {
        close(dragCrop(cropped.crop, 'e', 0.1, 0) as never, {
            x: 0.1,
            width: 0.4,
        });
        close(dragCrop(cropped.crop, 'nw', -0.5, -0.5) as never, {
            x: 0,
            y: 0,
            width: 0.4,
            height: 0.6,
        });
    });

    it('keeps its shape when a ratio is set, and stays on the picture', () => {
        const square = { x: 0.2, y: 0.2, width: 0.2, height: 0.2 };
        const grown = dragCrop(square, 'se', 0.5, 0.1, 1);

        expect(grown.width).toBeCloseTo(grown.height);
        expect(grown.x + grown.width).toBeLessThanOrEqual(1 + 1e-9);
        expect(grown.y + grown.height).toBeLessThanOrEqual(1 + 1e-9);
        close(grown as never, { x: 0.2, y: 0.2 });
    });

    it('is the largest of a ratio there is room for, in the middle', () => {
        // 16:9 on a 4:3 picture: the whole width, part of the height
        const crop = largestCrop(
            fractionRatio(16 / 9, { width: 400, height: 300 }),
        );

        close(crop as never, { x: 0, width: 1, height: 0.75, y: 0.125 });
        expect(
            outputSize({ width: 400, height: 300 }, { ...UNEDITED, crop }),
        ).toEqual({
            width: 400,
            height: 225,
        });
    });
});

describe('light and colour', () => {
    const pixels = (...values: number[]) => ({
        data: new Uint8ClampedArray(values),
        width: values.length / 4,
        height: 1,
    });

    it('leaves the pixels alone when nothing is changed', () => {
        expect(
            Array.from(adjustPixels(pixels(10, 20, 30, 255), UNEDITED).data),
        ).toEqual([10, 20, 30, 255]);
        expect(isUnedited(UNEDITED)).toBe(true);
        expect(isUnedited(cropped)).toBe(false);
    });

    it('brightens, and takes the colour out', () => {
        expect(
            Array.from(
                adjustPixels(pixels(100, 50, 0, 255), {
                    ...UNEDITED,
                    brightness: 200,
                }).data,
            ),
        ).toEqual([200, 100, 0, 255]);

        const [r, g, b, a] = adjustPixels(pixels(200, 40, 10, 128), {
            ...UNEDITED,
            saturation: 0,
        }).data;

        expect(r).toBe(g);
        expect(g).toBe(b);
        expect(a).toBe(128);
    });

    it('warms towards red and cools towards blue', () => {
        const [r, , b] = adjustPixels(pixels(100, 100, 100, 255), {
            ...UNEDITED,
            warmth: 100,
        }).data;

        expect([r > 100, b < 100]).toEqual([true, true]);
    });

    it('darkens the corners of the frame, not its middle', () => {
        // A row of grey: the ends are the frame's edges, the middle its centre
        const row = adjustPixels(
            {
                data: new Uint8ClampedArray(Array(9 * 4).fill(200)),
                width: 9,
                height: 1,
            },
            { ...UNEDITED, vignette: 100 },
            { x: 0, y: -4, width: 9, height: 9 },
        ).data;

        expect(row[4 * 4]).toBe(200);
        expect(row[0]).toBeLessThan(row[4 * 4]);
    });

    it('fills out an edit kept before warmth and vignette were there', () => {
        const kept = fullEdit({ rotate: 90, crop: { x: 0.5 } } as never);

        expect(kept.warmth).toBe(0);
        expect(kept.crop).toEqual({ x: 0.5, y: 0, width: 1, height: 1 });
        expect(lookLight(LOOKS[1].light).saturation).toBe(0);
    });
});
