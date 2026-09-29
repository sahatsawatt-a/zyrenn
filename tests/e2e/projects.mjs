// A project as a place: made from the switcher, filled like one's own notes,
// kept apart from them, renamed and deleted from its settings.
import { SHOTS, runBoard } from './harness.mjs';

// Named for this run, so one left behind by an earlier run is never mistaken for it
const RUN = Date.now().toString().slice(-6);
const NAME = `Lakeshore ${RUN}`;
const NOTE = `Site survey ${RUN}`;

await runBoard(
    '/notes',
    async ({ page, check }) => {
        const switcher = page.locator('[data-test="project-switcher"]');
        // A whole path, so /boards is never matched by /p/{project}/boards
        const at = (path) => (url) => new URL(url).pathname === path;
        const sidebarLink = (title) =>
            page.locator('[data-sidebar="menu-button"]', { hasText: title });

        check(
            'the switcher starts on your own things',
            /Personal/.test(await switcher.innerText()),
        );

        // ------------------------------------------------------------ Make one
        await switcher.click();
        await page.locator('[data-test="new-project"]').click();
        await page.getByPlaceholder('Name').fill(NAME);
        await page.getByRole('button', { name: 'Create' }).click();
        await page.waitForURL(/\/p\/\w+\/notes$/);

        const project = page.url().match(/\/p\/(\w+)\//)[1];
        check('a new project opens on its notes', true, page.url());
        check(
            'the switcher says which project you are in',
            (await switcher.innerText()).includes(NAME),
        );
        check(
            'the sidebar leads to the project’s boards',
            (await sidebarLink('Boards').getAttribute('href')) ===
                `/p/${project}/boards`,
        );
        await page.screenshot({ path: `${SHOTS}/projects-1-new.png` });

        // ------------------------------------------------ Fill it like your own
        await page.getByRole('button', { name: 'New folder' }).click();
        await page.getByPlaceholder('Name').fill('Surveys');
        await page.getByRole('button', { name: 'Create' }).click();
        await page.waitForTimeout(600);
        check(
            'a folder is made in the project',
            (await page.getByText('Surveys', { exact: true }).count()) > 0,
        );

        await page
            .getByRole('button', { name: /new note/i })
            .first()
            .click();
        await page.waitForURL(/\/notes\/\w+$/);
        await page
            .getByPlaceholder(/untitled/i)
            .first()
            .fill(NOTE);
        await page.waitForTimeout(1500);

        const crumb = page.locator('nav[aria-label="breadcrumb"] a', {
            hasText: 'Notes',
        });
        check(
            'the note’s breadcrumb leads back to the project',
            (await crumb.getAttribute('href')) === `/p/${project}/notes`,
        );
        check(
            'the note’s page is still in the project',
            (await switcher.innerText()).includes(NAME),
        );

        await crumb.click();
        await page.waitForURL(new RegExp(`/p/${project}/notes$`));
        check(
            'the project lists the note',
            (await page.getByText(NOTE).count()) > 0,
        );
        await page.screenshot({ path: `${SHOTS}/projects-2-filled.png` });

        // ----------------------------------------------------- Kept apart
        await sidebarLink('Boards').click();
        await page.waitForURL(new RegExp(`/p/${project}/boards$`));
        await switcher.click();
        await page.getByRole('menuitem', { name: 'Personal' }).click();
        await page.waitForURL(at('/boards'));
        check('switching to your own keeps to boards', true, page.url());

        await sidebarLink('Notes').click();
        await page.waitForURL(at('/notes'));
        check(
            'your own notes don’t show the project’s',
            (await page.getByText(NOTE).count()) === 0,
        );

        // ------------------------------------------------ Rename and delete
        await switcher.click();
        await page.getByRole('menuitem', { name: NAME }).click();
        await page.waitForURL(new RegExp(`/p/${project}/notes$`));
        await sidebarLink('Project settings').click();
        await page.waitForURL(new RegExp(`/p/${project}/settings$`));

        check(
            'settings list you as its owner',
            /owner/i.test(await page.locator('ul').last().innerText()),
        );

        await page.locator('#name').fill(`${NAME} (renamed)`);
        await page.getByRole('button', { name: 'Save' }).click();
        await page.waitForTimeout(800);
        check(
            'the new name reaches the switcher',
            (await switcher.innerText()).includes(`${NAME} (renamed)`),
        );
        await page.screenshot({ path: `${SHOTS}/projects-3-settings.png` });

        await page.locator('[data-test="delete-project"]').click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Delete' })
            .click();
        await page.waitForURL(at('/notes'));
        await page.waitForTimeout(500);
        check(
            'deleting it goes back to your own notes',
            /Personal/.test(await switcher.innerText()),
        );

        await switcher.click();
        check(
            'the project is gone from the switcher',
            (await page.getByRole('menuitem', { name: NAME }).count()) === 0,
        );
        await page.keyboard.press('Escape');

        // Asked for aside, so the expected 404 isn't logged as a page error
        const gone = await page.request.get(
            new URL(`/p/${project}/notes`, page.url()).href,
        );
        check(
            'its pages are gone too',
            gone.status() === 404,
            `${gone.status()}`,
        );
    },
    { canvas: false },
);
