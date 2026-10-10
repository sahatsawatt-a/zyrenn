// Two people with one workflow open: each sees the other there; a run's
// progress reaches both over the socket, not by asking; a draft one saves
// shows up for the other; and a page that was offline catches up on what
// it missed when it is back.
//
// Needs the second account E2E_MEMBER (e2e-member@example.com by default).
import {
    SHOTS,
    deleteProject,
    runBoard,
    shareProject,
    signIn,
    tinker,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

// start → pause (2 s) → done: long enough to watch, short enough to wait for
const definition = {
    nodes: [
        {
            id: 'start',
            key: 'start',
            type: 'trigger.manual',
            position: { x: 0, y: 0 },
        },
        {
            id: 'pause',
            key: 'pause',
            type: 'logic.delay',
            position: { x: 320, y: 0 },
            config: { amount: '2', unit: 'seconds' },
        },
        {
            id: 'done',
            key: 'done',
            type: 'output.log',
            position: { x: 640, y: 0 },
            config: { message: 'done' },
        },
    ],
    edges: [
        { id: 'e1', source: 'start', target: 'pause' },
        { id: 'e2', source: 'pause', target: 'done' },
    ],
};

await runBoard(
    '/notes',
    async ({ browser, page, check, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Live flow ${RUN}`,
            MEMBER,
            'editor',
        );
        afterwards(() => deleteProject(page, base, project));

        await page.goto(`${base}/p/${project}/workflows`, {
            waitUntil: 'networkidle',
        });
        await page
            .getByRole('button', { name: /new workflow/i })
            .first()
            .click();
        await page.waitForURL(/\/workflows\/\w+$/);
        const url = page.url();
        const ref = url.split('/').pop();

        tinker(
            `App\\Models\\Workflow\\Workflow::where('ref_id', '${ref}')->first()->update(['definition' => json_decode('${JSON.stringify(definition)}', true)]);`,
        );
        await page.reload({ waitUntil: 'networkidle' });

        const { context, page: other } = await signIn(browser, base, MEMBER);
        await other.goto(url, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);

        check(
            'each sees the other with the workflow open',
            (await page.locator('[data-test="presence-member"]').count()) ===
                1 &&
                (await other
                    .locator('[data-test="presence-member"]')
                    .count()) === 1,
        );

        await other.locator('[data-runs-toggle]').click();
        await other.getByText('It has not been run yet.').waitFor();

        // --- a run's progress, told over the socket ---
        const began = Date.now();
        await page.locator('[data-run]').click();
        await page
            .locator('[data-step="pause"][data-step-status="waiting"]')
            .waitFor({ timeout: 5000 });
        check('the canvas shows the step waiting while it waits', true);
        await page
            .locator('[data-run-status]', { hasText: 'Succeeded' })
            .waitFor({ timeout: 8000 });
        const took = Date.now() - began;
        // Asking again only every 10 s while the socket is up: sooner than that, the socket told it
        check(
            'the end of the run arrives as it happens, not at the next ask',
            took < 8000,
            `${took} ms`,
        );
        check(
            'and each step is coloured as it ended',
            (await page
                .locator('[data-step="done"]')
                .getAttribute('data-step-status')) === 'succeeded',
        );

        await other
            .locator('[data-history-run]')
            .first()
            .waitFor({ timeout: 5000 });
        check(
            'the other person sees the run arrive in their history without reloading',
            (await other.locator('[data-history-run]').count()) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-live.png` });

        // --- a draft saved by one shows up for the other ---
        await other.getByLabel('Workflow title').fill(`Renamed live ${RUN}`);
        await other
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });
        await page.waitForFunction(
            (expected) =>
                document.querySelector('[aria-label="Workflow title"]')
                    ?.value === expected,
            `Renamed live ${RUN}`,
            { timeout: 8000 },
        );
        await page.waitForTimeout(500);
        check(
            'a draft saved by one shows up for the other, who had nothing unsaved, connections and all',
            (await page.locator('.vue-flow__node').count()) === 3 &&
                (await page.locator('.vue-flow__edge').count()) === 2,
        );

        // --- offline, then back: what was missed is fetched ---
        await context.setOffline(true);
        await page.locator('[data-run]').click();
        await page
            .locator('[data-run-status]', { hasText: 'Succeeded' })
            .waitFor({ timeout: 10000 });
        await context.setOffline(false);
        await other.waitForFunction(
            () => document.querySelectorAll('[data-history-run]').length === 2,
            null,
            { timeout: 30000 },
        );
        check(
            'a page that was offline catches up on the run it missed once it is back',
            true,
        );
        await other.screenshot({ path: `${SHOTS}/workflow-live-other.png` });

        await context.close();
    },
    { canvas: false },
);
