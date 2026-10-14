// A timing diagram in a note: a ```timing block drawn from a row of steps
// for each signal, a mistake in the text said with its line, and a new one
// put in from the slash menu.
import { SHOTS, makeNote, open, runBoard, slash } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

const SPI = `title: Writing over SPI
CS:   10......1
SCK:  0.nnnnnn0
MOSI: x.======x | D5 | D4 | D3
mark: 2 first bit`;

const BROKEN = `CLK: 01q`;

await runBoard(
    '/notes',
    async (board) => {
        const { page, check, soon } = board;
        const base = new URL(page.url()).origin;
        const ref = await makeNote(
            board,
            `Timing ${RUN}`,
            `Before\n\n\`\`\`timing\n${SPI}\n\`\`\`\n\n\`\`\`timing\n${BROKEN}\n\`\`\`\n\nAfter`,
        );

        await open(page, `${base}/notes/${ref}`);
        await page.locator('.timing-svg svg').first().waitFor();

        const drawn = page.locator('.timing-block').first();
        const broken = page.locator('.timing-block').nth(1);
        const words = await drawn
            .locator('.timing-svg svg text')
            .allTextContents();

        check(
            'the signals are drawn, each named, with its values and the mark',
            [
                'Writing over SPI',
                'CS',
                'SCK',
                'MOSI',
                'D5',
                'D3',
                'first bit',
            ].every((word) => words.includes(word)),
            words.join(' | '),
        );
        check(
            'a row to a signal',
            (await drawn
                .locator('.timing-svg svg')
                .getAttribute('data-rows')) === '3',
        );
        check(
            'a mistake in the text is said, with its line',
            /Line 1: “q” is not something a signal can do/.test(
                await broken.locator('[data-test="timing-error"]').innerText(),
            ),
            await broken.locator('[data-test="timing-error"]').innerText(),
        );

        // --- a new one, from the slash menu, starts as an example
        await page.locator('.ProseMirror p', { hasText: 'After' }).click();
        await page.keyboard.press('End');
        await page.keyboard.press('Enter');
        await slash(page, 'timing', 'Timing diagram');
        await soon(
            'the slash menu puts one in, drawn from an example',
            async () =>
                (await page
                    .locator('.timing-block .timing-svg svg')
                    .count()) === 2 &&
                (await page.locator('.timing-block').count()) === 3,
            async () => `${await page.locator('.timing-block').count()} blocks`,
        );

        await page.screenshot({ path: `${SHOTS}/timing-note.png` });
    },
    { canvas: false },
);
