// A board shown inside a note: two dropdowns on top, the board below, and a
// reference that survives being saved and reopened.
import { SHOTS, runBoard } from './harness.mjs';

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, at, ready } = b;

        // --- a board worth showing: two frames with something on each
        await page.getByRole('button', { name: /new board/i }).click();
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
        await page.waitForTimeout(600);

        await page.waitForTimeout(2200);
        check(
            'the board saved itself',
            /Saved/i.test(await page.locator('body').innerText()),
        );

        const kinds = await page.evaluate(async () => {
            const ref = location.pathname.split('/').pop();
            const board = await (
                await fetch(`/boards/${ref}/content`, {
                    headers: { Accept: 'application/json' },
                })
            ).json();

            return (board.items ?? []).map((item) => item.kind).join(',');
        });
        check(
            'the board has a line joining two shapes',
            kinds.includes('arrow'),
            kinds,
        );

        // --- a note showing it
        await page.goto(`${APP}/notes`, { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: /new note/i }).click();
        await page.waitForURL(/notes\/\w+/);
        await page.waitForSelector('.tiptap');
        await page.waitForTimeout(700);

        await page.locator('.tiptap').click();
        await page.keyboard.type('/board');
        await page.waitForTimeout(700);
        await page.keyboard.press('Enter');
        await page.waitForTimeout(1200);
        check(
            'the slash menu inserts a board block',
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
        await page.waitForTimeout(2000);
        check(
            'choosing a board draws it in the note',
            (await page.locator('[data-test="board-view"] canvas').count()) > 0,
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

        check(
            'the lines are drawn too, not only the shapes',
            (await drawnIn()).lines > 0,
            JSON.stringify(await drawnIn()),
        );
        await page.screenshot({ path: `${SHOTS}/embed-whole.png` });

        const frames = await page
            .locator('[data-test="frame-choose"] option')
            .allInnerTexts();
        check(
            'the frames of that board are offered',
            frames.length > 1,
            frames.join(' | '),
        );
        await page
            .locator('[data-test="frame-choose"]')
            .selectOption({ index: 1 });
        await page.waitForTimeout(1200);
        check(
            'choosing a frame keeps it drawn',
            (await page.locator('[data-test="board-view"] canvas').count()) > 0,
        );
        check(
            'and keeps the lines on that frame',
            (await drawnIn()).lines > 0,
            JSON.stringify(await drawnIn()),
        );
        await page.screenshot({ path: `${SHOTS}/embed-frame.png` });

        // --- it survives a reload, because it is kept as two lines in the note
        await page.waitForTimeout(2200);
        const noteUrl = page.url();
        await page.goto(noteUrl, { waitUntil: 'networkidle' });
        await page.waitForSelector('.tiptap');
        await page.waitForTimeout(2500);
        check(
            'the block comes back after a reload',
            (await page.locator('[data-test="board-block"]').count()) === 1,
        );
        check(
            'still pointing at the same board',
            (await page.locator('[data-test="board-choose"]').inputValue()) ===
                boardUrl.split('/').pop(),
            await page.locator('[data-test="board-choose"]').inputValue(),
        );
        check(
            'and still drawing it',
            (await page.locator('[data-test="board-view"] canvas').count()) > 0,
        );
        await page.screenshot({ path: `${SHOTS}/embed-reloaded.png` });
    },
    { canvas: false },
);
