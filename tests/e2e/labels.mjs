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

        // --- a second frame, so there are pictures to be had one by one
        await page.locator('[data-test="panel-toggle"]').click();
        await draw('frame', 0.68, 0.1, 0.25, 0.3);
        await page.locator('[data-test="panel-toggle"]').click();

        // --- a PDF, a frame to a page, and a picture of each frame -- each
        // shown first, and the very file shown is the one handed over
        const fs = await import('node:fs');
        const headOf = async (download, path) => {
            await download.saveAs(path);

            return fs.readFileSync(path).subarray(0, 4).toString('latin1');
        };

        // Nothing is drawn until it is chosen, and nothing twice
        const drawn = [];
        page.on('request', (request) => {
            const at = new URL(request.url()).pathname;

            if (/\/boards\/\w+\/(pdf|png)$/.test(at)) {
                drawn.push(at.split('/').pop());
            }
        });

        await page.locator('[data-test="board-export"]').click();
        await page.locator('[data-test="pdf-preview-choice"]').waitFor();
        await page.waitForTimeout(800);
        check(
            'export asks what to make before making anything',
            drawn.length === 0,
            drawn.join(', ') || 'nothing drawn',
        );
        await page.locator('[data-test="pdf-preview-choose-pdf"]').click();
        await page
            .locator('[data-test="pdf-preview-frame"]')
            .waitFor({ timeout: 60000 });
        check('the PDF is previewed before it is had', true);
        let download = page.waitForEvent('download', { timeout: 60000 });
        await page.locator('[data-test="pdf-preview-download"]').click();
        let file = await download;
        check(
            'the board downloads as a PDF',
            file.suggestedFilename().endsWith('.pdf') &&
                (await headOf(file, `${SHOTS}/labels-export.pdf`)) === '%PDF',
            file.suggestedFilename(),
        );

        await page.locator('[data-test="pdf-preview-style-png"]').click();
        await page
            .locator('[data-test="pdf-preview-gallery"]')
            .waitFor({ timeout: 90000 });
        const pictures = page.locator('[data-test="pdf-preview-image"]');
        check(
            'pictures come a frame at a time',
            (await pictures.count()) === 2,
            `${await pictures.count()} pictures`,
        );
        const sizes = await pictures.evaluateAll((images) =>
            images.map(
                (image) => `${image.naturalWidth}x${image.naturalHeight}`,
            ),
        );
        check(
            'each the shape of its own frame',
            sizes.every((size) => size.startsWith('1600x')) &&
                sizes[0] !== sizes[1],
            sizes.join(', '),
        );
        await page.screenshot({ path: `${SHOTS}/board-export-preview.png` });

        const before = drawn.length;
        await page.locator('[data-test="pdf-preview-style-pdf"]').click();
        await page.locator('[data-test="pdf-preview-frame"]').waitFor();
        await page.locator('[data-test="pdf-preview-style-png"]').click();
        await page.locator('[data-test="pdf-preview-gallery"]').waitFor();
        check(
            'going back to a format shows it as it was drawn',
            drawn.length === before,
            `${drawn.length - before} drawn again`,
        );

        download = page.waitForEvent('download', { timeout: 60000 });
        await page
            .locator('[data-test="pdf-preview-download-one"]')
            .nth(1)
            .click();
        file = await download;
        check(
            'one frame downloads on its own, numbered',
            file.suggestedFilename().endsWith(' - 2. Frame.png') &&
                (await headOf(file, `${SHOTS}/labels-export.png`)) ===
                    '\x89PNG',
            file.suggestedFilename(),
        );

        const all = [];
        page.on('download', (each) => all.push(each.suggestedFilename()));
        await page.locator('[data-test="pdf-preview-download"]').click();
        await page.waitForTimeout(2500);
        check(
            'and Download all hands over every one',
            all.length === 2,
            all.join(', '),
        );
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        // --- a frame, right-clicked, saved as a picture of its own
        const box = await page.locator('[data-zoom]').boundingBox();
        const onFrame = {
            x: box.x + box.width * 0.3,
            y: box.y + box.height * 0.6,
        };
        await page.mouse.click(onFrame.x, onFrame.y, { button: 'right' });
        const menu = page.locator('[data-test="frame-menu"]');
        await menu.waitFor({ timeout: 5000 });
        check(
            'right-clicking a frame offers its picture',
            await menu.isVisible(),
        );

        download = page.waitForEvent('download', { timeout: 60000 });
        await page.locator('[data-test="frame-download"]').click();
        file = await download;
        check(
            'and Download picture saves just that frame',
            file.suggestedFilename().endsWith(' - Frame.png') &&
                (await headOf(file, `${SHOTS}/labels-frame.png`)) === '\x89PNG',
            file.suggestedFilename(),
        );

        await page
            .context()
            .grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.mouse.click(onFrame.x, onFrame.y, { button: 'right' });
        await menu.waitFor();
        await page.locator('[data-test="frame-copy"]').click();
        await page
            .getByText(/Copied “Frame” as a picture/)
            .waitFor({ timeout: 60000 });
        const copied = await page.evaluate(async () => {
            const [entry] = await navigator.clipboard.read();

            return entry.types.join(',');
        });
        check(
            'Copy picture puts a PNG on the clipboard',
            copied.includes('image/png'),
            copied,
        );

        // Off the frames it is the browser's own menu, not this one: just
        // left of the leftmost frame, clear of the tool rail
        const frameLeft = await page.evaluate(() =>
            Math.min(
                ...window.Konva.stages[0]
                    .find('Group')
                    .filter((group) => group.findOne('.frame-title'))
                    .map((group) => group.getClientRect().x),
            ),
        );
        await page.locator('[data-test="zoom-out"]').click();
        await page.locator('[data-test="zoom-out"]').click();
        await page.waitForTimeout(300);
        const leftmost = await page.evaluate(() =>
            Math.min(
                ...window.Konva.stages[0]
                    .find('Group')
                    .filter((group) => group.findOne('.frame-title'))
                    .map((group) => group.getClientRect().x),
            ),
        );
        await page.mouse.click(
            box.x + leftmost - 30,
            box.y + box.height * 0.5,
            {
                button: 'right',
            },
        );
        await page.waitForTimeout(400);
        check(
            'off the frames, there is no frame menu',
            !(await menu.isVisible()),
            `clicked ${Math.round(leftmost - 30)}px in, frames from ${Math.round(frameLeft)} → ${Math.round(leftmost)}`,
        );
        await page.keyboard.press('Escape');
    },
    { canvas: false },
);
