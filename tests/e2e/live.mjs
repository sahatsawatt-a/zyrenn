// Two people on one table: each sees the other there, and what either
// changes shows up for the other without a reload.
//
// Needs the second account E2E_MEMBER (e2e-member@example.com by default,
// password "password"), as members.mjs does.
import {
    SHOTS,
    deleteProject,
    runBoard,
    shareProject,
    signIn,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);
const NAME = `Live ${RUN}`;

await runBoard(
    '/notes',
    async ({ browser, page, check, afterwards }) => {
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
        await other.goto(tableUrl, { waitUntil: 'networkidle' });
        await other.waitForSelector('[data-test="table-workspace"]');
        await page.waitForTimeout(1500);

        check(
            'the owner sees the member there',
            (await page.locator('[data-test="presence-member"]').count()) === 1,
        );
        check(
            'and the member sees the owner',
            (await other.locator('[data-test="presence-member"]').count()) ===
                1,
        );

        // ------------------------------------------------ Changes cross over live
        // A new column: the member's grid loads the table again, keeping its place
        await page.locator('[data-test="add-column"]').first().click();
        await page.locator('[data-test="column-label"]').fill('Owner');
        await page.locator('[data-test="column-type-varchar"]').click();
        await page.locator('[data-test="column-save"]').click();
        await page.waitForTimeout(1800);
        check(
            'a column the owner adds appears for the member',
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
        await page.waitForTimeout(1500);
        check(
            'a row the owner adds appears for the member',
            (await rowsOf(other)) === before + 1,
            `${before} → ${await rowsOf(other)}`,
        );

        const cell = (who) =>
            who
                .locator('[data-test="table-row"]')
                .last()
                .locator('[data-column="owner"] input')
                .first();
        await cell(page).fill(`Hello ${RUN}`);
        await cell(page).press('Tab');
        await page.waitForTimeout(1800);
        check(
            'what the owner types reaches the member',
            (await cell(other).inputValue()) === `Hello ${RUN}`,
            await cell(other).inputValue(),
        );

        await other.locator('[data-test="table-title"]').fill(`Budget ${RUN}`);
        await other.waitForTimeout(2500);
        check(
            'a title the member gives it reaches the owner',
            (await page.locator('[data-test="table-title"]').inputValue()) ===
                `Budget ${RUN}`,
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
        await other.goto(page.url(), { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        check(
            'a note shows who else has it open',
            (await page.locator('[data-test="presence-member"]').count()) === 1,
        );

        // --------------------------------------------- Gone for one, gone for all
        await other.goto(tableUrl, { waitUntil: 'networkidle' });
        await page.goto(tableUrl, { waitUntil: 'networkidle' });
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
