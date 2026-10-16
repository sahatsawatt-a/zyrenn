import { describe, expect, it } from 'vitest';
import shared from '../../../../../tests/fixtures/rich-text-runs.json';
import { runsOf } from './labels';

const shown = (line: string) =>
    runsOf(line, false).map(({ text, italic, code }) =>
        code ? `[${text}]` : italic ? `/${text}/` : text,
    );

describe('a rich label read as light Markdown', () => {
    // The same cases the server's LabelFit is held to (LabelFitTest.php)
    it.each(shared.cases)(
        'reads $line as the server does',
        ({ line, runs }) => {
            expect(
                runsOf(line, false).map(({ text, bold, code }) => [
                    text,
                    bold,
                    !!code,
                ]),
            ).toEqual(runs);
        },
    );

    it('sets italic words apart, but not an underscore inside a word', () => {
        expect(shown('an _aside_ here, snake_case_name too')).toEqual([
            'an ',
            '/aside/',
            ' here, snake_case_name too',
        ]);
        expect(shown('*see `x_y`*')).toEqual(['/see /', '[x_y]']);
    });
});
