// A circuit in a note: a ```circuit block drawn from its text with a board's
// own symbols, a mistake in the text said with its line, an example picked
// over what was written only after a yes, and the drawing kept as a picture.
import { readFile } from 'node:fs/promises';
import { SHOTS, makeNote, runBoard } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

const DIVIDER = `title Divider
V1: 12V
R1: 10k
R2: 4k7
OUT: terminal
V1 -- R1 -- out
out -- R2 -- gnd
out -- OUT
V1.- -- gnd`;

const BROKEN = `R1: 10k
R1 -- Q7.b`;

await runBoard(
    '/notes',
    async (board) => {
        const { page, check } = board;
        const base = new URL(page.url()).origin;
        const ref = await makeNote(
            board,
            `Circuit ${RUN}`,
            `Before\n\n\`\`\`circuit\n${DIVIDER}\n\`\`\`\n\n\`\`\`circuit\n${BROKEN}\n\`\`\`\n\nAfter`,
        );

        await page.goto(`${base}/notes/${ref}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.circuit-svg svg', { timeout: 15000 });

        const drawn = page.locator('.circuit-block').first();
        const broken = page.locator('.circuit-block').nth(1);
        const words = await drawn
            .locator('.circuit-svg svg text')
            .allTextContents();

        check(
            'the circuit is drawn, with each part named and valued',
            [
                'R1',
                '10 kΩ',
                'R2',
                '4.7 kΩ',
                'V1',
                '12 V',
                'OUT',
                'Divider',
            ].every((word) => words.includes(word)),
            words.join(' | '),
        );
        check(
            'with its symbols, wires and a junction dot',
            (await drawn.locator('.circuit-svg svg path').count()) >= 6 &&
                (await drawn.locator('.circuit-svg svg polyline').count()) >= 4,
            `${await drawn.locator('.circuit-svg svg path').count()} paths, ${await drawn.locator('.circuit-svg svg polyline').count()} wires`,
        );
        check(
            'a mistake in the text is said, with its line',
            /Line 2: Q7 is not a part/.test(
                await broken.locator('[data-test="circuit-error"]').innerText(),
            ),
            await broken.locator('[data-test="circuit-error"]').innerText(),
        );

        await page.screenshot({ path: `${SHOTS}/circuit-note.png` });

        // --- drawn in the note's own ink, so it follows the dark theme
        const inkOf = () =>
            drawn
                .locator('.circuit-svg svg polyline')
                .first()
                .evaluate((line) => getComputedStyle(line).stroke);
        const light = await inkOf();
        await page.evaluate(() =>
            document.documentElement.classList.add('dark'),
        );
        await page.waitForTimeout(300);
        const dark = await inkOf();
        check(
            'it follows the dark theme',
            light !== dark,
            `${light} → ${dark}`,
        );
        await page.screenshot({ path: `${SHOTS}/circuit-note-dark.png` });
        await page.evaluate(() =>
            document.documentElement.classList.remove('dark'),
        );

        // --- an example over what was written: asked first, in the app
        await drawn.locator('.diagram-type-select').click();
        await page.locator('[role="option"][data-value="led"]').click();
        const ask = page.getByRole('dialog');
        await ask.waitFor();
        check(
            'picking an example over written text asks first',
            /Replace with/.test(await ask.innerText()),
        );
        await ask.getByRole('button', { name: 'Cancel' }).click();
        await ask.waitFor({ state: 'detached' });
        check(
            'and Cancel leaves it be',
            (
                await drawn.locator('.circuit-svg svg text').allTextContents()
            ).includes('R2'),
        );

        await drawn.locator('.diagram-type-select').click();
        await page.locator('[role="option"][data-value="led"]').click();
        await page.locator('[data-test="diagram-replace"]').click();
        await page.waitForFunction(
            () =>
                [
                    ...document.querySelectorAll(
                        '.circuit-block .circuit-svg svg text',
                    ),
                ].some((text) => text.textContent === 'D1'),
            null,
            { timeout: 8000 },
        );
        check('a yes draws the example', true);

        // --- kept as a picture: light on white, whatever the app is in
        await page.keyboard.press('Escape');
        await drawn.getByRole('button', { name: 'Expand' }).click();
        await page.locator('[data-test="viewer-save"]').click();
        await page.locator('[data-test="viewer-save-type-png"]').click();
        const [file] = await Promise.all([
            page.waitForEvent('download'),
            page.locator('[data-test="viewer-save-download"]').click(),
        ]);
        const path = `${SHOTS}/${file.suggestedFilename()}`;
        await file.saveAs(path);
        const bytes = await readFile(path);

        check(
            'the drawing downloads as a PNG named for the example',
            file.suggestedFilename() === 'LED and resistor.png' &&
                bytes.subarray(1, 4).toString() === 'PNG' &&
                bytes.length > 3000,
            `${file.suggestedFilename()}, ${bytes.length} bytes`,
        );
    },
    { canvas: false },
);
