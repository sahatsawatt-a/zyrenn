import { describe, expect, it } from 'vitest';
import type { BoardPreview } from './preview';
import { INSET, LEAST, fitBoard, kindLabel, rowsLabel } from './preview';

const preview = (
    aspect: number,
    boxes: Partial<BoardPreview['boxes'][number]>[] = [],
    lines: BoardPreview['lines'] = [],
): BoardPreview => ({
    kind: 'board',
    aspect,
    boxes: boxes.map((box) => ({
        x: 0,
        y: 0,
        w: 1,
        h: 1,
        fill: '#ffffff',
        stroke: '#334155',
        kind: 'rect',
        ...box,
    })),
    lines,
});

describe('a board fitted to its card', () => {
    it('fills the card top to bottom when the drawing is narrower than it', () => {
        // A 16:9 frame in a 280 x 84 card: as tall as the room allows, centred
        const { boxes } = fitBoard(preview(16 / 9, [{}]), 280, 84);
        const [box] = boxes;

        expect(box.height).toBeCloseTo(84 - INSET * 2);
        expect(box.width).toBeCloseTo((84 - INSET * 2) * (16 / 9));
        expect(box.x + box.width / 2).toBeCloseTo(140);
        expect(box.y).toBeCloseTo(INSET);
    });

    it('fills it side to side when the drawing is wider', () => {
        const { boxes } = fitBoard(preview(10, [{}]), 280, 84);
        const [box] = boxes;

        expect(box.width).toBeCloseTo(280 - INSET * 2);
        expect(box.height).toBeCloseTo((280 - INSET * 2) / 10);
        expect(box.y + box.height / 2).toBeCloseTo(42);
    });

    it('places each box where it lies on the drawing', () => {
        const { boxes } = fitBoard(
            preview(2, [{ x: 0.5, y: 0.5, w: 0.25, h: 0.25 }]),
            280,
            84,
        );
        // 68 tall and 136 wide, from x 72 and y 8
        const [box] = boxes;

        expect(box.x).toBeCloseTo(72 + 68);
        expect(box.y).toBeCloseTo(8 + 34);
        expect(box.width).toBeCloseTo(34);
        expect(box.height).toBeCloseTo(17);
    });

    it('never draws a box too small to see', () => {
        const { boxes } = fitBoard(
            preview(1, [{ w: 0.001, h: 0.001 }]),
            280,
            84,
        );

        expect(boxes[0].width).toBe(LEAST);
        expect(boxes[0].height).toBe(LEAST);
    });

    it('draws words as a bar, a picture as shade, and no colour as none', () => {
        const { boxes } = fitBoard(
            preview(1, [
                { kind: 'text', h: 0.5 },
                { kind: 'image' },
                { fill: 'transparent', stroke: 'transparent' },
            ]),
            280,
            84,
        );

        expect(boxes.map((box) => box.look)).toEqual([
            'words',
            'picture',
            'shape',
        ]);
        // A bar thinner than the words' box, across its middle
        expect(boxes[0].height).toBeLessThan((84 - INSET * 2) / 2);
        expect(boxes[2].fill).toBe('');
        expect(boxes[2].stroke).toBe('');
    });

    it('draws each line between the same points as its boxes', () => {
        const { lines } = fitBoard(preview(1, [], [[0, 0, 1, 1]]), 100, 100);

        expect(lines).toEqual([[INSET, INSET, 100 - INSET, 100 - INSET]]);
    });

    it('copes with a drawing of no shape at all', () => {
        expect(() => fitBoard(preview(0, [{}]), 0, 0)).not.toThrow();
    });
});

describe('what a card says', () => {
    it('names the kind and counts rows', () => {
        expect(kindLabel('board')).toBe('Board');
        expect(rowsLabel(1)).toBe('1 row');
        expect(rowsLabel(9)).toBe('9 rows');
    });
});
