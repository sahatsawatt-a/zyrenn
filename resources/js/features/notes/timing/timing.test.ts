import { describe, expect, it } from 'vitest';
import { timingExamples } from './examples';
import { parseTiming, renderTiming } from './timing';

const signal = (source: string, row = 0) => {
    const read = parseTiming(source);

    if (!read.ok) {
        throw new Error(read.error);
    }

    return read.timing.rows[row]!;
};

describe('a timing diagram read from text', () => {
    it('reads a step to a character, with a dot for more of the same', () => {
        expect(signal('EN: 01..0').runs).toEqual([
            { kind: 'low', from: 0, to: 1 },
            { kind: 'high', from: 1, to: 4 },
            { kind: 'low', from: 4, to: 5 },
        ]);
    });

    it('joins the same level written twice, and ignores spaces', () => {
        expect(signal('EN: 00 11 0').runs).toEqual([
            { kind: 'low', from: 0, to: 2 },
            { kind: 'high', from: 2, to: 4 },
            { kind: 'low', from: 4, to: 5 },
        ]);
    });

    it('names each value in the order they are given', () => {
        expect(signal('D: x=.=x | A | B').runs).toEqual([
            { kind: 'unknown', from: 0, to: 1 },
            { kind: 'value', from: 1, to: 3, label: 'A' },
            { kind: 'value', from: 3, to: 4, label: 'B' },
            { kind: 'unknown', from: 4, to: 5 },
        ]);
    });

    it('keeps a title, marks and a gap between groups', () => {
        const read = parseTiming(
            'title: Handshake\nREQ: 010\n\nACK: 001\nmark: 1 asks, 2.5 answers',
        );

        expect(read.ok && read.timing.title).toBe('Handshake');
        expect(
            read.ok && read.timing.rows.map((row) => row?.name ?? null),
        ).toEqual(['REQ', null, 'ACK']);
        expect(read.ok && read.timing.marks).toEqual([
            { at: 1, label: 'asks' },
            { at: 2.5, label: 'answers' },
        ]);
        expect(read.ok && read.timing.steps).toBe(3);
    });

    it('says which line it could not read, and why', () => {
        expect(parseTiming('CLK: 01q')).toEqual({
            ok: false,
            error: expect.stringMatching(/^Line 1: “q” is not something/),
        });
        expect(parseTiming('CLK: 01\nnonsense')).toEqual({
            ok: false,
            error: expect.stringMatching(/^Line 2: write a signal/),
        });
        expect(parseTiming('CLK: .1')).toEqual({
            ok: false,
            error: expect.stringMatching(/cannot start with/),
        });
        expect(parseTiming('# only a comment')).toEqual({
            ok: false,
            error: expect.stringMatching(/^Nothing to draw yet/),
        });
    });
});

describe('a timing diagram drawn', () => {
    it('draws every example, a row to a signal', () => {
        for (const example of timingExamples) {
            const drawn = renderTiming(example.source);

            expect(drawn.ok, example.label).toBe(true);

            const read = parseTiming(example.source);
            const rows = read.ok ? read.timing.rows.filter(Boolean).length : 0;

            expect(drawn.ok && drawn.svg).toContain(`data-rows="${rows}"`);
            expect(drawn.ok && drawn.svg).not.toContain('NaN');
        }
    });

    it("writes names safely, and cuts a value's name to its box", () => {
        const drawn = renderTiming('A<b>: 0=1 | a very long name indeed');

        expect(drawn.ok && drawn.svg).toContain('A&lt;b&gt;');
        expect(drawn.ok && drawn.svg).not.toContain('a very long name indeed');
        expect(drawn.ok && drawn.svg).toContain('…');
    });

    it('is drawn in the text colour, or on paper of its own for a file', () => {
        const inNote = renderTiming('CLK: pp');
        const asFile = renderTiming('CLK: pp', {
            ink: '#0f172a',
            paper: '#ffffff',
        });

        expect(inNote.ok && inNote.svg).not.toContain('<rect');
        expect(asFile.ok && asFile.svg).toContain('color="#0f172a"');
        expect(asFile.ok && asFile.svg).toContain('fill="#ffffff"');
    });
});
