// The labs page: what is being tried out in notes, each with something to
// click -- an answer kept out of sight until its line is clicked, a circuit
// that writes its own voltages and currents, a graph that follows a slider
// and says what the pointer is over -- and a circuit worked out as a table.
import { SHOTS, runBoard } from './harness.mjs';

await runBoard(
    '/demo/labs',
    async ({ page, check, soon }) => {
        const note = page.locator('[data-test="labs-note"]');

        // ---------------------------------------------------------------- reveal
        const reveal = note.locator('[data-test="reveal"]').first();
        const answer = reveal.locator('.reveal-body');

        await reveal.waitFor();
        check('an answer starts out of sight', !(await answer.isVisible()));

        await reveal.locator('[data-test="reveal-toggle"]').click();
        await soon(
            'a click on its line shows it',
            async () =>
                (await answer.isVisible()) &&
                (await answer.innerText()).includes('3 mA'),
        );

        await reveal.locator('[data-test="reveal-toggle"]').click();
        await soon(
            'and another hides it again',
            async () => !(await answer.isVisible()),
        );

        // -------------------------------------------------- a circuit's own values
        const words = () =>
            note.locator('.circuit-svg svg text').allTextContents();

        const values = note.locator('[data-test="circuit-values"]');

        await values.waitFor();
        check(
            'a circuit that keeps its values back starts without them',
            !(await words()).join(' | ').includes('2.5 V') &&
                (await values.getAttribute('aria-pressed')) === 'false',
            (await words()).join(' | '),
        );

        await values.click();
        await soon(
            'Values writes them on the drawing',
            async () => {
                const said = (await words()).join(' | ');

                // Two 10 kΩ across 5 V: half of it in the middle, 250 µA through
                return said.includes('2.5 V') && said.includes('250 µA');
            },
            async () => (await words()).join(' | '),
        );

        await values.click();
        await soon(
            'and again takes them off',
            async () => !(await words()).join(' | ').includes('2.5 V'),
        );

        // --------------------------------------------------------------- a graph
        const graph = note.locator('.plot-block').first();
        const curve = () =>
            graph
                .locator('svg.plot-drawing g[clip-path] path')
                .first()
                .getAttribute('d');

        await graph.locator('svg.plot-drawing').waitFor();

        const before = await curve();
        const legend = await graph
            .locator('svg.plot-drawing text')
            .allTextContents();

        check(
            'a graph draws its curves, named, on its axes',
            (await graph
                .locator('svg.plot-drawing g[clip-path] path')
                .count()) === 2 &&
                ['Vc', 'Vr', 'Time (ms)', 'Voltage (V)'].every((word) =>
                    legend.includes(word),
                ),
            legend.join(' | '),
        );

        const slider = graph.locator('[data-test="plot-slider-R"]');

        await slider.focus();
        await page.keyboard.press('End');
        await soon(
            'sliding a value moves the curve',
            async () => (await curve()) !== before,
        );
        check(
            'and the height of the graph stays where it was',
            (
                await graph.locator('svg.plot-drawing text').allTextContents()
            ).includes('6'),
        );

        const box = await graph.locator('svg.plot-drawing').boundingBox();

        await page.mouse.move(
            box.x + box.width * 0.5,
            box.y + box.height * 0.5,
        );
        await soon(
            'pointing at it says what each curve comes to there',
            async () =>
                /^t = [\d.]+\s+Vc = [\d.]+\s+Vr = [\d.]+$/.test(
                    await graph.locator('.plot-hover-text').textContent(),
                ),
            () => graph.locator('.plot-hover-text').textContent(),
        );

        await graph.locator('[data-test="plot-reset"]').click();
        await soon(
            'Reset puts the values back as written',
            async () => (await curve()) === before,
        );
        await page.screenshot({
            path: `${SHOTS}/labs-note.png`,
            fullPage: true,
        });

        // ------------------------------------------------- a circuit, worked out
        const solver = page.locator('[data-test="labs-solver"]');
        const row = (name) =>
            solver
                .locator('[data-test="labs-answers"] tbody tr', {
                    hasText: name,
                })
                .innerText();

        await solver.scrollIntoViewIfNeeded();
        await soon(
            'two resistors in series share the voltage and carry one current',
            async () => {
                const [r1, r2] = [await row('R1'), await row('R2')];

                return (
                    r1.includes('3 V') &&
                    r1.includes('3 mA') &&
                    r2.includes('6 V') &&
                    r2.includes('3 mA')
                );
            },
            async () => `${await row('R1')} / ${await row('R2')}`,
        );

        await solver.getByRole('button', { name: 'In parallel' }).click();
        await soon(
            'in parallel each takes the whole voltage and its own current',
            async () => {
                const [r1, r2, v1] = [
                    await row('R1'),
                    await row('R2'),
                    await row('V1'),
                ];

                return (
                    r1.includes('9 mA') &&
                    r2.includes('4.5 mA') &&
                    v1.includes('13.5 mA')
                );
            },
            async () =>
                `${await row('R1')} / ${await row('R2')} / ${await row('V1')}`,
        );

        await solver.getByRole('button', { name: 'Too much' }).click();
        await soon(
            'a part asked for more than it is good for is said',
            async () =>
                (
                    await solver
                        .locator('[data-test="labs-warning"]')
                        .allInnerTexts()
                )
                    .join(' ')
                    .includes(
                        'D1 carries 72 mA: more than the 20 mA an ordinary LED is good for.',
                    ),
            () => solver.locator('[data-test="labs-warning"]').allInnerTexts(),
        );

        await solver
            .locator('[data-test="labs-circuit"]')
            .fill('R1: 10k\nR1 -- Q7.b');
        await soon('what cannot be read is said, with its line', async () =>
            /Line 2/.test(
                await solver
                    .locator('[data-test="labs-circuit-error"]')
                    .innerText(),
            ),
        );
    },
    { canvas: false },
);
