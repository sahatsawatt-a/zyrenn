// A workflow drawn in the editor: made from the list, steps added from the
// palette and joined by dragging between their dots, each set up beside the
// canvas. It saves itself; what stands in the way of publishing is shown on
// the steps, and Publish freezes it. After a reload it is all still there.
import {
    SHOTS,
    runBoard,
    tinker,
    open as openPage,
    reopen,
} from './harness.mjs';

const TITLE = `e2e flow ${Date.now().toString().slice(-6)}`;

await runBoard(
    '/workflows',
    async ({ page, check, afterwards }) => {
        const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
        afterwards(() =>
            tinker(
                `${user}->workflows()->where('title', '${TITLE}')->delete();`,
            ),
        );

        const saved = () =>
            page
                .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
                .waitFor({ timeout: 10000 });
        const step = (key) => page.locator(`[data-step="${key}"]`);
        const settings = page.locator('[data-step-settings]');
        // Opens a step's settings as a person would: the panel closed, everything in view
        const open = async (key) => {
            if (await settings.count()) {
                await settings.getByRole('button', { name: 'Close' }).click();
            }

            await fit();
            await step(key).click();
            await settings.waitFor();
        };
        const fit = async () => {
            await page
                .getByRole('button', { name: 'Fit the workflow in view' })
                .click();
            await page.waitForTimeout(500);
        };

        // --- made from the list ---
        await page
            .getByRole('button', { name: /new workflow/i })
            .first()
            .click();
        await page.waitForURL(/\/workflows\/\w+$/);
        await page.locator('[data-canvas] .vue-flow__node').first().waitFor();
        check(
            'a new workflow opens with something to start it, and nothing in its way',
            (await step('start').count()) === 1 &&
                (await page.locator('[data-problems]').innerText()).includes(
                    'Ready',
                ),
        );

        await page.getByLabel('Workflow title').fill(TITLE);

        // --- steps from the palette ---
        await page.locator('[data-palette-step="logic.if"]').click();
        await step('if').waitFor();
        check(
            'clicking a step in the palette adds it, set up beside the canvas',
            (await settings.count()) === 1,
        );

        // Dragged onto the canvas instead
        const canvas = await page.locator('[data-canvas]').boundingBox();
        await page
            .locator('[data-palette-step="output.log"]')
            .dragTo(page.locator('[data-canvas] .vue-flow__pane'), {
                targetPosition: {
                    x: canvas.width * 0.75,
                    y: canvas.height * 0.25,
                },
            });
        await step('log').waitFor();
        check('a step can be dragged onto the canvas', true);

        await page.locator('[data-palette-step="output.log"]').click();
        await step('log_2').waitFor();
        check('a second of a kind gets a key of its own', true);

        // --- what stands in the way shows on the steps ---
        await saved();
        const problems = await page.locator('[data-problems]').innerText();
        check(
            'unfinished steps are counted as things to fix once saved',
            /\d+ to fix/.test(problems),
            problems,
        );
        check(
            'and each says how many of its own',
            (await step('if').locator('[data-step-problems]').count()) === 1,
        );

        // --- joined by dragging from a dot to a dot ---
        await page.keyboard.press('Escape');
        await page.locator('[data-step-settings] [aria-label="Close"]').click();
        await fit();
        const join = async (from, handle, to) => {
            await step(from)
                .locator(`.vue-flow__handle.source[data-handleid="${handle}"]`)
                .dragTo(step(to).locator('.vue-flow__handle.target'));
            await page.waitForTimeout(200);
        };
        await join('start', 'out', 'if');
        await join('if', 'true', 'log');
        await join('if', 'false', 'log_2');
        check(
            'steps are joined by dragging between their dots',
            (await page.locator('.vue-flow__edge').count()) === 3,
        );

        // A trigger has no way in, so a connection dropped on it comes to nothing
        await step('log')
            .locator('.vue-flow__handle.source')
            .dragTo(step('start'));
        await page.waitForTimeout(200);
        check(
            'nothing can lead into what starts a run',
            (await page.locator('.vue-flow__edge').count()) === 3,
        );

        // --- set up beside the canvas ---
        await fit();
        await open('start');
        await settings
            .locator('textarea')
            .fill('{"total": 150, "name": "Ada"}');

        await open('if');
        await settings
            .locator('#setting-condition')
            .fill('trigger.total > 100');

        await open('log');
        await settings.locator('#step-label').fill('Big order');
        await settings
            .locator('#setting-message')
            .fill('Big order from {{ trigger.name }}');
        const readable = await page.locator('[data-test-data]').innerText();
        check(
            'beside a step is what it can read: the trigger, its input, and the steps before it',
            ['trigger', 'input', 'node("if")', 'node("start")'].every((name) =>
                readable.includes(name),
            ),
            readable,
        );
        check(
            'its key and what a failure does are kept under "More"',
            (await settings.locator('#step-key').count()) === 0,
        );
        await settings.locator('[data-more-settings]').click();
        check(
            'where the key later steps read it by can be changed',
            (await settings.locator('#step-key').inputValue()) === 'log' &&
                (await settings.locator('#step-on-error').count()) === 1,
        );

        await open('log_2');
        await settings
            .locator('#setting-message')
            .fill('Small order {{ trigger.total');
        await saved();
        check(
            'a template that is never closed is caught on the step',
            (await step('log_2').locator('[data-step-problems]').count()) === 1,
        );

        // Publish says what is wrong, and goes to it
        await page.locator('[data-publish]').click();
        await page.getByText(/in the way of publishing/).waitFor();
        check(
            'publishing is refused while something is in the way, and the step is opened',
            (
                await settings.locator('[data-settings-problems]').innerText()
            ).includes('never closed'),
        );

        await settings
            .locator('#setting-message')
            .fill('Small order {{ trigger.total }}');
        await saved();
        check(
            'with that fixed, nothing stands in the way',
            (await page.locator('[data-problems]').innerText()).includes(
                'Ready',
            ),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-editor.png` });

        await page.locator('[data-publish]').click();
        await page.locator('[data-published]', { hasText: 'v1' }).waitFor();
        check(
            'Publish freezes it as version 1',
            (await page.locator('[data-published]').innerText()).includes(
                'published',
            ),
        );

        // A change after publishing waits for the next
        await fit();
        await open('log');
        await settings
            .locator('#setting-message')
            .fill('Big order from {{ trigger.name }}!');
        await saved();
        check(
            'a later change is shown as not yet published',
            (await page.locator('[data-published]').innerText()).includes(
                'changed since',
            ),
        );

        // --- run, by the worker, as it is saved ---
        const runStatus = page.locator('[data-run-status]');
        const ran = async (label) => {
            await page.locator('[data-run]').click();
            await runStatus
                .filter({ hasText: label })
                .waitFor({ timeout: 30000 });
        };

        await ran('Succeeded');
        check(
            'Run runs the saved draft through the worker, and it succeeds',
            (await page.locator('[data-run-step]').count()) === 4,
        );
        check(
            'the If went "true", so the step only down "false" was skipped, on the canvas too',
            (await page
                .locator('[data-run-step="log_2"]')
                .getAttribute('data-run-step-status')) === 'skipped' &&
                (await step('log_2').getAttribute('data-step-status')) ===
                    'skipped' &&
                (await step('log').getAttribute('data-step-status')) ===
                    'succeeded',
        );
        check(
            'the run log has what the Log step wrote, worked out',
            (await page.locator('[data-run-logs]').innerText()).includes(
                'Big order from Ada!',
            ),
            await page.locator('[data-run-logs]').innerText(),
        );

        await page.locator('[data-run-step="if"]').click();
        await settings.waitFor();
        const flat = async (locator) =>
            (await locator.innerText()).replace(/\s+/g, ' ');
        const given = page.locator('[data-run-panel] [data-run-input]');
        const gave = page.locator('[data-run-panel] [data-run-output]');
        check(
            'each step says in a line what it gave, or which way it went',
            (await flat(page.locator('[data-run-step="if"]'))).includes(
                'went "true"',
            ) &&
                (await flat(page.locator('[data-run-step="start"]'))).includes(
                    'total: 150',
                ) &&
                (await flat(page.locator('[data-run-step="log"]'))).includes(
                    'Big order from Ada!',
                ),
            await flat(page.locator('[data-run-step="start"]')),
        );
        const [left, right] = [
            await given.boundingBox(),
            await gave.boundingBox(),
        ];
        check(
            'a step opened shows what it was given beside what it gave, part by part',
            (await flat(given)).includes('total 150') &&
                (await flat(gave)).includes('name Ada') &&
                Math.abs(left.y - right.y) < 2 &&
                left.x + left.width <= right.x,
            await flat(given),
        );

        await page.locator('[data-run-expand]').click();
        const modal = page.locator('[data-run-modal]');
        await modal.waitFor();
        const large = await modal.locator('[data-run-output]').boundingBox();
        check(
            'and opens larger over the page, the two still side by side',
            large.width > right.width &&
                (await flat(modal.locator('[data-run-input]'))).includes(
                    'total 150',
                ),
            `${Math.round(right.width)} wide, then ${Math.round(large.width)}`,
        );
        await modal.locator('[data-run-raw]').click();
        check(
            'where it can be read as it is kept, to copy out',
            (await modal.locator('[data-run-output]').innerText()).includes(
                '"total": 150',
            ),
        );
        await page.keyboard.press('Escape');
        await modal.waitFor({ state: 'detached' });
        await page.screenshot({ path: `${SHOTS}/workflow-run.png` });

        // A formula that can't be worked out fails the run, and says where
        await open('if');
        await settings
            .locator('#setting-condition')
            .fill('trigger.missing > 1');
        await saved();
        await ran('Failed');
        const why = await page.locator('[data-run-error]').first().innerText();
        check(
            'a step that fails stops the run, and the run says which and why',
            why.includes('There is no "missing" here') &&
                (await page
                    .locator('[data-run-step="log"]')
                    .getAttribute('data-run-step-status')) === 'cancelled',
            why,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-run-failed.png` });

        await settings
            .locator('#setting-condition')
            .fill('trigger.total > 100');
        await saved();

        await page.getByRole('button', { name: 'History' }).click();
        check(
            'the history lists both runs, newest first',
            (await page.locator('[data-history-run]').count()) === 2 &&
                (
                    await page.locator('[data-history-run]').first().innerText()
                ).includes('Failed'),
        );
        await page.locator('[data-history-run]').nth(1).click();
        await runStatus.filter({ hasText: 'Succeeded' }).waitFor();
        check('an earlier run can be opened again from the history', true);
        await page.getByRole('button', { name: 'Close the run panel' }).click();

        // --- removed with Delete ---
        await fit();
        await open('log_2');
        await settings.locator('[data-remove-step]').click();
        await saved();
        check(
            'removing a step takes its connections with it',
            (await step('log_2').count()) === 0 &&
                (await page.locator('.vue-flow__edge').count()) === 2,
        );

        // --- all there after a reload ---
        await reopen(page);
        await step('if').waitFor();
        check(
            'after a reload every step, connection and setting is still there',
            (await page.getByLabel('Workflow title').inputValue()) === TITLE &&
                (await page.locator('.vue-flow__node').count()) === 3 &&
                (await page.locator('.vue-flow__edge').count()) === 2 &&
                (await step('log').innerText()).includes('Big order'),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-reloaded.png` });

        const stored = tinker(
            `$w = ${user}->workflows()->where('title', '${TITLE}')->first(); echo json_encode([$w->publishedVersion->number, $w->hasUnpublishedChanges(), collect($w->definition['nodes'])->pluck('key')->sort()->values()]);`,
        );
        check(
            'the server holds the same: version 1 published, the draft moved on',
            stored === '[1,true,["if","log","start"]]',
            stored,
        );

        // --- and in the list ---
        await openPage(page, new URL('/workflows', page.url()).href);
        const listed = await page
            .getByText(TITLE)
            .locator('xpath=ancestor::*[contains(., "steps")][1]')
            .innerText();
        check(
            'the list says how many steps and what is published',
            /3 steps/.test(listed) && /v1, changed since/.test(listed),
            listed,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-list.png` });
    },
    { canvas: false },
);
