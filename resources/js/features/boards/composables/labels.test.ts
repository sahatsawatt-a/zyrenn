import { describe, expect, it } from 'vitest';
import { runsOf } from './labels';

const shown = (line: string) =>
    runsOf(line, false).map(({ text, italic, code }) =>
        code ? `[${text}]` : italic ? `/${text}/` : text,
    );

describe('a rich label read as light Markdown', () => {
    it('keeps an underscore inside a word as it is', () => {
        expect(shown('compare_by handling and ROW_WEBHOOK_URL')).toEqual([
            'compare_by handling and ROW_WEBHOOK_URL',
        ]);
        expect(shown('an _aside_ here, snake_case_name too')).toEqual([
            'an ',
            '/aside/',
            ' here, snake_case_name too',
        ]);
    });

    it('sets `code` apart, marks and all inside it', () => {
        expect(shown('set `ROW_WEBHOOK_URL` and *go*')).toEqual([
            'set ',
            '[ROW_WEBHOOK_URL]',
            ' and ',
            '/go/',
        ]);
    });

    it('reads code inside bold and italic words', () => {
        const runs = runsOf('**`docker compose up`** in `code/`', false);

        expect(
            runs.map(({ text, bold, code }) => [text, bold, !!code]),
        ).toEqual([
            ['docker compose up', true, true],
            [' in ', false, false],
            ['code/', false, true],
        ]);
        expect(shown('*see `x_y`*')).toEqual(['/see /', '[x_y]']);
    });

    it('shows a mark escaped with a backslash as itself, in one word', () => {
        expect(shown('\\_private\\_ and 2 \\* 3 and \\`x\\`')).toEqual([
            '_private_ and 2 * 3 and `x`',
        ]);
    });
});
