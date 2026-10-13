// The guide: reached from the sidebar, a topic drawn as a note that cannot be
// written in, moving between topics, and all of it inside a phone's width.
import { SHOTS, runBoard, open } from './harness.mjs';

await runBoard(
    '/dashboard',
    async ({ page, check }) => {
        await page
            .locator('[data-sidebar="menu-button"]', { hasText: 'Guide' })
            .click();
        await page.waitForURL((url) => url.pathname === '/guide');
        check('the sidebar opens the guide in the app', true, page.url());

        const topic = page.locator('[data-guide-topic]');
        await topic.locator('.tiptap table').first().waitFor();
        check(
            'opens on the first topic',
            (await topic.locator('h2').first().innerText()) ===
                'Getting started',
        );
        check(
            'drawn as a note: a table and a callout',
            (await topic.locator('.tiptap table').count()) > 0 &&
                (await topic.locator('.tiptap .callout-block').count()) > 0,
        );
        check(
            'only for reading',
            (await topic.locator('.tiptap').getAttribute('contenteditable')) ===
                'false',
        );
        const picture = topic.locator('.tiptap img').first();
        await picture.scrollIntoViewIfNeeded();
        await page.waitForFunction(
            () =>
                document.querySelector('[data-guide-topic] .tiptap img')
                    ?.naturalWidth > 0,
        );
        check('with a picture of the screen it is about', true);
        await page.screenshot({ path: `${SHOTS}/guide-desktop.png` });

        await page
            .locator('[data-guide-topics] a', { hasText: 'Workflows' })
            .click();
        await page.waitForURL((url) => url.pathname === '/guide/workflows');
        await topic.locator('.tiptap ol').first().waitFor();
        check(
            'another topic opens in place',
            (await topic.locator('h2').first().innerText()) === 'Workflows' &&
                (await topic.innerText()).includes('From a template'),
        );
        check(
            'the topic being read is marked',
            (await page
                .locator('[data-guide-topics] a[aria-current="page"]')
                .innerText()) === 'Workflows',
        );

        await page.getByRole('link', { name: 'Notes' }).last().click();
        await page.waitForURL((url) => url.pathname === '/guide/notes');
        check('the one before is a step back', true);

        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, page.url().replace('/notes', '/workflows'));
        await topic.locator('.tiptap table').first().waitFor();
        const wide = await page.evaluate(
            () => document.documentElement.scrollWidth,
        );
        check('nothing runs off a phone', wide <= 390, `${wide}px`);
        await page.screenshot({
            path: `${SHOTS}/guide-phone.png`,
            fullPage: true,
        });
    },
    { canvas: false },
);
