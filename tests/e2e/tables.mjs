// A table of one's own: made from the list, given columns and rows, typed
// into, and all of it still there on the way back.
import { SHOTS, runBoard } from './harness.mjs';

// Named for this run, so a table left behind by an earlier one is never
// mistaken for this one
const TITLE = `Budget ${Date.now().toString().slice(-6)}`;

await runBoard(
    '/tables',
    async ({ page, check }) => {
        const rows = page.locator('[data-test="table-row"]');
        const cell = (row, column) =>
            rows.nth(row).locator(`[data-column="${column}"] input`).first();
        const headers = async () =>
            (
                await page
                    .locator('[data-test="column-header"]')
                    .allInnerTexts()
            ).map((text) => text.trim());

        const addColumn = async (label, type) => {
            await page.locator('[data-test="add-column"]').first().click();
            await page.locator('[data-test="column-label"]').fill(label);
            await page.locator(`[data-test="column-type-${type}"]`).click();
            await page.locator('[data-test="column-save"]').click();
            await page.waitForTimeout(600);
        };

        const saved = async () => {
            await page.waitForTimeout(1600);

            return (
                await page.locator('[data-test="table-status"]').innerText()
            ).trim();
        };

        await page.getByRole('button', { name: /new table/i }).click();
        await page.waitForURL(/tables\/\w+/, { timeout: 30000 });
        await page.waitForSelector('[data-test="table-workspace"]');
        check('a new table opens', true);

        await page.locator('[data-test="table-title"]').fill(TITLE);

        await addColumn('Owner', 'varchar');
        await addColumn('Budget', 'integer');
        check(
            'columns are added after the id',
            (await headers()).join('|').match(/ID.*Owner.*Budget/i) !== null,
            (await headers()).join(' | ') || '(no headers)',
        );

        await page.locator('[data-test="add-row"]').first().click();
        await page.waitForTimeout(500);
        await page.locator('[data-test="add-row"]').first().click();
        await page.waitForTimeout(500);
        check('two rows are added', (await rows.count()) === 2);

        await cell(0, 'owner').fill('Ada');
        await cell(1, 'owner').fill('Grace');
        await cell(0, 'budget').fill('300');
        await cell(0, 'budget').press('Tab');
        await cell(1, 'budget').fill('120');
        await cell(1, 'budget').press('Tab');

        const status = await saved();
        check('it says it saved itself', status.startsWith('Saved'), status);

        await page.locator('[data-test="table-search"]').fill('Grace');
        await page.waitForTimeout(300);
        check('search narrows the rows', (await rows.count()) === 1);
        await page.locator('[data-test="table-search"]').fill('');
        await page.waitForTimeout(300);

        await page
            .locator('[data-test="column-header"][data-column="budget"]')
            .hover();
        await page
            .locator(
                '[data-test="column-header"][data-column="budget"] [data-test="column-menu"]',
            )
            .click();
        await page.getByText(/sort ascending/i).click();
        await page.waitForTimeout(300);
        check(
            'sorting puts the smaller budget first',
            (await cell(0, 'budget').inputValue()) === '120',
            await cell(0, 'budget').inputValue(),
        );

        await page.screenshot({ path: `${SHOTS}/tables-filled.png` });

        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('[data-test="table-workspace"]');
        check(
            'the title survives a reload',
            (await page.locator('[data-test="table-title"]').inputValue()) ===
                TITLE,
        );
        check(
            'and so do the columns',
            (await headers()).join('|').match(/Owner.*Budget/i) !== null,
        );
        check(
            'and what was typed',
            (await cell(0, 'owner').inputValue()) === 'Ada' &&
                (await cell(1, 'budget').inputValue()) === '120',
            `${await cell(0, 'owner').inputValue()} / ${await cell(1, 'budget').inputValue()}`,
        );

        await rows.nth(0).hover();
        await rows.nth(0).locator('[data-test="select-row"]').click();
        await page.locator('[data-test="bulk-delete"]').click();
        await page.waitForTimeout(800);
        check('a selected row can be deleted', (await rows.count()) === 1);

        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('[data-test="table-workspace"]');
        check(
            'and stays deleted',
            (await rows.count()) === 1 &&
                (await cell(0, 'owner').inputValue()) === 'Grace',
        );

        await page.locator('[data-test="delete-table"]').click();
        await page.getByRole('button', { name: /^delete table$/i }).click();
        await page.waitForURL(/\/tables(\?|$)/);
        await page.waitForTimeout(500);
        check(
            'deleting it takes it off the list',
            !(await page.locator('body').innerText()).includes(TITLE),
        );
    },
    { canvas: false },
);
