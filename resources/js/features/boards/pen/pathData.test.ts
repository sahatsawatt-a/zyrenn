import { describe, expect, it } from 'vitest';
import {
    normalisePath,
    parsePath,
    pathBounds,
    pathString,
    transformPath,
} from './pathData';

describe('path data', () => {
    it('reads relative commands, shorthands and a close as absolute segments', () => {
        expect(pathString(parsePath('m10 10 h20 v20 l-20 0 z'))).toBe(
            'M10 10 L30 10 L30 30 L10 30 Z',
        );
        // A second pair after a move is a line
        expect(pathString(parsePath('M0,0 10,10'))).toBe('M0 0 L10 10');
        // S mirrors the last control point; T the last quadratic one
        expect(
            pathString(parsePath('M0 0 C0 10 10 10 10 0 S20 -10 20 0')),
        ).toBe('M0 0 C0 10 10 10 10 0 C10 -10 20 -10 20 0');
        expect(pathString(parsePath('M0 0 Q5 10 10 0 T20 0'))).toBe(
            'M0 0 Q5 10 10 0 Q15 -10 20 0',
        );
    });

    it('turns an arc into curves that end where it does', () => {
        const segments = parsePath('M0 50 A50 50 0 0 1 100 50');
        const last = segments.at(-1);

        expect(segments.slice(1).every((s) => s.type === 'C')).toBe(true);
        expect(last && 'x' in last ? [last.x, last.y] : []).toEqual([100, 50]);
        // A half circle above the line: its top reaches y = 0
        expect(Math.round(pathBounds(segments).y)).toBe(0);
    });

    it('measures the line, not the handles, and keeps what it could read', () => {
        // Handles far above, the curve itself only halfway up
        const box = pathBounds(parsePath('M0 100 C0 0 100 0 100 100'));

        expect(Math.round(box.y)).toBe(25);
        expect(box.width).toBe(100);
        expect(pathString(parsePath('M0 0 L10 10 L oops'))).toBe('M0 0 L10 10');
    });

    it('moves a path to start at 0,0 and transforms it', () => {
        const { d, box } = normalisePath(parsePath('M100 200 L150 260'));

        expect(d).toBe('M0 0 L50 60');
        expect(box).toEqual({ x: 100, y: 200, width: 50, height: 60 });
        expect(
            pathString(
                transformPath(parsePath('M1 2 L3 4'), [2, 0, 0, 2, 10, 10]),
            ),
        ).toBe('M12 14 L16 18');
    });
});
