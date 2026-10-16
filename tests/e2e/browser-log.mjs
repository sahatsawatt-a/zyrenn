// A run's log, for the app's admins: what each step met in the browser --
// here a page that writes to its console and throws -- folded under the run,
// the hidden value it typed blotted out. The check opens a page of its own (a
// data: URL), so nothing reaches the internet.
import { open, runBoard, tinker, until } from './harness.mjs';

const RUN = Date.now();
const email = process.env.E2E_EMAIL ?? 'test@example.com';
const user = `App\\Models\\User::where('email', '${email}')->first()`;
const page_ = encodeURIComponent(
    "<body style=\"font:20px system-ui;padding:40px\"><label>Password <input id=p></label> <button onclick=\"console.log('typed '+p.value);console.error('bad thing');setTimeout(()=>{throw new Error('boom')});document.body.append(' Done')\">Go</button></body>",
).replace(/'/g, '%27'); // (in a PHP string below)

await runBoard(
    '/checks',
    async ({ page, soon, afterwards }) => {
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const title = `Log ${RUN}`;

        // Its admin for the while, and a suite run once, behind the scenes
        const ref = tinker(
            `$u = ${user}; $u->forceFill(['is_admin' => true])->save();
             $s = (new App\\Models\\Browser\\BrowserSuite(['title' => '${title}', 'checks' => []]))->ownedBy($u, $u);
             $s->take(['checks' => [['title' => 'Logs', 'steps' => [['do' => 'open', 'url' => 'data:text/html,${page_}'], ['do' => 'type', 'target' => 'Password', 'text' => '{{password}}'], ['do' => 'click', 'target' => 'Go'], ['do' => 'see', 'target' => 'Done']]]],
                 'values' => [['name' => 'password', 'value' => 'hunter2-${RUN}']]])->save();
             echo $s->ref_id;`.replace(/\n\s*/g, ' '),
        );

        afterwards(() =>
            tinker(
                `${user}->forceFill(['is_admin' => false])->save(); App\\Models\\Browser\\BrowserSuite::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        const run = tinker(
            `$s = App\\Models\\Browser\\BrowserSuite::where('ref_id', '${ref}')->first(); $r = App\\Support\\Browser\\SuiteRun::start($s, $s->creator, App\\Support\\Browser\\SuiteRun::settings('quick')); App\\Jobs\\RunBrowserChecks::dispatch($r->id); echo $r->ref_id;`,
        );
        await until(
            async () =>
                tinker(
                    `echo App\\Models\\Browser\\BrowserRun::where('ref_id', '${run}')->value('state');`,
                ) === 'passed',
            { timeout: 60000, every: 1000 },
        );

        await open(page, `${new URL(page.url()).origin}/checks`);
        await page
            .locator('[data-test="suite-row"]', { hasText: title })
            .click();
        await until(
            async () =>
                (await test('suite-title')
                    .inputValue()
                    .catch(() => '')) === title,
        );

        // Its run, opened: choosing a suite opens its checks once it has
        // loaded, which may be after the tab was asked for
        const row = page.locator(`[data-test="run-row"][data-run="${run}"]`);
        await soon('the run is there under its runs', async () => {
            if (!(await row.isVisible())) {
                await page
                    .getByRole('tab', { name: /Runs/ })
                    .click({ timeout: 2000 })
                    .catch(() => {});
            }

            return row.isVisible();
        });
        // Opened: clicked again while the list is still being drawn afresh
        await soon('opened, it is the run shown', async () => {
            await row.click({ timeout: 2000 }).catch(() => {});

            return (
                (await page
                    .locator('[data-test="check-run"]')
                    .getAttribute('data-run')
                    .catch(() => null)) === run
            );
        });

        await soon(
            'an admin finds its log folded under it',
            async () => (await test('check-run-log').count()) === 1,
        );
        // Unfolded: clicked again while the run is still being drawn afresh
        await soon('unfolded', async () => {
            if (
                !(await test('check-run-log')
                    .getAttribute('open')
                    .then((open) => open !== null)
                    .catch(() => false))
            ) {
                await test('check-run-log')
                    .locator('summary')
                    .click({ timeout: 2000 })
                    .catch(() => {});
            }

            return test('check-run-log-step').first().isVisible();
        });
        await soon(
            'opened, a line for each step, with what the page wrote and threw',
            async () => {
                const text = (await test('check-run-log').textContent()) ?? '';

                return (
                    (await test('check-run-log-step').count()) === 4 &&
                    text.includes('console.error: bad thing') &&
                    text.includes('page error: boom')
                );
            },
        );
        await soon('…and the hidden value it typed blotted out', async () => {
            const text = (await test('check-run-log').textContent()) ?? '';

            return (
                text.includes('typed •••') && !text.includes(`hunter2-${RUN}`)
            );
        });
    },
    { canvas: false },
);
