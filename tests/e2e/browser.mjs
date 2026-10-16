// Browser checks (/checks): a suite of checks made and kept, run quick
// in the real browser on the server, then slowed and filmed into the Drive; a
// page of this network refused; and -- for an admin -- the app's own test runs
// as the runner writes them down. The check opens a page of its own (a data:
// URL), so nothing reaches the internet.
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { KEPT, SHOTS, open, runBoard, tinker, until } from './harness.mjs';

const RUN = Date.now();
const email = process.env.E2E_EMAIL ?? 'test@example.com';
const user = `App\\Models\\User::where('email', '${email}')->first()`;

await runBoard(
    '/checks',
    async ({ page, check, soon, afterwards }) => {
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const base = new URL(page.url()).origin;

        afterwards(() =>
            tinker(
                `$u = ${user}; $u->browserSuites()->where('title', 'like', 'Sign-up %')->get()->each->delete(); $u->driveFolders()->whereNull('parent_id')->where('name', 'Browser checks')->get()->each(function ($f) { $f->deleteTree(); }); echo 'ok';`,
            ),
        );

        await test('suite-new').waitFor();
        check(
            'the sidebar opens Browser checks',
            await page
                .locator('[data-sidebar="menu-button"]', {
                    hasText: 'Browser checks',
                })
                .getAttribute('data-active')
                .then((on) => on === 'true'),
        );

        // ------------------------------------------------- A suite of its own
        await test('suite-new').click();
        await page
            .getByRole('dialog')
            .getByRole('textbox')
            .fill(`Sign-up ${RUN}`);
        await page.getByRole('button', { name: 'Make it' }).click();
        // The new one, not the one open before it
        await until(
            async () =>
                (await test('suite-title')
                    .inputValue()
                    .catch(() => '')) === `Sign-up ${RUN}`,
        );
        check(
            'a new suite starts empty, saying how to make a check',
            (await test('checks-empty').isVisible()) &&
                (await test('run-test').isDisabled()),
        );
        // Written step by step here: building by clicking is browser-build.mjs
        await test('check-add').click();
        await test('check-title').fill('Signing up');
        const page_ = encodeURIComponent(
            '<body style="font:20px system-ui;padding:40px"><label>Your name <input></label> <button onclick="document.body.append(\' Thanks, \' + document.querySelector(\'input\').value)">Sign up</button></body>',
        );
        await test('step-url').fill(`data:text/html,${page_}`);

        // A step of a kind, from the menu of them: it opens to be written
        const add = async (kind, fill) => {
            await test('check-add-step').click();
            await page
                .getByRole('menuitem', { name: kind, exact: true })
                .click();
            await page.getByRole('menu').waitFor({ state: 'detached' });
            const step = test('check-step').last();

            for (const [field, value] of Object.entries(fill)) {
                await step.locator(`[data-test="step-${field}"]`).fill(value);
            }
        };
        // What a step is about, picked from the page rather than guessed at
        const pickFor = async (name) => {
            await test('check-step')
                .last()
                .locator('[data-test="step-pick"]')
                .click();
            const dialog = test('pick-dialog');
            await dialog
                .locator('[data-test="pick-picture"]')
                .waitFor({ timeout: 30000 });
            const listed = await dialog
                .locator('[data-test="pick-thing"]')
                .allInnerTexts();
            await dialog
                .locator('[data-test="pick-thing"]', { hasText: name })
                .first()
                .click();
            await dialog.waitFor({ state: 'detached' });

            return listed;
        };
        await add('Type into', { text: 'Ada' });
        const fields = await pickFor('Your name');
        check(
            'a field to type into is picked from the page, only fields offered',
            (await test('step-target').last().inputValue()) === 'Your name' &&
                fields.length === 1,
            fields.join(', '),
        );
        await add('Click', {});
        const things = await pickFor('Sign up');
        check(
            'and a button to click, from what the steps before leave',
            (await test('step-target').last().inputValue()) === 'Sign up' &&
                things.some((one) => /Your name/.test(one)),
            things.join(', '),
        );
        await add('Expect to see', { target: 'Thanks, Ada' });
        await add('Take a picture', {});
        await soon('kept as it is written', async () =>
            ((await test('suite-status').textContent()) ?? '').startsWith(
                'Saved',
            ),
        );

        // A run started now, followed until it says how it went
        const runEnds = async (label, pattern, timeout, how = 'run-test') => {
            const before = await test('check-run')
                .getAttribute('data-run', { timeout: 100 })
                .catch(() => null);
            await test(how).click();
            await soon(
                label,
                async () =>
                    (await test('check-run').getAttribute('data-run')) !==
                        before &&
                    pattern.test((await test('check-run').innerText()) ?? ''),
                async () => test('check-run-headline').textContent(),
                { timeout },
            );
        };

        // ------------------------------------------------- Run quick
        await runEnds(
            'run quick in the browser on the server, it passes',
            /^Passed/,
            30000,
        );
        await test('check-result').first().getByRole('button').first().click();
        check(
            'each step says in words what it did',
            (await test('step-result').count()) === 5 &&
                /Type "Ada" into "Your name"/.test(
                    await test('check-run').innerText(),
                ),
        );

        await test('step-shot').first().click();
        const picture = page.locator('[data-chat-picture] img');
        await picture.waitFor();
        await page.waitForFunction(
            () =>
                document.querySelector('[data-chat-picture] img')
                    ?.naturalWidth > 0,
        );
        check('its picture opens larger, over the page', true);
        await page.keyboard.press('Escape');
        await picture.waitFor({ state: 'detached' });

        // ------------------------------------------- A demo video, kept
        await runEnds(
            'made into a demo video, it passes',
            /^Passed/,
            60000,
            'run-demo',
        );
        check(
            'it went at the steady pace, filmed and kept',
            /Steady · captions · filmed · kept in the Drive/.test(
                await test('check-run').innerText(),
            ),
        );
        // Run some other way: how fast that goes, said before it starts
        await test('suite-menu').click();
        await test('run-more').click();
        check(
            'run with options, it says how fast it goes',
            /glides 0\.35s.*45ms.*0\.8s/.test(
                await test('run-pace').innerText(),
            ),
        );
        await page.keyboard.press('Escape');
        await test('run-options').waitFor({ state: 'detached' });
        await test('check-result').first().getByRole('button').first().click();
        await test('check-video').waitFor();
        await page.waitForFunction(
            () =>
                document.querySelector('[data-test="check-video"]')
                    ?.readyState >= 1,
        );
        check('its film plays in the page', true);
        check(
            'and it is in the Drive, with its pictures, in a folder of the run',
            (await test('check-run-folder').count()) === 1 &&
                tinker(
                    `echo ${user}->driveFiles()->whereIn('name', ['Signing up.mp4', 'Signing up.webm', 'Signing up - step 5.jpg'])->count();`,
                ) === '2',
        );
        await soon(
            'and the suite gets a way to its folder in the Drive',
            async () =>
                /\/drive\?folder=/.test(
                    (await test('suite-folder').getAttribute('href')) ?? '',
                ),
        );
        await page.screenshot({ path: `${SHOTS}/browser-check-filmed.png` });

        // ------------------------------------------- Kept off this network
        await test('tab-checks').click();
        await test('step-url').first().fill('http://web:8080/login');
        await runEnds(
            'a page of this network is refused by the browser, saying so',
            /not on the public internet/,
            30000,
        );

        // ------------------------------------------------- The app's own tests
        // Only for an admin: the account is one for this part alone
        tinker(`${user}->forceFill(['is_admin' => true])->save(); echo 'ok';`);
        afterwards(() =>
            tinker(
                `${user}->forceFill(['is_admin' => false])->save(); echo 'ok';`,
            ),
        );
        // A run as the runner writes one down, of a made-up suite
        const id = `${new Date(RUN).toISOString().replace(/[-:]/g, '').replace(/\..*/, '')}e2e${String(RUN).slice(-1)}`;
        const runs = join(KEPT, 'runs');
        mkdirSync(join(runs, id, 'pretend'), { recursive: true });
        writeFileSync(
            join(runs, `${id}.json`),
            JSON.stringify({
                id,
                from: 'cli',
                began: RUN,
                ended: RUN + 4000,
                lanes: 1,
                watched: false,
                stopped: false,
                suites: [
                    {
                        name: 'pretend',
                        state: 'failed',
                        lane: 1,
                        took: 4000,
                        expected: 3,
                        problems: 'the pretend thing',
                        lines: ['PASS  one thing', 'FAIL  the pretend thing'],
                    },
                ],
            }),
        );
        afterwards(() => {
            rmSync(join(runs, `${id}.json`), { force: true });
            rmSync(join(runs, id), { recursive: true, force: true });
        });

        await open(page, `${base}/admin/test-runs`);
        check(
            "the app's own test runs are a page of their own, in the sidebar for an admin",
            (await page
                .locator('[data-sidebar="menu-button"]', {
                    hasText: 'Test runs',
                })
                .count()) === 1,
        );
        const kept = test('tests-history').getByRole('button').filter({
            hasText: '0/1 passed',
        });
        await kept.first().click();
        await test('tests-run-shown').waitFor();
        await soon(
            'a run kept by the runner shows, suite by suite',
            async () =>
                (await test('tests-suite')
                    .first()
                    .getAttribute('data-state')) === 'failed',
        );
        await test('tests-suite').first().getByRole('button').click();
        check(
            'a suite opens to what it checked',
            /FAIL {2}the pretend thing/.test(
                await test('tests-lines').innerText(),
            ),
        );
        check(
            'and says how to start the runner, or asks it for a run',
            (await test('tests-no-runner').count()) +
                (await test('tests-ask').count()) ===
                1,
        );
        // Away from the test runs, which ask the app every two seconds, before
        // the account stops being an admin
        await open(page, `${base}/checks`);
        await test('suite-new').waitFor();
    },
    { canvas: false },
);
