import { describe, expect, it } from 'vitest';
import { parsePath, pathString } from './pathData';
import { runsOf, segmentsOf } from './usePen';

describe('a path as points to drag', () => {
    it('comes back as the same path, lines and curves alike', () => {
        const d = 'M0 0 L100 0 C150 0 150 100 100 100 L0 100 Z';

        expect(pathString(segmentsOf(runsOf(parsePath(d))))).toBe(d);
    });

    it('keeps each run of a path apart, and its closing curve', () => {
        const d = 'M0 0 C0 -50 100 -50 100 0 C100 50 0 50 0 0 Z M200 0 L300 0';
        const runs = runsOf(parsePath(d));

        expect(runs.map((run) => [run.anchors.length, run.closed])).toEqual([
            [2, true],
            [2, false],
        ]);
        expect(pathString(segmentsOf(runs))).toBe(d);
    });

    it('reads a quadratic curve as the cubic it is', () => {
        const [run] = runsOf(parsePath('M0 0 Q50 100 100 0'));

        expect(run.anchors[0].outY).toBeCloseTo(66.67, 1);
        expect(run.anchors[1].inY).toBeCloseTo(66.67, 1);
    });
});
