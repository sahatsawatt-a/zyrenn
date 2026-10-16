// Browser checks followed live: with a suite open, a run started somewhere
// else -- here, behind the scenes, as a timetable, a workflow or an
// assistant would -- turns up in its runs and ends there with no reload, its
// news arriving over the socket. The check opens a page of its own (a data:
// URL), so nothing reaches the internet.
import { open, runBoard, tinker, until } from './harness.mjs';

const RUN = Date.now();
const email = process.env.E2E_EMAIL ?? 'test@example.com';
const user = `App\\Models\\User::where('email', '${email}')->first()`;
const page_ = encodeURIComponent(
    '<body style="font:20px system-ui;padding:40px"><h1>Live</h1><button>Go</button></body>',
);
// The page asks again every 30 s at the most while the socket is there: an
// end seen well before that was heard, not asked for
const HEARD = { timeout: 20000 };

await runBoard(
    '/checks',
    async ({ page, soon, afterwards }) => {
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const base = new URL(page.url()).origin;
        const title = `Live ${RUN}`;

        // A suite of one check, a few seconds long at a steady pace
        const ref = tinker(
            `$u = ${user}; $s = (new App\\Models\\Browser\\BrowserSuite(['title' => '${title}', 'checks' => [['id' => 'live', 'title' => 'Going live', 'steps' => [
                ['do' => 'open', 'url' => 'data:text/html,${page_}'], ['do' => 'see', 'target' => 'Live'], ['do' => 'see', 'target' => 'Go'], ['do' => 'see', 'target' => 'Live']]]]]))->ownedBy($u, $u); $s->save(); echo $s->ref_id;`.replace(
                /\n\s*/g,
                ' ',
            ),
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Browser\\BrowserSuite::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        await open(page, `${base}/checks`);
        await page
            .locator('[data-test="suite-row"]', { hasText: title })
            .click();
        await until(
            async () =>
                (await test('suite-title')
                    .inputValue()
                    .catch(() => '')) === title,
        );

        // Started elsewhere, while the page is open
        const run = tinker(
            `$s = App\\Models\\Browser\\BrowserSuite::where('ref_id', '${ref}')->first(); $r = App\\Support\\Browser\\SuiteRun::start($s, $s->creator, App\\Support\\Browser\\SuiteRun::settings('steady'), App\\Support\\Browser\\SuiteRun::TIMETABLE); App\\Jobs\\RunBrowserChecks::dispatch($r->id); echo $r->ref_id;`,
        );
        const row = page.locator(`[data-test="run-row"][data-run="${run}"]`);

        await soon(
            'a run started elsewhere turns up in the open suite, with no reload',
            async () => (await row.count()) === 1,
            '',
            HEARD,
        );

        // Its runs, opened: choosing a suite opens its checks once it has
        // loaded, which may be after the tab was asked for
        const runs = page.getByRole('tab', { name: /Runs/ });
        await soon('…and is there under its runs', async () => {
            if (!(await row.isVisible())) {
                await runs.click({ timeout: 2000 }).catch(() => {});
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
        await test('check-run-headline').waitFor();
        await soon(
            'opened, its headline follows it to the end as it is told',
            async () =>
                (await test('check-run-headline').textContent())?.startsWith(
                    'Passed',
                ),
            await test('check-run-headline').textContent(),
            HEARD,
        );
        await soon(
            '…and its row in the runs says it passed',
            async () => (await row.getAttribute('data-state')) === 'passed',
        );
        // The headline is told; the steps are read once there is more to show
        await soon(
            "its check's steps come after it",
            async () => (await test('check-result').count()) === 1,
        );
    },
    { canvas: false },
);
