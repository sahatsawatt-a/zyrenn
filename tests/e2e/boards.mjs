// A board of one's own: made from the list, saved as it is drawn, and still
// there on the way back.
import { SHOTS, open, reopen, runBoard, saved, until } from './harness.mjs';

// Named for this run, so a board left behind by an earlier one is never
// mistaken for this one
const TITLE = `Launch plan ${Date.now().toString().slice(-6)}`;

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, at, layerNames, ready } = b;

        await page
            .getByRole('button', { name: /new board/i })
            .first()
            .click();
        await page.waitForURL(
            /boards\/\w+/,
            { timeout: 30000 },
            { canvas: false },
        );
        await ready();
        check(
            'a new board opens on the canvas',
            (await page.locator('canvas').count()) > 0,
        );

        await page.locator('[data-test="board-title"]').fill(TITLE);
        await page.waitForTimeout(300);

        await page.locator('[data-test="tool-sticky"]').click();
        await page.mouse.move(at(0.3, 0.4).x, at(0.3, 0.4).y);
        await page.mouse.down();
        await page.mouse.move(
            at(0.45, 0.62).x,
            at(0.45, 0.62).y,
            { steps: 8 },
            { canvas: false },
        );
        await page.mouse.up();
        await page.waitForTimeout(400);

        if (await page.locator('[data-test="text-editor"]').count()) {
            await page.keyboard.press('Control+a');
            await page.keyboard.type('Pick the launch date');
            await page.keyboard.press('Enter');
        }

        await saved(page);
        const status = await page.locator('body').innerText();
        check(
            'it says it saved itself',
            /Saved/i.test(status),
            (status.match(/Saved[^\n·]*/) ?? ['(none)'])[0].slice(0, 30),
        );

        const url = page.url();
        await open(page, url);
        await page.waitForSelector('canvas');
        await soon(
            'the title survives a reload',
            async () =>
                (await page
                    .locator('[data-test="board-title"]')
                    .inputValue()) === TITLE,
        );
        await soon(
            'and so does what was drawn',
            async () =>
                (await layerNames()).some((name) =>
                    name.includes('Pick the launch date'),
                ),
            async () => (await layerNames()).slice(0, 2).join(' | '),
        );
        await page.screenshot(
            { path: `${SHOTS}/boards-reloaded.png` },
            { canvas: false },
        );

        await page
            .locator('[data-sidebar="menu-button"]')
            .filter({ hasText: 'Boards' })
            .first()
            .click();
        await page.waitForURL(/boards$/);
        // The list reads what the app has kept, a moment after the board
        // said it was saved: it is opened again until the board is on it
        let listed;
        await until(
            async () => {
                listed = await page.locator('main').innerText();

                if (listed.includes(TITLE)) {
                    return true;
                }

                await reopen(page);

                return false;
            },
            { timeout: 15000, every: 400 },
        );
        check('the list shows the board', listed.includes(TITLE), TITLE);
        check(
            'and says how much is on it',
            /\d+ item/.test(listed),
            (listed.match(/\d+ items?/) ?? [''])[0],
        );

        // tidy up after ourselves
        await page.getByRole('link', { name: TITLE }).first().click();
        await ready();
        await page.locator('[data-test="delete-board"]').click();
        await page.getByRole('button', { name: /delete board/i }).click();
        await page.waitForURL(/boards$/, { timeout: 15000 });
        await soon(
            'and it can be deleted again',
            async () =>
                !(await page.locator('main').innerText()).includes(TITLE),
        );
    },
    { canvas: false },
);
