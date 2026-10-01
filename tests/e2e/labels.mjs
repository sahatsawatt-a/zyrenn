// What a board's words can be made to look like -- headings and bullets in
// one card, a typeface, a size, room round them -- and what a board can be
// turned into: a picture that fills its box its own way, a full-screen show,
// a PDF and a PNG.
import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { SHOTS, removeAt, runBoard } from './harness.mjs';

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';
const SAMPLE = `${SHOTS}/labels-sample.png`;

await mkdir(SHOTS, { recursive: true });
// Colour bars, so there is a picture to see fitted (needs ffmpeg)
execFileSync('ffmpeg', [
    ...['-loglevel', 'error', '-y'],
    ...['-f', 'lavfi', '-i', 'testsrc2=size=320x160', '-frames:v', '1'],
    SAMPLE,
]);

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, ready, draw, screenOf, afterwards } = b;

        await page.getByRole('button', { name: /new board/i }).click();
        await page.waitForURL(/boards\/\w+/, { timeout: 30000 });
        const ref = page.url().split('/').pop();
        afterwards(() => removeAt(page, `/boards/${ref}`));
        await ready();

        /** The board as the app has saved it, by item id. */
        const saved = async () => {
            await page.waitForTimeout(2200);

            return page.evaluate(async (ref) => {
                const board = await (
                    await fetch(`/boards/${ref}/content`, {
                        headers: { Accept: 'application/json' },
                    })
                ).json();

                return Object.fromEntries(
                    (board.items ?? []).map((item) => [item.id, item]),
                );
            }, ref);
        };

        // A slide to hold it all, and a card on it
        await draw('frame', 0.05, 0.05, 0.6, 0.8);
        const card = await draw('rect', 0.1, 0.15, 0.25, 0.3);
        const middle = await screenOf(card.x + card.w / 2, card.y + card.h / 2);

        // --- a heading and its points in one card
        await page.mouse.dblclick(middle.x, middle.y);
        await page.waitForTimeout(300);
        for (const [index, line] of [
            '# Why it matters',
            '- **First** point',
            '- Second point',
        ].entries()) {
            if (index) {
                await page.keyboard.press('Shift+Enter');
            }
            await page.keyboard.type(line);
        }
        await page.keyboard.press('Enter');
        await page.waitForTimeout(300);

        await page.mouse.click(middle.x, middle.y);
        await page.waitForTimeout(300);
        await page.locator('[data-test="prop-rich"]').check();
        await page.waitForTimeout(400);
        check(
            'Markdown makes the label a heading with points under it',
            await page.evaluate(
                () => window.Konva.stages[0].find('.rich-label').length === 1,
            ),
        );

        // --- its face, its size and the room round it
        await page.locator('[data-test="font-serif"]').click();
        await page.locator('[data-test="prop-font-size"]').fill('20');
        await page.locator('[data-test="prop-font-size"]').press('Enter');
        await page.locator('[data-test="prop-padding"]').fill('28');
        await page.locator('[data-test="prop-padding"]').press('Enter');

        const rect = Object.values(await saved()).find(
            (item) => item.kind === 'rect',
        );
        check(
            'the card keeps what it was given',
            rect?.rich === true &&
                rect.fontFamily === 'serif' &&
                rect.fontSize === 20 &&
                rect.padding === 28 &&
                rect.text.startsWith('# Why it matters\n- **First**'),
            JSON.stringify({
                rich: rect?.rich,
                font: rect?.fontFamily,
                size: rect?.fontSize,
                padding: rect?.padding,
            }),
        );
        await page.screenshot({ path: `${SHOTS}/labels-rich.png` });

        // --- a picture, fitted inside its box rather than stretched
        await page.keyboard.press('Escape');
        await page.locator('[data-test="open-image-picker"]').click();
        await page.locator('input[type="file"]').setInputFiles(SAMPLE);
        await page.waitForTimeout(2500);
        await page.locator('[data-test="fit-contain"]').click();
        const picture = Object.values(await saved()).find(
            (item) => item.kind === 'image',
        );
        check(
            'a picture can be fitted inside its box',
            picture?.fit === 'contain',
        );

        // --- the show takes the whole screen, and Esc ends it
        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: /present/i }).click();
        await page.waitForTimeout(1200);
        check(
            'presenting fills the screen',
            await page.evaluate(() => !!document.fullscreenElement),
        );
        await page.keyboard.press('Escape');
        await page.waitForTimeout(800);
        check(
            'and Esc ends the show',
            (await page.locator('[data-test="exit-present"]').count()) === 0,
        );

        // --- a PDF, a frame to a page, and a PNG of it all
        for (const [test, ending] of [
            ['board-export-pdf', '.pdf'],
            ['board-export-png', '.png'],
        ]) {
            await page.locator('[data-test="board-export"]').click();
            const download = page.waitForEvent('download', { timeout: 60000 });
            await page.locator(`[data-test="${test}"]`).click();
            const file = await download;
            const path = `${SHOTS}/labels-export${ending}`;
            await file.saveAs(path);
            const head = (await import('node:fs'))
                .readFileSync(path)
                .subarray(0, 4)
                .toString('latin1');
            check(
                `the board downloads as a ${ending.slice(1).toUpperCase()}`,
                file.suggestedFilename().endsWith(ending) &&
                    (ending === '.pdf' ? head === '%PDF' : head === '\x89PNG'),
                file.suggestedFilename(),
            );
        }
    },
    { canvas: false },
);
