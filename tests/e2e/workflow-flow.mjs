// A Switch's ways and a For each, in the editor: each way the Switch names is
// a dot of its own on the canvas, a way renamed lets go of what was joined
// to it, For each offers the workflows it may run, and a run gathers what
// each item's run gave.
import { SHOTS, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e flow ${RUN}`;
const CALLEE = `e2e callee ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
const at = (x, y) => ({ x, y });

const callee = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${CALLEE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'called',
                    key: 'called',
                    type: 'trigger.call',
                    position: at(0, 0),
                    config: {},
                },
                {
                    id: 'twice',
                    key: 'twice',
                    type: 'data.set',
                    position: at(300, 0),
                    config: {
                        fields: [
                            {
                                name: 'doubled',
                                value: '{{ trigger.item * 2 }}',
                            },
                        ],
                    },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'called',
                    sourceHandle: 'out',
                    target: 'twice',
                },
            ],
        },
    )}', true); $w->save(); $w->freeze(App\\Models\\Workflow\\WorkflowVersion::PUBLISHED, $u); echo $w->ref_id;`,
);

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'start',
                    key: 'start',
                    type: 'trigger.manual',
                    position: at(0, 120),
                    config: { payload: '{"n": 20, "items": [1, 2, 3]}' },
                },
                {
                    id: 'sort',
                    key: 'sort',
                    type: 'logic.switch',
                    position: at(320, 120),
                    config: {
                        ways: [{ name: 'big', value: 'trigger.n > 10' }],
                    },
                },
                {
                    id: 'each',
                    key: 'each',
                    type: 'logic.each',
                    position: at(660, 40),
                    config: {
                        workflow: callee,
                        items: 'trigger.items',
                        at_once: 2,
                    },
                },
                {
                    id: 'small',
                    key: 'small',
                    type: 'output.log',
                    position: at(660, 220),
                    config: { message: 'small' },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'start',
                    sourceHandle: 'out',
                    target: 'sort',
                },
                {
                    id: 'e2',
                    source: 'sort',
                    sourceHandle: 'big',
                    target: 'each',
                },
                {
                    id: 'e3',
                    source: 'sort',
                    sourceHandle: 'otherwise',
                    target: 'small',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

await runBoard(
    `/workflows/${ref}`,
    async ({ page, check, afterwards }) => {
        afterwards(() =>
            tinker(
                `${user}->workflows()->whereIn('title', ['${TITLE}', '${CALLEE}'])->delete();`,
            ),
        );

        const settings = page.locator('[data-step-settings]');
        const step = (key) => page.locator(`[data-step="${key}"]`);
        // Connections on the canvas
        const fromSort = () => page.locator('.vue-flow__edge').count();

        await step('sort').waitFor();
        const ways = await step('sort').innerText();
        check(
            'a Switch shows each way it names, then "otherwise"',
            ways.includes('big') && ways.includes('otherwise'),
            ways.replace(/\s+/g, ' '),
        );

        // --- run it: big, so For each runs the callee for each item ---
        await page.locator('[data-run]').click();
        await page
            .locator('[data-run-status]', { hasText: 'Succeeded' })
            .waitFor({ timeout: 60000 });
        await page.locator('[data-run-step="each"]').click();
        const output = await page.locator('[data-run-output]').innerText();
        check(
            'For each runs the other workflow per item and gathers what each gave',
            output.includes('"doubled": 2') &&
                output.includes('"doubled": 6') &&
                output.includes('"succeeded": 3'),
            output.replace(/\s+/g, ' ').slice(0, 160),
        );
        check(
            'the way not taken is skipped',
            (await page
                .locator('[data-run-step="small"]')
                .getAttribute('data-run-step-status')) === 'skipped',
        );
        await page.screenshot({ path: `${SHOTS}/workflow-flow-run.png` });
        await page
            .getByRole('button', { name: 'Close the run panel' })
            .click()
            .catch(() => {});

        // --- For each offers only what it may run ---
        await page
            .getByRole('button', { name: 'Fit the workflow in view' })
            .click();
        await page.waitForTimeout(400);
        await step('each').click();
        await settings.waitFor();
        await settings
            .locator('#setting-workflow')
            .click({ timeout: 8000 })
            .catch(async (error) => {
                await page.screenshot({
                    path: `${SHOTS}/workflow-flow-stuck.png`,
                });
                throw error;
            });
        const offered = await page.getByRole('option').allInnerTexts();
        check(
            'For each offers the published workflows run "When run by another workflow"',
            offered.some((text) => text.includes(CALLEE)) &&
                !offered.some((text) => text.includes(TITLE)),
            offered.join(', '),
        );
        await page.keyboard.press('Escape');
        await settings.getByRole('button', { name: 'Close' }).click();

        // --- a way added, a way renamed ---
        await step('sort').click();
        await settings.waitFor();
        await settings.locator('[data-add-field]').click();
        const rows = settings.locator('[data-field-row]');
        await rows.nth(1).getByLabel('Name').fill('some');
        await rows.nth(1).getByLabel('When').fill('trigger.n > 5');
        await page.waitForTimeout(300);
        check(
            'a way added is a way out on the canvas',
            (await step('sort').innerText()).includes('some'),
        );

        const before = await fromSort();
        await rows.nth(0).getByLabel('Name').fill('large');
        await page.waitForTimeout(300);
        check(
            'renaming a way lets go of what was joined to it',
            (await fromSort()) === before - 1 &&
                (await step('sort').innerText()).includes('large'),
            `${before} → ${await fromSort()}`,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-flow-switch.png` });
    },
    { canvas: false },
);
