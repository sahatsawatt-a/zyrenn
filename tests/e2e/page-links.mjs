// Links from a note to another page by its name: [[Title]] written in
// Markdown is a chip that opens the page, typing [[ offers the pages there
// are, and the page linked to lists the notes that link to it.
import { SHOTS, makeNote, runBoard } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

await runBoard(
    '/notes',
    async (board) => {
        const { page, check } = board;
        const base = new URL(page.url()).origin;

        const budget = await makeNote(board, `Budget ${RUN}`, 'The numbers.');
        const plan = await makeNote(
            board,
            `Plan ${RUN}`,
            `Costs are in [[Budget ${RUN}]], and [[Nowhere ${RUN}]] is not written yet.\n\nSecond paragraph.`,
        );

        await page.goto(`${base}/notes/${plan}`, { waitUntil: 'networkidle' });

        const chips = page.locator('[data-test="page-link"]');
        await chips.first().waitFor();

        check(
            'a [[Title]] is a link showing the page by its name',
            (await chips.first().innerText()).trim() === `Budget ${RUN}`,
            (await chips.first().innerText()).trim(),
        );
        await page
            .locator('[data-test="page-link"].is-missing')
            .waitFor({ timeout: 8000 });
        check(
            'a name nothing goes by is drawn as missing',
            (await chips.nth(1).getAttribute('class')).includes('is-missing') &&
                !(await chips.first().getAttribute('class')).includes(
                    'is-missing',
                ),
        );

        // ----------------------------------------------- Typing [[ offers pages
        await page.locator('.tiptap p').last().click();
        await page.keyboard.press('End');
        await page.keyboard.type(` See also [[Budget ${RUN.slice(0, 3)}`);

        const menu = page.locator('[data-test="page-link-menu"]');
        await menu.waitFor();
        await page.waitForFunction(
            (title) =>
                [
                    ...document.querySelectorAll(
                        '[data-test="page-link-menu"] .item-title',
                    ),
                ].some((item) => item.textContent.trim() === title),
            `Budget ${RUN}`,
        );
        check('typing [[ offers the pages there are, by name', true);
        await page.screenshot({ path: `${SHOTS}/page-link-menu.png` });

        await page.keyboard.press('Enter');
        await page.waitForFunction(
            () =>
                document.querySelectorAll(
                    '[data-test="page-link"]:not(.is-missing)',
                ).length === 2,
        );
        check('and Enter puts the link in', (await chips.count()) === 3);
        check(
            'with nothing of what was typed left behind',
            !(await page.locator('.tiptap').innerText()).includes('[['),
            await page.locator('.tiptap p').last().innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/page-links.png` });

        // Saved before leaving
        await page.waitForTimeout(1800);

        // ------------------------------------------------ A click opens the page
        await chips.first().click();
        await page.waitForURL(`**/notes/${budget}`);
        check('a click on a link opens the page', true, page.url());

        const linked = page.locator('[data-test="note-backlinks"]');
        await linked.waitFor();
        check(
            'and that page lists the notes that link to it',
            (await linked.innerText()).includes(`Plan ${RUN}`),
            (await linked.innerText()).replace(/\s+/g, ' '),
        );
        await page.screenshot({ path: `${SHOTS}/page-links-backlinks.png` });

        await linked.getByText(`Plan ${RUN}`).click();
        await page.waitForURL(`**/notes/${plan}`);
        check('which is the way back', true);

        // The typed link was kept: two links to the one page, still one backlink
        await page.locator('[data-test="page-link"]').nth(2).waitFor();
        check(
            'the link typed in was saved',
            (await page.locator('[data-test="page-link"]').count()) === 3,
        );
    },
    { canvas: false },
);
