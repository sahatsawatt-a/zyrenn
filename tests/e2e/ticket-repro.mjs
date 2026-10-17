// A bug that says how to make it happen again -- its steps, what should
// happen and what does, and the project's browser check that does them, run
// from the ticket to see whether it still happens -- with a video of it
// beside its pictures. And all of it on a phone.
//
// Needs the checks worker and the chrome service (a check runs for real, on
// a page of its own, so nothing leaves the machine) and ffmpeg, for the clip.
import { readFileSync } from 'node:fs';
import {
    SHOTS,
    clip,
    deleteProject,
    open,
    reopen,
    runBoard,
    shareProject,
    tinker,
} from './harness.mjs';

const EMAIL = process.env.E2E_EMAIL ?? 'test@example.com';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);
const CLIP = clip();

// A sign-in that never signs in: the button does nothing, so "Signed in"
// never shows and the check fails -- the bug still happens
const PAGE = 'data:text/html,<h1>Shop</h1><button>Log in</button>';

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Repro ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        tinker(
            `$p = App\\Models\\Project::where('ref_id', '${project}')->firstOrFail(); $s = (new App\\Models\\Browser\\BrowserSuite(['title' => 'Shop', 'checks' => [['id' => 'login', 'title' => 'Logging in', 'steps' => [['do' => 'open', 'url' => '${PAGE}'], ['do' => 'click', 'target' => 'Log in'], ['do' => 'see', 'target' => 'Signed in', 'timeout' => '2']]]]]))->ownedBy($p, App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()); $s->save(); echo $s->ref_id;`,
        );

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const dialogGone = () =>
            test('ticket-dialog').waitFor({ state: 'detached' });
        const steps = () => test('ticket-repro-step');

        await open(page, `${base}/p/${project}/tickets`);

        // ------------------------------------------- Filed with how it happens
        await test('new-ticket').click();
        await test('ticket-type-bug').click();
        check(
            'how to make it happen again is folded away until asked for',
            (await steps().count()) === 0 &&
                /optional/.test(await test('ticket-repro-toggle').innerText()),
        );
        await test('ticket-title').fill('Logging in does nothing');
        await test('ticket-repro-toggle').click();
        await steps().first().fill('Open the shop');
        // Enter is the next step
        await steps().first().press('Enter');
        await soon('Enter starts the next step', async () =>
            steps()
                .nth(1)
                .evaluate((input) => input === document.activeElement)
                .catch(() => false),
        );
        await page.keyboard.type('Click "Log in"');
        await test('ticket-repro-expected').fill('Signed in');
        await test('ticket-repro-happens').fill('Nothing at all');
        await test('ticket-check').click();
        await page.locator('[data-pick="__none__"]').waitFor();
        await page.getByRole('option', { name: 'Shop · Logging in' }).click();

        // A feature asks none of it
        await test('ticket-type-feature').click();
        check(
            'only a bug asks how to make it happen',
            (await test('ticket-repro').count()) === 0,
        );
        await test('ticket-type-bug').click();

        // ------------------------------------------------------------ A video
        await test('ticket-add-video').click();
        const chooser = page.waitForEvent('filechooser');
        await page
            .getByRole('button', { name: 'Choose from computer' })
            .click();
        await (
            await chooser
        ).setFiles({
            name: `login-${RUN}.mp4`,
            mimeType: 'video/mp4',
            buffer: readFileSync(CLIP),
        });
        await test('ticket-video').waitFor({ timeout: 30000 });
        check(
            'a video goes in beside the pictures',
            (await test('ticket-video').count()) === 1 &&
                (await test('ticket-picture').count()) === 0 &&
                /Pictures and videos/.test(
                    await test('ticket-dialog').innerText(),
                ),
        );
        await test('ticket-save').click();
        await dialogGone();

        const row = test('ticket-row').first();
        await soon('the list counts its video', async () =>
            row
                .locator('svg[aria-label="Videos"]')
                .isVisible()
                .catch(() => false),
        );

        // ------------------------------------------------- Read back, and run
        await reopen(page);
        await row.locator('button').first().click();
        await test('ticket-dialog').waitFor();
        check(
            'opened, it shows the steps as they were said',
            (await steps().count()) === 2 &&
                (await steps().nth(1).inputValue()) === 'Click "Log in"' &&
                (await test('ticket-repro-happens').inputValue()) ===
                    'Nothing at all',
        );
        check(
            'and its check, not run yet',
            /Not run yet/.test(await test('ticket-replay').innerText()),
        );

        await test('ticket-video').click();
        await soon('the video plays in the app’s player', async () =>
            test('viewer-video')
                .evaluate((video) => video.readyState >= 2)
                .catch(() => false),
        );
        await page.keyboard.press('Escape');
        await test('viewer-video').waitFor({ state: 'detached' });

        await test('ticket-replay-start').click();
        await soon(
            'Run it says the bug still happens',
            async () =>
                (await test('ticket-replay-state').innerText()) ===
                'Still happens',
            async () => test('ticket-replay').innerText(),
            { timeout: 90000, every: 500 },
        );
        check(
            'with the step it failed at',
            /Signed in/.test(await test('ticket-replay-verdict').innerText()),
        );
        await page.screenshot({ path: `${SHOTS}/ticket-repro.png` });

        await test('ticket-replay-film').click();
        await soon('the run’s film plays too', async () =>
            test('viewer-video')
                .evaluate((video) => video.readyState >= 1)
                .catch(() => false),
        );
        await page.keyboard.press('Escape');
        await test('viewer-video').waitFor({ state: 'detached' });

        const linked = await test('ticket-replay-run').getAttribute('href');
        check(
            'and links to the run in Browser checks',
            /\/checks\?suite=\w+&run=\w+/.test(linked ?? ''),
            linked,
        );

        // ------------------------------------------------------- On a phone
        await page.setViewportSize({ width: 390, height: 844 });
        await reopen(page);
        await test('ticket-dialog').waitFor();
        await test('ticket-replay').scrollIntoViewIfNeeded();
        const box = await test('ticket-replay').boundingBox();
        const start = await test('ticket-replay-start').boundingBox();
        check(
            'the steps and Run it fit a phone',
            box.x >= 0 &&
                box.x + box.width <= 390 &&
                start.x + start.width <= 390,
            JSON.stringify({ box, start }),
        );
        await page.screenshot({ path: `${SHOTS}/ticket-repro-phone.png` });
    },
    { canvas: false },
);
