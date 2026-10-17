// Prototypes: a project's list (empty, then with one), making one for a
// phone, its bar on the board -- going through its screens, adding one,
// choosing the device -- Play, and the list at a phone's width. Makes a
// project and takes it away again.
import {
    runBoard,
    shareProject,
    deleteProject,
    boardKept,
    open,
    saved,
    until,
} from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

await runBoard(
    '/dashboard',
    async (ctx) => {
        const { page, check, afterwards } = ctx;
        const { project, base } = await shareProject(
            page,
            `Proto ${RUN}`,
            process.env.E2E_MEMBER ?? 'e2e-member@example.com',
        );
        afterwards(() => deleteProject(page, base, project));

        await open(page, `${base}/p/${project}/prototypes`);
        check(
            'empty list says how it goes',
            await page.locator('[data-test="prototypes-empty"]').isVisible(),
        );
        const sidebar = await page
            .locator('[data-sidebar="menu-button"]', { hasText: 'Prototypes' })
            .getAttribute('href');
        check(
            'sidebar has Prototypes in the project',
            (sidebar ?? '').endsWith(`/p/${project}/prototypes`),
            sidebar,
        );

        // Made for a phone, it opens on the board with three screens
        await page.locator('[data-test="new-prototype"]').click();
        const dialog = page.locator('[data-test="new-prototype-dialog"]');
        await dialog.locator('input[name="title"]').fill(`Booking ${RUN}`);
        await dialog.locator('[data-device="phone"]').click();
        await dialog.locator('[data-test="make-prototype"]').click();
        await page.waitForURL(/\/prototypes\/\w+$/);
        const ref = page.url().split('/').pop();
        await page.locator('[data-test="prototype-bar"]').waitFor();

        // The top bar leads back to the project's prototypes, not its boards
        const crumb = page.locator('nav[aria-label="breadcrumb"] a').first();
        check(
            'top bar says Prototypes',
            (await crumb.innerText()).trim() === 'Prototypes' &&
                ((await crumb.getAttribute('href')) ?? '').endsWith(
                    `/p/${project}/prototypes`,
                ),
            `${await crumb.innerText()} ${await crumb.getAttribute('href')}`,
        );
        check(
            'its delete says prototype',
            (await page
                .locator('[data-test="delete-board"]')
                .getAttribute('title')) === 'Delete this prototype',
        );

        await page.locator('[data-test="prototype-screens"]').click();
        const screens = page.locator('[data-test="prototype-screen"]');
        check('three starter screens', (await screens.count()) === 3);
        const names = await screens.allInnerTexts();
        check(
            'named Home, List and Detail',
            names.map((name) => name.trim()).join(',') === 'Home,List,Detail',
            names.join(','),
        );

        // A fourth, at the phone's size, kept with the board
        // Ready once the shared board has arrived
        await page
            .locator('[data-test="prototype-add-screen"]:not([data-disabled])')
            .click();
        await saved(page);
        const kept = await until(async () => {
            const items = Object.values(await boardKept(page, ref));
            const frames = items.filter((item) => item.kind === 'frame');

            return frames.length === 4 ? frames : null;
        });
        const added = kept?.find((frame) => frame.text === 'Screen 4');
        check(
            'added screen kept at 390×844',
            added?.width === 390 && added?.height === 844,
            JSON.stringify(added && [added.width, added.height]),
        );

        // Made for a tablet instead
        await page.locator('[data-test="prototype-device"]').click();
        await page.locator('[role="menuitem"][data-device="tablet"]').click();
        check(
            'device now a tablet',
            Boolean(
                await until(async () =>
                    (
                        await page
                            .locator('[data-test="prototype-device"]')
                            .innerText()
                    ).includes('Tablet'),
                ),
            ),
        );

        await page.locator('[data-test="prototype-play"]').click();
        await page.waitForURL(/\/prototypes\/\w+\/play$/);
        await page.locator('[data-test="play"]').waitFor();
        check('Play opens', page.url().endsWith(`/prototypes/${ref}/play`));

        // Listed, and at a phone's width nothing runs off the side
        await open(page, `${base}/p/${project}/prototypes`);
        const card = page.locator('[data-test="prototype-card"]');
        check('listed once', (await card.count()) === 1);
        const said = await card.innerText();
        check('card says 4 screens', said.includes('4 screens'), said);

        await page.setViewportSize({ width: 390, height: 844 });
        const wide = await until(() =>
            page.evaluate(
                () =>
                    document.documentElement.scrollWidth <=
                    document.documentElement.clientWidth,
            ),
        );
        check('list fits a phone', Boolean(wide));
    },
    { canvas: false },
);
