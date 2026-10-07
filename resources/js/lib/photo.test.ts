import { describe, expect, it } from 'vitest';
import {
    adjustPixels,
    dragCrop,
    flip,
    fractionRatio,
    fullEdit,
    hidePixels,
    isUnedited,
    largestCrop,
    LOOKS,
    lookLight,
    newHidden,
    pictureFrame,
    outputSize,
    outputType,
    saveScale,
    straightenScale,
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

describe('hiding an area', () => {
    // A 40×40 checkerboard of black and white single pixels
    const board = () => {
        const data = new Uint8ClampedArray(40 * 40 * 4);

        for (let i = 0; i < 40 * 40; i++) {
            const on = ((i % 40) + Math.floor(i / 40)) % 2 ? 255 : 0;
            data.set([on, on, on, 255], i * 4);
        }

        return { data, width: 40, height: 40 };
    };
    const at = (pixels: { data: Uint8ClampedArray }, x: number, y: number) =>
        pixels.data[(y * 40 + x) * 4];
    const middle = { x: 0.25, y: 0.25, width: 0.5, height: 0.5 };

    it('blacks a box out, and leaves what is round it', () => {
        const done = hidePixels(board(), [
            { ...middle, style: 'fill', shape: 'rect' },
        ]);

        expect([at(done, 20, 20), at(done, 21, 20)]).toEqual([24, 24]);
        expect([at(done, 0, 0), at(done, 1, 0)]).toEqual([0, 255]);
    });

    it('pixelates into even squares, and blurs to an even grey', () => {
        const coarse = hidePixels(board(), [
            { ...middle, style: 'pixelate', shape: 'rect' },
        ]);
        const soft = hidePixels(board(), [
            { ...middle, style: 'blur', shape: 'rect' },
        ]);

        // Squares of 3 (a 40th of 40, at least 3) from the area's corner
        expect(at(coarse, 10, 10)).toBe(at(coarse, 11, 11));
        expect(Math.abs(at(soft, 20, 20) - 127)).toBeLessThan(20);
        expect(Math.abs(at(soft, 21, 20) - 127)).toBeLessThan(20);
        expect(at(soft, 9, 9)).toBe(255 * (18 % 2));
    });

    it('is drawn where it lies on the picture, when the pixels are of the crop', () => {
        const edit = {
            ...UNEDITED,
            crop: { x: 0.5, y: 0, width: 0.5, height: 1 },
        };
        const frame = pictureFrame({ width: 80, height: 40 }, edit, 1);

        // The right half of an 80-wide picture: the area at x 0.75 is at 20
        expect(frame).toEqual({ x: -40, y: -0, width: 80, height: 40 });
        const done = hidePixels(
            board(),
            [
                {
                    x: 0.75,
                    y: 0,
                    width: 0.05,
                    height: 0.1,
                    style: 'fill',
                    shape: 'rect',
                },
            ],
            frame,
        );
        expect([at(done, 20, 0), at(done, 19, 0)]).toEqual([24, 255]);
    });

    it('hides only inside its shape: an oval leaves the corners of its box', () => {
        const done = hidePixels(board(), [
            { ...middle, style: 'fill', shape: 'circle' },
        ]);

        // The middle of the box is blacked out; its corner is as it was
        expect(at(done, 20, 20)).toBe(24);
        expect([at(done, 10, 10), at(done, 11, 10)]).toEqual([0, 255]);
        expect(
            fullEdit({ hidden: [{ ...middle, style: 'blur' }] } as never)
                .hidden[0].shape,
        ).toBe('rect');
    });

    it('goes round and over with the picture, and counts as an edit', () => {
        const area = newHidden({ x: 0, y: 0, width: 1, height: 1 }, 'blur');
        const edit = { ...UNEDITED, hidden: [{ ...area, x: 0, y: 0 }] };

        close(turn(edit, true).hidden[0] as never, {
            x: 1 - area.height,
            y: 0,
            width: area.height,
            height: area.width,
        });
        close(flip(edit, 'y').hidden[0] as never, { y: 1 - area.height });
        expect(isUnedited(edit)).toBe(false);
        expect(fullEdit({ rotate: 90 }).hidden).toEqual([]);
    });
});

describe('straightening, shape and size', () => {
    it('grows a straightened picture just enough to fill its frame', () => {
        expect(straightenScale({ width: 100, height: 100 }, 45)).toBeCloseTo(
            Math.SQRT2,
        );
        expect(straightenScale({ width: 200, height: 100 }, 0)).toBe(1);
        // A wide picture tilted needs more than a square one
        expect(
            straightenScale({ width: 200, height: 100 }, 10),
        ).toBeGreaterThan(straightenScale({ width: 100, height: 100 }, 10));
    });

    it('keeps the tilt as it turns, and leans the other way when flipped', () => {
        const tilted = { ...UNEDITED, angle: 5 };

        expect(turn(tilted, true).angle).toBe(5);
        expect(flip(tilted, 'x').angle).toBe(-5);
    });

    it('saves a shape as PNG, to see through round it', () => {
        expect(outputType('image/jpeg')).toBe('image/jpeg');
        expect(outputType('image/jpeg', 'circle')).toBe('image/png');
        expect(outputType('image/webp', 'rounded')).toBe('image/webp');
    });

    it('saves smaller when asked, and never larger', () => {
        const big = { width: 4000, height: 2000 };

        expect(
            saveScale(big, { ...UNEDITED, maxSide: 1600 }, 'image/jpeg'),
        ).toBe(0.4);
        expect(
            saveScale(
                { width: 400, height: 200 },
                { ...UNEDITED, maxSide: 1600 },
                'image/png',
            ),
        ).toBe(1);
        expect(saveScale(big, UNEDITED, 'image/jpeg')).toBe(1);
    });
});
