// The sidebar on a phone: a sheet over the page, which a page chosen in it
// closes -- so what is behind it can be tapped, and a tour that goes through
// the menu can go on.
import { runBoard } from './harness.mjs';

await runBoard(
    '/dashboard',
    async ({ page, check, soon }) => {
        const overlay = page.locator('[data-slot="sheet-overlay"]');
        const menu = page.locator(
            '[data-sidebar="sidebar"][data-mobile="true"]',
        );

        await page.locator('[data-sidebar="trigger"]').first().click();
        await menu.waitFor();
        check(
            'the menu opens as a sheet over the page',
            (await overlay.count()) === 1,
        );

        await menu.getByRole('link', { name: 'Boards', exact: true }).click();
        await soon(
            'a page chosen in it is gone to',
            async () => new URL(page.url()).pathname === '/boards',
        );
        await soon(
            'and the menu is closed, its shade with it',
            async () =>
                (await overlay.count()) === 0 && (await menu.count()) === 0,
        );

        // What is behind it takes a tap again: the menu opens once more
        await page.locator('[data-sidebar="trigger"]').first().click();
        await menu.waitFor();
        check(
            'the page behind can be tapped again',
            (await menu.count()) === 1,
        );
    },
    { width: 390, height: 844, canvas: false },
);
