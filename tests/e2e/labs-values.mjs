// A circuit on a board, worked out: Values writes the voltages and currents
// on the drawing and takes them off again, marks a part asked for more than
// it is good for, and the Circuit dialog has them as a table.
import { inksIn } from './circuit-parts.mjs';
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/labs/board', async ({ page, check, soon, screenOf }) => {
    const inks = inksIn(page, screenOf);
    // The two circuits to work out, at the foot of the board: an LED with
    // enough in line with it, and a resistor with too much across it
    const sound = () => inks(-80, 1160, 300, 1420);
    const strained = () => inks(300, 1160, 560, 1420);
    const values = page.locator('[data-test="board-values"]');

    await values.waitFor();
    check(
        'a board starts with nothing worked out on it',
        !(await sound()).worked &&
            !(await strained()).over &&
            (await values.getAttribute('aria-pressed')) === 'false',
    );

    await values.click();
    await soon(
        'Values writes what was worked out on the drawing',
        async () => (await sound()).worked,
    );
    check('with nothing amiss where nothing is', !(await sound()).over);
    check(
        'and in red where a part is asked for too much',
        (await strained()).over,
    );
    await page.screenshot({ path: `${SHOTS}/labs-values.png` });

    // ------------------------------------------------------------ as a table
    await page.locator('[data-test="board-circuit"]').click();

    const dialog = page.locator('[data-test="circuit-reading"]');

    await dialog.locator('[data-test="circuit-tab-values"]').click();

    const row = (name) =>
        dialog
            .locator('[data-test="circuit-values"] tr', { hasText: name })
            .first()
            .innerText();

    await soon(
        'the Circuit dialog has the same as a table',
        async () => {
            const [r1, d1] = [await row('R1'), await row('D1')];

            return (
                r1.includes('7.2 V') &&
                r1.includes('15.3 mA') &&
                d1.includes('1.8 V')
            );
        },
        async () => `${await row('R1')} / ${await row('D1')}`,
    );
    check(
        'and says what is over its rating',
        (
            await dialog.locator('[data-test="circuit-warnings"]').innerText()
        ).includes(
            'R2 turns 810 mW to heat: more than the 250 mW an ordinary resistor is good for.',
        ),
    );

    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'detached' });

    // ---------------------------------------------------------------- hidden
    await values.click();
    await soon(
        'Values again takes them off',
        async () => !(await sound()).worked && !(await strained()).over,
    );
});
