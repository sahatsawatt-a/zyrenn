// Two people on one table: each sees the other there, and what either
// changes shows up for the other without a reload.
//
// Needs the second account E2E_MEMBER (e2e-member@example.com by default,
// password "password"), as members.mjs does.
import {
    SHOTS,
    deleteProject,
    open,
    runBoard,
    shareProject,
    signIn,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);
const NAME = `Live ${RUN}`;

await runBoard(
    '/notes',
    async ({ browser, page, check, soon, afterwards }) => {
        // -------------------------- A project with both of them in it, and a table
        const { project, base } = await shareProject(page, NAME, MEMBER);
        afterwards(() => deleteProject(page, base, project));

        await page.goto(`${base}/p/${project}/tables`);
        await page
            .getByRole('button', { name: /new table/i })
            .first()
            .click();
        await page.waitForURL(/\/tables\/\w+$/);
        const tableUrl = page.url();
        await page.waitForSelector('[data-test="table-workspace"]');

        // ------------------------------------------------ The member opens it too
        const { context, page: other } = await signIn(browser, base, MEMBER);
        await open(other, tableUrl);
        await other.waitForSelector('[data-test="table-workspace"]');

        const sees = (who) => async () =>
            (await who.locator('[data-test="presence-member"]').count()) === 1;
        await soon('the owner sees the member there', sees(page));
        await soon('and the member sees the owner', sees(other));

        // ------------------------------------------------ Changes cross over live
        // A new column: the member's grid loads the table again, keeping its place
        await page.locator('[data-test="add-column"]').first().click();
        await page.locator('[data-test="column-label"]').fill('Owner');
        await page.locator('[data-test="column-type-varchar"]').click();
        await page.locator('[data-test="column-save"]').click();
        await soon(
            'a column the owner adds appears for the member',
            async () =>
                (await other
                    .locator('[data-test="column-header"][data-column="owner"]')
                    .count()) === 1,
        );

        const rowsOf = (who) => who.locator('[data-test="table-row"]').count();
        const before = await rowsOf(other);

        await page
            .getByRole('button', { name: /add row|new row/i })
            .first()
            .click();
        await soon(
            'a row the owner adds appears for the member',
            async () => (await rowsOf(other)) === before + 1,
            async () => `${before} → ${await rowsOf(other)}`,
        );

        const cell = (who) =>
            who
                .locator('[data-test="table-row"]')
                .last()
                .locator('[data-column="owner"] input')
                .first();
        await cell(page).fill(`Hello ${RUN}`);
        await cell(page).press('Tab');
        await soon(
            'what the owner types reaches the member',
            async () => (await cell(other).inputValue()) === `Hello ${RUN}`,
            () => cell(other).inputValue(),
        );

        await other.locator('[data-test="table-title"]').fill(`Budget ${RUN}`);
        await soon(
            'a title the member gives it reaches the owner',
            async () =>
                (await page
                    .locator('[data-test="table-title"]')
                    .inputValue()) === `Budget ${RUN}`,
        );
        await page.screenshot({ path: `${SHOTS}/live-1-owner.png` });
        await other.screenshot({ path: `${SHOTS}/live-2-member.png` });

        // ----------------------------------------- Presence on notes and boards
        await page.goto(`${base}/p/${project}/notes`);
        await page
            .getByRole('button', { name: /new note/i })
            .first()
            .click();
        await page.waitForURL(/\/notes\/\w+$/);
        await open(other, page.url());
        await soon('a note shows who else has it open', sees(page));

        // --------------------------------------------- Gone for one, gone for all
        await open(other, tableUrl);
        await open(page, tableUrl);
        // (both of them there, so the other is on the table to be told)
        await soon(
            'and back on the table, each sees the other',
            async () => (await sees(page)()) && (await sees(other)()),
        );
        await page.locator('[data-test="delete-table"]').click();
        await page.getByRole('button', { name: 'Delete table' }).click();
        await other
            .waitForURL(new RegExp(`/p/${project}/tables`), { timeout: 8000 })
            .catch(() => {});
        check(
            'a table deleted by one closes for the other',
            new URL(other.url()).pathname === `/p/${project}/tables`,
            other.url(),
        );

        await context.close();
    },
    { canvas: false },
);
