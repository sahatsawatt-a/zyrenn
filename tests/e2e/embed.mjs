// A board shown inside a note: two dropdowns on top, the board below, and a
// reference that survives being saved and reopened.
import {
    SHOTS,
    boardKept,
    open,
    runBoard,
    saved,
    slash,
    steady,
    until,
} from './harness.mjs';

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, at, ready } = b;

        // --- a board worth showing: two frames with something on each
        await page
            .getByRole('button', { name: /new board/i })
            .first()
            .click();
        await page.waitForURL(/boards\/\w+/, { timeout: 30000 });
        await ready();
        const boardUrl = page.url();
        const title = `Embedded ${Date.now().toString().slice(-6)}`;
        await page.locator('[data-test="board-title"]').fill(title);

        await page.locator('[data-test="tool-frame"]').click();
        await page.mouse.move(at(0.12, 0.2).x, at(0.12, 0.2).y);
        await page.mouse.down();
        await page.mouse.move(at(0.45, 0.6).x, at(0.45, 0.6).y, { steps: 8 });
        await page.mouse.up();
        await page.waitForTimeout(400);
        await page.locator('[data-test="tool-sticky"]').click();
        await page.mouse.move(at(0.18, 0.28).x, at(0.18, 0.28).y);
        await page.mouse.down();
        await page.mouse.move(at(0.3, 0.45).x, at(0.3, 0.45).y, { steps: 6 });
        await page.mouse.up();
        await page.waitForTimeout(400);

        if (await page.locator('[data-test="text-editor"]').count()) {
            await page.keyboard.press('Control+a');
            await page.keyboard.type('Seen from a note');
            await page.keyboard.press('Enter');
        }

        // a second shape, joined to the first: a line has no box of its own and
        // is the thing most easily lost when a board is drawn somewhere else
        await b.pick('rect');
        await page.mouse.move(at(0.32, 0.26).x, at(0.32, 0.26).y);
        await page.mouse.down();
        await page.mouse.move(at(0.42, 0.42).x, at(0.42, 0.42).y, { steps: 6 });
        await page.mouse.up();
        await page.waitForTimeout(400);
        await page.keyboard.press('Escape');

        await page.locator('[data-test="tool-arrow"]').click();
        await page.mouse.move(at(0.24, 0.36).x, at(0.24, 0.36).y);
        await page.mouse.down();
        await page.mouse.move(at(0.3, 0.35).x, at(0.3, 0.35).y, { steps: 6 });
        await page.mouse.move(at(0.37, 0.34).x, at(0.37, 0.34).y, { steps: 6 });
        await page.mouse.up();

        await saved(page);
        check(
            'the board saved itself',
            /Saved/i.test(await page.locator('body').innerText()),
        );

        // (kept by the app a moment later: the note draws it from there)
        let kinds;
        await soon(
            'the board has a line joining two shapes',
            async () => {
                kinds = Object.values(
                    await boardKept(page, boardUrl.split('/').pop()),
                )
                    .map((item) => item.kind)
                    .join(',');

                return kinds.includes('arrow');
            },
            () => kinds,
        );

        // --- a note showing it
        await open(page, `${APP}/notes`);
        await page.getByRole('button', { name: /new note/i }).click();
        await page.waitForURL(/notes\/\w+/);
        await page.waitForSelector('.tiptap');

        await page.locator('.tiptap').click();
        await slash(page, 'board');
        await soon(
            'the slash menu inserts a board block',
            async () =>
                (await page.locator('[data-test="board-block"]').count()) === 1,
        );
        check(
            'with a dropdown for the board',
            (await page.locator('[data-test="board-choose"]').count()) === 1,
        );
        check(
            'and one for the frame',
            (await page.locator('[data-test="frame-choose"]').count()) === 1,
        );

        await page
            .locator('[data-test="board-choose"]')
            .selectOption({ label: title });
        await soon(
            'choosing a board draws it in the note',
            async () =>
                (await page
                    .locator('[data-test="board-view"] canvas')
                    .count()) > 0,
        );

        // what is drawn in the note's own stage, by kind
        const drawnIn = () =>
            page.evaluate(() => {
                const stage =
                    window.Konva.stages[window.Konva.stages.length - 1];
                return {
                    shapes: stage.find('Rect').length,
                    lines: stage
                        .find('Line')
                        .filter((l) => !l.closed() && l.points().length >= 4)
                        .length,
                };
            });

        await soon(
            'the lines are drawn too, not only the shapes',
            async () => (await drawnIn()).lines > 0,
            async () => JSON.stringify(await drawnIn()),
        );
        await page.screenshot({ path: `${SHOTS}/embed-whole.png` });

        const options = () =>
            page.locator('[data-test="frame-choose"] option').allInnerTexts();
        await until(async () => (await options()).length > 1);
        const frames = await options();
        check(
            'the frames of that board are offered',
            frames.length > 1,
            frames.join(' | '),
        );
        await page
            .locator('[data-test="frame-choose"]')
            .selectOption({ index: 1 });
        // (drawn again, as that frame alone)
        await steady(drawnIn);
        check(
            'choosing a frame keeps it drawn',
            (await page.locator('[data-test="board-view"] canvas').count()) > 0,
        );
        await soon(
            'and keeps the lines on that frame',
            async () => (await drawnIn()).lines > 0,
            async () => JSON.stringify(await drawnIn()),
        );
        await page.screenshot({ path: `${SHOTS}/embed-frame.png` });

        // --- it survives a reload, because it is kept as two lines in the note
        await saved(page);
        const noteUrl = page.url();
        await open(page, noteUrl);
        await page.waitForSelector('.tiptap');
        await soon(
            'the block comes back after a reload',
            async () =>
                (await page.locator('[data-test="board-block"]').count()) === 1,
        );
        await soon(
            'still pointing at the same board',
            async () =>
                (await page
                    .locator('[data-test="board-choose"]')
                    .inputValue()) === boardUrl.split('/').pop(),
            () => page.locator('[data-test="board-choose"]').inputValue(),
        );
        await soon(
            'and still drawing it',
            async () =>
                (await page
                    .locator('[data-test="board-view"] canvas')
                    .count()) > 0,
        );
        await page.screenshot({ path: `${SHOTS}/embed-reloaded.png` });
    },
    { canvas: false },
);
