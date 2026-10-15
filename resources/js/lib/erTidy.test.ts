import { describe, expect, it } from 'vitest';
import { clearLabels, crosses, openRows, spreadEnds } from './erTidy';
import type { Box, Point } from './erTidy';

const box = (
    left: number,
    top: number,
    right: number,
    bottom: number,
): Box => ({
    left,
    top,
    right,
    bottom,
});

describe('tidying an ER diagram', () => {
    it('spreads lines meeting one side of a box at one spot, in the order they leave', () => {
        const parent = box(100, 0, 300, 80);
        // Three lines leave its bottom at x 200: to the left, straight down, to the right
        const lines: Point[][] = [
            [
                { x: 200, y: 80 },
                { x: 200, y: 120 },
                { x: 50, y: 120 },
                { x: 50, y: 200 },
            ],
            [
                { x: 200, y: 80 },
                { x: 200, y: 200 },
            ],
            [
                { x: 200, y: 80 },
                { x: 200, y: 120 },
                { x: 400, y: 120 },
                { x: 400, y: 200 },
            ],
        ];

        expect(spreadEnds(lines, [parent])).toBe(true);

        const starts = lines.map((points) => points[0].x);

        expect(starts[0]).toBeLessThan(starts[1]);
        expect(starts[1]).toBeLessThan(starts[2]);
        expect(starts[2] - starts[1]).toBeGreaterThanOrEqual(30);
        // Each still leaves square: its first stretch straight down
        for (const points of lines) {
            expect(points[1].x).toBe(points[0].x);
        }
    });

    it('turns the runs of lines leaving one side at heights of their own', () => {
        const parent = box(100, 0, 300, 80);
        // Two head left, two right, all turning at y 120 as Mermaid drew them
        const lines: Point[][] = [
            [-200, 120],
            [150, 160],
            [250, 180],
            [500, 220],
        ].map(([to, down]) => [
            { x: 200, y: 80 },
            { x: 200, y: 120 },
            { x: to, y: 120 },
            { x: to, y: down },
        ]);

        expect(spreadEnds(lines, [parent])).toBe(true);

        const heights = lines.map((points) => points[1].y);

        // Left-going: the one leaving furthest left turns nearest; so too right
        expect(heights[0]).toBeLessThan(heights[1]);
        expect(heights[3]).toBeLessThan(heights[2]);
        // Clear of the markers, and still square
        expect(Math.min(...heights)).toBeGreaterThanOrEqual(80 + 45);
        for (const points of lines) {
            expect(points[2].y).toBe(points[1].y);
            expect(points[1].x).toBe(points[0].x);
        }
    });

    it('staggers runs that only meet once each is pushed past its marker', () => {
        // As Mermaid drew the comparison table's lines: runs at 116 and 136,
        // both nearer its bottom (96) than a marker needs, so both end at 141
        const comparison = box(551, 12, 674, 96);
        const lines: Point[][] = [
            [
                [582, 116, 222],
                [602, 136, 552],
                [623, 136, 673],
                [643, 116, 893],
            ].map(([x, y, to]) => [
                { x, y: 96 },
                { x, y },
                { x: to, y },
                { x: to, y: 246 },
            ]),
        ].flat();

        expect(spreadEnds(lines, [comparison])).toBe(true);

        const heights = lines.map((points) => points[1].y);

        expect(Math.min(...heights)).toBeGreaterThanOrEqual(96 + 45);
        // The one going furthest left turns nearest, and likewise right
        expect(heights[0]).toBeLessThan(heights[1]);
        expect(heights[3]).toBeLessThan(heights[2]);
    });

    it('leaves lines alone that already meet a side apart', () => {
        const lines: Point[][] = [
            [
                { x: 120, y: 80 },
                { x: 120, y: 200 },
            ],
            [
                { x: 280, y: 80 },
                { x: 280, y: 200 },
            ],
        ];

        expect(spreadEnds(lines, [box(100, 0, 300, 80)])).toBe(false);
    });

    it('opens a gap between rows too close for their lines', () => {
        const moves = openRows([
            box(0, 0, 100, 80),
            box(0, 160, 100, 240),
            box(0, 400, 100, 480),
        ]);

        // 80 apart: opened to 150; the next gap (160) is room enough
        expect(moves).toEqual([{ from: 159.5, by: 70 }]);
    });

    it('slides a label another line runs through along its own line', () => {
        expect(
            crosses({ x: 0, y: 50 }, { x: 100, y: 50 }, box(40, 40, 60, 60)),
        ).toBe(true);
        expect(
            crosses({ x: 0, y: 10 }, { x: 100, y: 10 }, box(40, 40, 60, 60)),
        ).toBe(false);

        const own: Point[] = [
            { x: 50, y: 0 },
            { x: 50, y: 300 },
        ];
        const other: Point[] = [
            { x: 0, y: 150 },
            { x: 100, y: 150 },
        ];
        const [centre] = clearLabels(
            [{ centre: { x: 50, y: 150 }, half: { x: 30, y: 10 }, line: 0 }],
            [own, other],
        );

        expect(centre.x).toBe(50);
        expect(Math.abs(centre.y - 150)).toBeGreaterThan(13);
    });
});
