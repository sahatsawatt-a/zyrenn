// A note's history: pin the note as it is, edit on, and bring the pin back.
import { runBoard } from './harness.mjs';

const TITLE = `History ${Date.now().toString().slice(-6)}`;

await runBoard(
    '/notes',
    async ({ page, check }) => {
        await page.getByRole('button', { name: /new note/i }).first().click();
        await page.waitForURL(/notes\/\w+/, { timeout: 30000 });
        await page.getByLabel('Note title').fill(TITLE);
        await page.waitForTimeout(1600);

        await page.locator('[data-test="note-history"]').click();
        await page.getByPlaceholder('Label (optional)').fill('First draft');
        await page.locator('[data-test="note-pin-current"]').click();
        await page.waitForSelector('[data-test="note-version"]');
        check(
            'pinning lists the version under its label',
            (await page.locator('[data-test="note-version"]').first().innerText()).includes('First draft'),
        );
        await page.keyboard.press('Escape');

        await page.getByLabel('Note title').fill(`${TITLE} edited`);
        await page.waitForTimeout(1600);

        await page.locator('[data-test="note-history"]').click();
        await page.locator('[data-test="note-version"]', { hasText: 'First draft' }).locator('[data-test="note-version-restore"]').click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        check(
            'restoring brings the pinned title back',
            (await page.getByLabel('Note title').inputValue()) === TITLE,
            await page.getByLabel('Note title').inputValue(),
        );

        await page.locator('[data-test="note-history"]').click();
        await page.waitForSelector('[data-test="note-version"]');
        const first = page.locator('[data-test="note-version"]').first();
        check('the pin is listed first', (await first.innerText()).includes('First draft'));
        await first.locator('[data-test="note-version-pin"]').click();
        await page.waitForTimeout(500);
        check(
            'unpinning drops the label',
            !(await page.locator('[data-test="note-version"]').allInnerTexts()).join().includes('First draft'),
        );
    },    { canvas: false },
);
