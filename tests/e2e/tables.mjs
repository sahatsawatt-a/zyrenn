// A table of one's own: made from the list, given columns and rows, typed
// into, and all of it still there on the way back.
import {
    SHOTS,
    reopen,
    runBoard,
    saved as pageSaved,
    until,
} from './harness.mjs';

// Named for this run, so a table left behind by an earlier one is never
// mistaken for this one
const TITLE = `Budget ${Date.now().toString().slice(-6)}`;

await runBoard(
    '/tables',
    async ({ page, check, soon, problems }) => {
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
            await page
                .locator(
                    `[data-test="column-header"]:has-text(${JSON.stringify(label)})`,
                )
                .waitFor();
        };

        /** What the page says once it has saved what was just done. */
        const saved = async () => {
            await pageSaved(page).catch(() => {});

            return (
                await page.locator('[data-test="table-status"]').innerText()
            ).trim();
        };

        await page
            .getByRole('button', { name: /new table/i })
            .first()
            .click();
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
        await until(async () => (await rows.count()) === 1);
        await page.locator('[data-test="add-row"]').first().click();
        await soon(
            'two rows are added',
            async () => (await rows.count()) === 2,
        );

        await cell(0, 'owner').fill('Ada');
        await cell(1, 'owner').fill('Grace');
        await cell(0, 'budget').fill('300');
        await cell(0, 'budget').press('Tab');
        await cell(1, 'budget').fill('120');
        await cell(1, 'budget').press('Tab');

        const status = await saved();
        check('it says it saved itself', status.startsWith('Saved'), status);

        await page.locator('[data-test="table-search"]').fill('Grace');
        await soon(
            'search narrows the rows',
            async () => (await rows.count()) === 1,
        );
        await page.locator('[data-test="table-search"]').fill('');
        await until(async () => (await rows.count()) === 2);

        await page
            .locator('[data-test="column-header"][data-column="budget"]')
            .hover();
        await page
            .locator(
                '[data-test="column-header"][data-column="budget"] [data-test="column-menu"]',
            )
            .click();
        await page.getByText(/sort ascending/i).click();
        await soon(
            'sorting puts the smaller budget first',
            async () => (await cell(0, 'budget').inputValue()) === '120',
            () => cell(0, 'budget').inputValue(),
        );
        // (that menu gone, before the next column's is opened)
        await page.getByText(/sort ascending/i).waitFor({ state: 'hidden' });

        // A field is renamed, and offered only kinds its values are kept as
        const kind = (type) =>
            page.locator(`[data-test="column-type-${type}"]`);

        await page
            .locator('[data-test="column-header"][data-column="owner"]')
            .hover();
        await page
            .locator(
                '[data-test="column-header"][data-column="owner"] [data-test="column-menu"]',
            )
            .click();
        await page.getByText(/edit field/i).click();
        check(
            'a text field may become an email, but not a number',
            (await kind('email').isEnabled()) &&
                !(await kind('integer').isEnabled()),
        );
        await page.locator('[data-test="column-label"]').fill('Lead');
        await kind('email').click();
        await page.locator('[data-test="column-save"]').click();
        await until(async () => (await headers()).includes('Lead'));
        const edited = await saved();
        check(
            'editing a field saves it',
            edited.startsWith('Saved') && (await headers()).includes('Lead'),
            `${edited} / ${(await headers()).join(' | ')}`,
        );

        await page.screenshot({ path: `${SHOTS}/tables-filled.png` });

        await reopen(page);
        await page.waitForSelector('[data-test="table-workspace"]');
        await rows.first().waitFor();
        check(
            'the title survives a reload',
            (await page.locator('[data-test="table-title"]').inputValue()) ===
                TITLE,
        );
        check(
            'and so do the columns, as edited',
            (await headers()).join('|').match(/Lead.*Budget/i) !== null,
            (await headers()).join(' | '),
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
        await soon(
            'a selected row can be deleted',
            async () => (await rows.count()) === 1,
        );
        await saved();

        await reopen(page);
        await page.waitForSelector('[data-test="table-workspace"]');
        await rows.first().waitFor();
        check(
            'and stays deleted',
            (await rows.count()) === 1 &&
                (await cell(0, 'owner').inputValue()) === 'Grace',
        );

        // ------------------------------------------------- formulas
        const formula = (row, column) =>
            rows.nth(row).locator(`[data-column="${column}"]`).innerText();
        const parameter = async (name, value) => {
            await page.locator('[data-test="parameter-add"]').click();
            await page.locator('[data-test="parameter-name"]').fill(name);
            await page.locator('[data-test="parameter-new-value"]').fill(value);
            await page.locator('[data-test="parameter-save"]').click();
            await page.locator(`[data-test="parameter-${name}"]`).waitFor();
        };

        await parameter('rate', '5');
        check(
            'a parameter shows above the table',
            (await page.locator('[data-test="parameter-rate"]').innerText())
                .replace(/\s+/g, ' ')
                .trim() === 'rate = 5',
        );

        // A formula that can't be worked out is said why, and not kept
        await page.locator('[data-test="add-column"]').first().click();
        await page.locator('[data-test="column-label"]').fill('In baht');
        await page.locator('[data-test="column-type-formula"]').click();
        await page
            .locator('[data-test="column-expression"]')
            .fill('budget * fx');
        await page.locator('[data-test="column-save"]').click();
        const refusal =
            (await until(() =>
                page
                    .locator('[data-test="column-expression-error"]')
                    .innerText({ timeout: 500 }),
            )) ?? '';
        // The refusal is a 422, which the browser reports; it was asked for
        const told = () =>
            problems.findIndex((problem) => problem.includes('422'));
        await until(() => told() !== -1, { timeout: 2000 });
        const asked = told();

        if (asked !== -1) {
            problems.splice(asked, 1);
        }

        check(
            'a formula naming nothing is refused where it was typed',
            refusal.includes('Nothing in this table is called "fx"'),
            refusal || '(no message)',
        );

        await page
            .locator('[data-test="column-expression"]')
            .fill('budget * rate');
        await page.locator('[data-test="column-summary-sum"]').click();
        await page.locator('[data-test="column-save"]').click();
        await soon(
            'a formula column works out every row',
            async () => (await formula(0, 'in_baht')).trim() === '600',
            () => formula(0, 'in_baht'),
        );

        // Typed into, the row's formula follows without reloading
        await cell(0, 'budget').fill('200');
        await cell(0, 'budget').press('Tab');
        await soon(
            'and follows a value as it is typed',
            async () => (await formula(0, 'in_baht')).trim() === '1,000',
            () => formula(0, 'in_baht'),
        );

        // The parameter moves once; the row and the total follow
        await page.locator('[data-test="parameter-rate"]').click();
        await page.locator('[data-test="parameter-value"]').fill('2');
        await page.locator('[data-test="parameter-value"]').press('Enter');
        await soon(
            'changing a parameter works every row out again',
            async () => (await formula(0, 'in_baht')).trim() === '400',
            () => formula(0, 'in_baht'),
        );
        const total = await page
            .locator('[data-test="table-footer"] [data-total="in_baht"]')
            .innerText()
            .catch(() => '');
        check(
            'and the footer sums it',
            total.replace(/\s+/g, ' ').trim().toLowerCase() === 'sum 400',
            total || '(no footer)',
        );
        await page.screenshot({ path: `${SHOTS}/tables-formulas.png` });

        await saved();
        await reopen(page);
        await page.waitForSelector('[data-test="table-workspace"]');
        await rows.first().waitFor();
        check(
            'formulas, parameters and totals survive a reload',
            (await formula(0, 'in_baht')).trim() === '400' &&
                (
                    await page
                        .locator('[data-test="parameter-rate"]')
                        .innerText()
                ).includes('2'),
        );

        await page.locator('[data-test="delete-table"]').click();
        await page.getByRole('button', { name: /^delete table$/i }).click();
        await page.waitForURL(/\/tables(\?|$)/);
        await soon(
            'deleting it takes it off the list',
            async () =>
                !(await page.locator('body').innerText()).includes(TITLE),
        );
    },
    { canvas: false },
);
