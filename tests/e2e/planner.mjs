// The content planner demo (/demo/planner): posts on a month, one opened,
// each platform's version checked against what it takes, versions written
// by an agent, published platform by platform, one that failed tried again
// -- and the list a phone gets. Nothing is kept or posted: it is a demo.
import { SHOTS, open, runBoard } from './harness.mjs';

await runBoard(
    '/demo/planner',
    async ({ page, check, soon }) => {
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const base = new URL(page.url()).origin;

        await open(page, `${base}/demo/planner`);
        await test('planner-calendar').waitFor();
        check(
            'a month of posts, each on its day, saying it is a demo',
            (await test('planner-chip').count()) >= 3 &&
                (await test('planner-demo').isVisible()),
        );
        await page.screenshot({ path: `${SHOTS}/planner-month.png` });

        // ------------------------------------------- One, checked per platform
        await test('planner-chip').filter({ hasText: 'Weekend sale' }).click();
        await test('planner-post').waitFor();
        await test('planner-tab-instagram').click();
        check(
            'Instagram says it needs a picture, before anything is sent',
            /needs a picture/.test(
                await test('planner-version-instagram').innerText(),
            ),
        );
        check(
            'and nothing can be published while it does',
            await test('planner-publish').isDisabled(),
        );
        await test('planner-add-picture').click();
        await soon(
            'given a picture, Instagram is content',
            async () =>
                !(await test('planner-version-instagram').innerText()).includes(
                    'needs a picture',
                ),
        );

        await test('planner-write').click();
        await soon('an agent writes a version for each platform', async () =>
            /#shop/.test(await test('planner-words-instagram').inputValue()),
        );
        await test('planner-tab-linkedin').click();
        check(
            'each its own, LinkedIn more formal',
            /small shop's news/.test(
                await test('planner-words-linkedin').inputValue(),
            ),
        );
        await page.screenshot({ path: `${SHOTS}/planner-post.png` });

        // ------------------------------------------- Out, platform by platform
        await test('planner-publish').click();
        await soon(
            'published, each platform says how it went',
            async () =>
                (await test('planner-result-facebook').count()) === 1 &&
                /Posted/.test(
                    await test('planner-result-instagram').innerText(),
                ) &&
                /refused/.test(
                    await test('planner-result-linkedin').innerText(),
                ),
            async () => await test('planner-results').innerText(),
            { timeout: 15000 },
        );
        check(
            'one failed, so the post says so',
            /Failed/.test(await test('planner-status').innerText()),
        );
        await test('planner-retry-linkedin').click();
        await soon('tried again, it is posted everywhere', async () =>
            /Posted/.test(await test('planner-status').innerText()),
        );
        await page.screenshot({ path: `${SHOTS}/planner-published.png` });
        await page.keyboard.press('Escape');
        await test('planner-post').waitFor({ state: 'detached' });

        // ------------------------------------------------------- A new one
        await test('planner-new').click();
        await test('planner-post').waitFor();
        check(
            'a new post is a draft, and cannot be approved with nothing in it',
            /Draft/.test(await test('planner-status').innerText()) &&
                (await test('planner-approve').isDisabled()),
        );
        await test('planner-text').fill('A post written here.');
        check(
            'written but with no picture, Instagram still holds it back',
            await test('planner-approve').isDisabled(),
        );
        await test('planner-add-picture').click();
        await soon(
            'given a picture too, it can be approved',
            async () => !(await test('planner-approve').isDisabled()),
        );
        await page.keyboard.press('Escape');
        await test('planner-post').waitFor({ state: 'detached' });

        // ------------------------------------------------------- On a phone
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, `${base}/demo/planner`);
        await test('planner-agenda').waitFor();
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        check(
            'a phone gets the list, and it fits',
            overflow <= 0 && (await test('planner-row').count()) >= 4,
            `${overflow}px too wide`,
        );
        await page.screenshot({ path: `${SHOTS}/planner-phone.png` });
    },
    { canvas: false },
);
