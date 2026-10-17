// A project's tickets: filed from a form, read as a list or a board, moved
// along, talked over in a chat of the project -- and all of it on a phone.
//
// Needs a second account, E2E_MEMBER, to be given a ticket.
import {
    SHOTS,
    deleteProject,
    open,
    runBoard,
    shareProject,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Tickets ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const rows = () => test('ticket-row');
        const dialogGone = () =>
            test('ticket-dialog').waitFor({ state: 'detached' });
        // One of the app's own dropdowns: opened, then the choice clicked
        const pick = async (dropdown, value) => {
            await dropdown.click();
            await page.locator(`[data-pick="${value}"]`).click();
        };

        await open(page, `${base}/p/${project}/tickets`);
        check(
            'a project with none says so',
            await page.getByText('No tickets yet').isVisible(),
        );

        // ------------------------------------------------------ Filing them
        const file = async ({ type, title, body, unit, high }) => {
            await test('new-ticket').click();
            await test(`ticket-type-${type}`).click();
            await test('ticket-title').fill(title);
            await test('ticket-body').fill(body);
            // The app's own pick-or-add: opened, narrowed by typing, Enter takes it
            await test('ticket-unit').click();
            await page.locator('[data-pick-search]').fill(unit);
            await page.locator('[data-pick-search]').press('Enter');

            if (high) {
                await test('ticket-priority-high').click();
            }

            await test('ticket-save').click();
            await dialogGone();
        };

        await file({
            type: 'bug',
            title: 'Resizing a shape shakes',
            body: 'The edge runs ahead of the pointer and back.',
            unit: 'Boards',
            high: true,
        });
        await file({
            type: 'feature',
            title: 'Shapes with no fill',
            body: 'A shape that shows what is behind it.',
            unit: 'Boards',
        });
        await file({
            type: 'bug',
            title: 'A link pasted twice',
            body: 'Paste a link, then another straight after.',
            unit: 'Notes',
        });

        await soon('three are filed', async () => (await rows().count()) === 3);

        // A part nobody has used yet is offered as a new one
        await test('new-ticket').click();
        await test('ticket-unit').click();
        await page.locator('[data-pick-search]').fill('Payments');
        await page.locator('[data-pick-new="Payments"]').waitFor();
        await page.locator('[data-pick-new="Payments"]').click();
        check(
            'a part typed that nobody has used is added as itself',
            (await test('ticket-unit').innerText()).includes('Payments'),
        );
        await page.screenshot({ path: `${SHOTS}/tickets-part.png` });
        // The list is gone before Escape, or Escape only closes the list
        await page.locator('[data-pick-search]').waitFor({ state: 'detached' });
        await page.keyboard.press('Escape');
        await dialogGone();
        check(
            'numbered as they came, newest first',
            /#3/.test(await rows().first().innerText()) &&
                /#1/.test(await rows().last().innerText()),
            (await rows().allInnerTexts()).join(' | ').replace(/\s+/g, ' '),
        );
        check(
            'a pressing one says so',
            /High/.test(await rows().last().innerText()),
        );

        // ------------------------------------------------------ Finding them
        await test('tickets-bug').click();
        check('only the bugs', (await rows().count()) === 2);
        await test('tickets-all').click();
        await pick(test('tickets-unit'), 'Notes');
        check('only one part', (await rows().count()) === 1);
        await pick(test('tickets-unit'), '__every__');
        await test('tickets-search').fill('fill');
        check(
            'only what was searched for',
            (await rows().count()) === 1 &&
                /no fill/.test(await rows().first().innerText()),
        );
        await test('tickets-search').fill('');

        // ---------------------------------------------- Moving them along
        await pick(
            rows().last().locator('[data-test="ticket-row-status"]'),
            'in_progress',
        );
        await soon(
            'a status picked in the list moves it under that heading',
            async () =>
                /#1/.test(
                    await page
                        .locator('[data-status="in_progress"]')
                        .innerText(),
                ),
        );

        // Opened, changed, given to someone, and saved
        await rows()
            .filter({ hasText: 'no fill' })
            .locator('button')
            .first()
            .click();
        await test('ticket-dialog').waitFor();
        check(
            'the open ticket is in the address',
            /ticket=2/.test(page.url()),
            page.url(),
        );
        await test('ticket-title').fill('Shapes with no fill, and any line');
        await test('ticket-assignee').click();
        await page.getByRole('option', { name: 'E2E Member' }).click();
        await pick(test('ticket-status'), 'done');
        await test('ticket-save').click();
        await dialogGone();
        await soon(
            'done folds it away',
            async () => (await rows().count()) === 2,
        );

        // ------------------------------------------------------ As a board
        await test('tickets-as-board').click();
        const column = (status) =>
            page.locator(
                `[data-test="ticket-board"] [data-status="${status}"]`,
            );
        check(
            'the board has each where it stands',
            /#3/.test(await column('open').innerText()) &&
                /#1/.test(await column('in_progress').innerText()) &&
                /any line/.test(await column('done').innerText()),
        );

        // Dragged from one column to the next
        await column('open')
            .locator('[data-test="ticket-card"]')
            .first()
            .dragTo(column('in_progress'));
        await soon('a card dragged to a column takes its status', async () =>
            /#3/.test(await column('in_progress').innerText()),
        );

        // On hold only with a word on what it waits on
        const holdThree = () =>
            column('in_progress')
                .locator('[data-test="ticket-card"]', { hasText: '#3' })
                .dragTo(column('on_hold'));
        await holdThree();
        await test('ticket-reason-dialog').waitFor();
        await page.getByRole('button', { name: 'Cancel' }).click();
        await test('ticket-reason-dialog').waitFor({ state: 'detached' });
        check(
            'put off without a reason, it stays where it was',
            /#3/.test(await column('in_progress').innerText()),
        );
        await holdThree();
        await test('ticket-move-reason').fill('Waits on the paste fix in #1');
        await test('ticket-move-confirm').click();
        await soon('with one, it is on hold and the card says why', async () =>
            /#3[\s\S]*Waits on the paste fix in #1/.test(
                await column('on_hold').innerText(),
            ),
        );
        await soon(
            'and the question goes away',
            async () => (await test('ticket-reason-dialog').count()) === 0,
        );
        await page.screenshot({ path: `${SHOTS}/tickets-board.png` });

        // The view is remembered, and so is the ticket an address names
        await open(page, `${base}/p/${project}/tickets?ticket=1`);
        await test('ticket-dialog').waitFor();
        check(
            'an address with a ticket in it opens that ticket, on the board',
            (await test('ticket-title').inputValue()) ===
                'Resizing a shape shakes' &&
                (await test('ticket-board').count()) === 1,
        );
        await pick(test('ticket-status'), 'dropped');
        await test('ticket-reason').waitFor();
        await page.screenshot({ path: `${SHOTS}/tickets-form.png` });
        await pick(test('ticket-status'), 'in_progress');
        await test('ticket-reason').waitFor({ state: 'detached' });
        check('the form asks why only for on hold or won’t do', true);

        // ------------------------------------------------------ Talking it over
        await test('ticket-discuss').click();
        await page.waitForURL(/\/chats\/\w+$/);
        await soon('it is discussed in a chat named for it', () =>
            page.getByText('#1 Resizing a shape shakes').first().isVisible(),
        );

        // ------------------------------------------------------ On a phone
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, `${base}/p/${project}/tickets`);
        await test('tickets-as-list').click();
        await rows().first().waitFor();

        const overflow = () =>
            page.evaluate(
                () =>
                    document.documentElement.scrollWidth -
                    document.documentElement.clientWidth,
            );
        check(
            'the list fits a phone',
            (await overflow()) <= 0,
            `${await overflow()}px too wide`,
        );
        await page.screenshot({ path: `${SHOTS}/tickets-phone-list.png` });

        await test('new-ticket').click();
        await test('ticket-dialog').waitFor();
        const form = await test('ticket-dialog').boundingBox();
        const save = await test('ticket-save').boundingBox();
        check(
            'the form fits it too, with Save in reach',
            form.x >= 0 &&
                form.x + form.width <= 390 &&
                save.y + save.height <= 844,
            JSON.stringify({ form, save }),
        );
        await page.screenshot({ path: `${SHOTS}/tickets-phone-form.png` });
        await page.keyboard.press('Escape');

        await test('tickets-as-board').click();
        check(
            'and the board slides column by column, not the page',
            (await overflow()) <= 0,
            `${await overflow()}px too wide`,
        );
        await page.screenshot({ path: `${SHOTS}/tickets-phone-board.png` });

        // ------------------------------------------------------ Deleting
        await test('tickets-as-list').click();
        const before = await rows().count();
        await rows().first().locator('button').first().click();
        await test('ticket-delete').click();
        await page.getByRole('button', { name: 'Delete', exact: true }).click();
        await soon(
            'a ticket is deleted from its form',
            async () => (await rows().count()) === before - 1,
        );
    },
    { canvas: false },
);
