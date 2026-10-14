import { describe, expect, it } from 'vitest';
import { readPath } from '@/features/workflow/edgeHops';
import { bumpAt, bumpsClockwise, lineHops, runsOf } from './lineHops';

const line = (id: string, points: number[], radius = 5) => ({
    id,
    runs: runsOf(points),
    radius,
});

describe('lines that cross', () => {
    it('has the line lying across hop over the one standing up', () => {
        const hops = lineHops([
            line('across', [0, 50, 100, 50]),
            line('down', [50, 0, 50, 100]),
        ]);

        expect([...hops.keys()]).toEqual(['across']);
        expect(hops.get('across')?.get(0)).toEqual([{ x: 50, y: 50 }]);

        // Whichever of the two came first
        const other = lineHops([
            line('down', [50, 0, 50, 100]),
            line('across', [0, 50, 100, 50]),
        ]);

        expect([...other.keys()]).toEqual(['across']);
    });

    it('has the later of two slanting lines hop over the earlier', () => {
        const hops = lineHops([
            line('first', [0, 0, 100, 60]),
            line('second', [0, 60, 100, 0]),
        ]);

        expect([...hops.keys()]).toEqual(['second']);
    });

    it('finds a crossing near the end of a stub the line carries straight on from', () => {
        const hops = lineHops([
            line('down', [40, -50, 40, 50]),
            line('out', [30, 0, 45, 0, 100, 0, 100, 80]),
        ]);

        expect(hops.get('out')?.get(0)).toEqual([{ x: 40, y: 0 }]);
    });

    it('finds the crossing on the right run of an elbow', () => {
        const hops = lineHops([
            line('elbow', [0, 0, 40, 0, 40, 100, 120, 100]),
            line('across', [0, 50, 100, 50]),
        ]);

        expect(hops.get('across')?.get(0)).toEqual([{ x: 40, y: 50 }]);
    });

    it('leaves lines that only meet at an end, or run side by side', () => {
        expect(
            lineHops([
                line('a', [0, 50, 100, 50]),
                line('b', [50, 50, 50, 100]),
                line('c', [0, 52, 100, 52]),
                // Ending right by the other line: a bump would not fit
                line('d', [30, 0, 30, 53]),
            ]).size,
        ).toBe(0);
    });

    it('makes one bump of two crossings too close for two', () => {
        const hops = lineHops([
            line('one', [50, 0, 50, 100]),
            line('two', [54, 0, 54, 100]),
            line('across', [0, 50, 100, 50]),
        ]);

        expect(hops.get('across')?.get(0)).toEqual([{ x: 50, y: 50 }]);
    });

    it('bumps up over a line lying across, left over one standing up, either way along it', () => {
        const run = (x1: number, y1: number, x2: number, y2: number) => ({
            from: { x: x1, y: y1 },
            to: { x: x2, y: y2 },
        });
        // A quarter turn round from where the arc starts is its top
        const top = (r: ReturnType<typeof run>) => {
            const bump = bumpAt(r, { x: 50, y: 50 }, 5);
            const middle =
                bump.start + (bump.clockwise ? 1 : -1) * (Math.PI / 2);

            return {
                x: Math.round(Math.cos(middle)) || 0,
                y: Math.round(Math.sin(middle)) || 0,
            };
        };

        expect(top(run(0, 50, 100, 50))).toEqual({ x: 0, y: -1 });
        expect(top(run(100, 50, 0, 50))).toEqual({ x: 0, y: -1 });
        expect(top(run(50, 0, 50, 100))).toEqual({ x: -1, y: 0 });
        expect(top(run(50, 100, 50, 0))).toEqual({ x: -1, y: 0 });
        expect(bumpsClockwise(run(0, 50, 100, 50))).toBe(true);
    });
});

describe('a workflow line as Vue Flow draws it', () => {
    it('is read as its commands and its straight runs', () => {
        const read = readPath(
            'M0 0L20 0L 95,0Q 100,0 100,5L 100,95Q 100,100 105,100L180 100',
        );

        // The stub out of the step and the line it carries on as are one run
        expect(read.commands.map((command) => command.letter).join('')).toBe(
            'MLQLQL',
        );
        expect(read.runs).toEqual([
            { from: { x: 0, y: 0 }, to: { x: 95, y: 0 } },
            { from: { x: 100, y: 5 }, to: { x: 100, y: 95 } },
            { from: { x: 105, y: 100 }, to: { x: 180, y: 100 } },
        ]);
    });
});
