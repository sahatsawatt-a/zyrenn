// Who is in a project: an owner adds someone, a viewer only looks, a new
// owner takes over, and the first one leaves.
//
// Needs a second account to add, E2E_MEMBER (e2e-member@example.com by default)
// with the password "password".
import { SHOTS, runBoard } from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';

// Named for this run, so one left behind by an earlier run is never mistaken for it
const RUN = Date.now().toString().slice(-6);
const NAME = `Harbour ${RUN}`;
const NOTE = `Brief ${RUN}`;

await runBoard(
    '/notes',
    async ({ browser, page, check }) => {
        const at = (path) => (url) => new URL(url).pathname === path;
        const switcher = page.locator('[data-test="project-switcher"]');

        // ------------------------------------ The owner makes and fills a project
        await switcher.click();
        await page.locator('[data-test="new-project"]').click();
        await page.getByPlaceholder('Name').fill(NAME);
        await page.getByRole('button', { name: 'Create' }).click();
        await page.waitForURL(/\/p\/\w+\/notes$/);
        const project = page.url().match(/\/p\/(\w+)\//)[1];
        const base = new URL(page.url()).origin;

        await page
            .getByRole('button', { name: /new note/i })
            .first()
            .click();
        await page.waitForURL(/\/notes\/\w+$/);
        const noteUrl = page.url();
        await page
            .getByPlaceholder(/untitled/i)
            .first()
            .fill(NOTE);
        await page.waitForTimeout(1500);

        await page.goto(`${base}/p/${project}/boards`);
        await page
            .getByRole('button', { name: /new board/i })
            .first()
            .click();
        await page.waitForURL(/\/boards\/\w+$/);
        const boardUrl = page.url();

        // ------------------------------------------------- Add a viewer
        await page.goto(`${base}/p/${project}/settings`);
        const addForm = page.locator('[data-test="add-member"]');
        await addForm.getByPlaceholder('Their email address').fill(MEMBER);
        await addForm.locator('select').selectOption('viewer');
        await addForm.getByRole('button', { name: 'Add' }).click();
        await page.waitForTimeout(800);
        check(
            'the owner adds someone by email',
            (await page.getByText(MEMBER).count()) > 0,
        );

        await addForm
            .getByPlaceholder('Their email address')
            .fill('nobody@example.com');
        await addForm.getByRole('button', { name: 'Add' }).click();
        await page.waitForTimeout(600);
        check(
            'but only someone with an account',
            (await page.getByText('Nobody has an account').count()) > 0,
        );
        await page.screenshot({ path: `${SHOTS}/members-1-added.png` });

        // ------------------------------------- The viewer only looks
        const context = await browser.newContext({
            viewport: { width: 1500, height: 950 },
        });
        const other = await context.newPage();
        await other.goto(`${base}/login`, { waitUntil: 'networkidle' });
        await other.getByLabel('Email address').fill(MEMBER);
        await other.getByLabel('Password', { exact: true }).fill('password');
        await other.getByRole('button', { name: /log in/i }).click();
        await other.waitForURL(/dashboard/);

        await other.locator('[data-test="project-switcher"]').click();
        await other.getByRole('menuitem', { name: NAME }).click();
        await other.waitForURL(at(`/p/${project}/notes`));
        check(
            'the viewer finds the project in the switcher',
            (await other.getByText(NOTE).count()) > 0,
        );
        check(
            'and is offered nothing to make',
            (await other
                .getByRole('button', { name: /new note|new folder/i })
                .count()) === 0,
        );

        await other.goto(noteUrl);
        await other.waitForTimeout(800);
        check(
            'the note is view only',
            /View only/.test(await other.locator('body').innerText()),
        );
        check(
            'its text can’t be changed',
            (await other
                .locator('.ProseMirror')
                .getAttribute('contenteditable')) === 'false' &&
                (await other
                    .getByPlaceholder(/untitled/i)
                    .first()
                    .getAttribute('readonly')) !== null,
        );

        await other.goto(boardUrl);
        await other.waitForTimeout(800);
        check(
            'the board is drawn but not editable',
            (await other.locator('[data-test="board-read-only"]').count()) ===
                1 &&
                (await other.locator('[data-test="tool-sticky"]').count()) ===
                    0,
        );

        await other.goto(`${base}/p/${project}/drive`);
        check(
            'the Drive offers no upload',
            (await other.getByRole('button', { name: 'Upload' }).count()) === 0,
        );
        await other.screenshot({ path: `${SHOTS}/members-2-viewer.png` });

        // ---------------------------------------- Promoted, they can make things
        await page.reload();
        const theirRole = page
            .locator('li', { hasText: MEMBER })
            .locator('[data-test="member-role"]');
        await theirRole.selectOption('editor');
        await page.waitForTimeout(800);

        await other.goto(`${base}/p/${project}/notes`);
        check(
            'made an editor, they can make notes',
            (await other.getByRole('button', { name: /new note/i }).count()) >
                0,
        );

        // ---------------------------------------------- Handing it over
        await page.locator('[data-test="leave-project"]').click();
        await page.locator('[data-test="confirm-remove"]').click();
        await page.waitForTimeout(800);
        check(
            'the only owner can’t leave',
            (await page.getByText('only owner').count()) > 0 &&
                at(`/p/${project}/settings`)(page.url()),
        );

        await theirRole.selectOption('owner');
        await page.waitForTimeout(800);
        await page.locator('[data-test="leave-project"]').click();
        await page.locator('[data-test="confirm-remove"]').click();
        await page.waitForURL(at('/notes'));
        await switcher.click();
        check(
            'with another owner, they leave and the project is gone for them',
            (await page.getByRole('menuitem', { name: NAME }).count()) === 0,
        );
        await page.keyboard.press('Escape');

        // ----------------------------------- The new owner runs it, and cleans up
        await other.goto(`${base}/p/${project}/settings`);
        check(
            'the new owner can delete it',
            (await other.locator('[data-test="delete-project"]').count()) === 1,
        );
        await other.screenshot({ path: `${SHOTS}/members-3-new-owner.png` });
        await other.locator('[data-test="delete-project"]').click();
        await other
            .getByRole('dialog')
            .getByRole('button', { name: 'Delete' })
            .click();
        await other.waitForURL(at('/notes'));
        await context.close();
    },
    { canvas: false },
);
