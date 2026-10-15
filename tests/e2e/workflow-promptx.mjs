// PromptX: an account connected by its email and password, which is tried by
// signing in and never kept; and its calls as steps of PromptX's own group --
// making, reading, importing and publishing a flow, and reading its runs. Run,
// a step asks PromptX itself; the account here holds a token PromptX never
// gave out, so PromptX refuses it and the run says to sign in again. Nothing
// is ever made on PromptX.
//
// Needs the app to reach the internet.
import { SHOTS, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e promptx ${RUN}`;
const ACCOUNT_NAME = `e2e promptx ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const account = tinker(
    `$u = ${user}; $c = new App\\Models\\Connection(['name' => '${ACCOUNT_NAME}', 'service' => 'promptx', 'settings' => ['login' => 'e2e@example.com']]); $c->secrets = ['access_token' => Database\\Factories\\ConnectionFactory::jwt(['exp' => now()->addDays(30)->getTimestamp()]), 'refresh_token' => 'e2e-not-a-real-token']; $c->ownedBy($u, $u)->save(); echo $c->ref_id;`,
);

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'start',
                    key: 'start',
                    type: 'trigger.manual',
                    label: 'Run by hand',
                    position: { x: 0, y: 120 },
                    config: { payload: '{}' },
                },
                {
                    id: 'runs',
                    key: 'runs',
                    type: 'promptx.runs',
                    label: 'PromptX: list runs',
                    position: { x: 320, y: 120 },
                    config: { connection: account, status: 'FAILED' },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'start',
                    sourceHandle: 'out',
                    target: 'runs',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

const forget = () =>
    tinker(
        `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); $u->connections()->where('name', '${ACCOUNT_NAME}')->delete();`,
    );

// --- the connection: an email and a password, tried by signing in ---
const failedForm = await runBoard(
    '/settings/connections',
    async ({ page, check }) => {
        const sheet = page.locator('[data-slot="sheet-content"]');

        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="promptx"]').click();
        check(
            'a PromptX account asks for an email and a password, and nothing else',
            (await sheet.locator('#connection-login').count()) === 1 &&
                (await sheet.locator('#connection-password').count()) === 1 &&
                (await sheet.locator('input[id^="connection-"]').count()) === 3,
        );

        await sheet.locator('#connection-name').fill(`e2e try ${RUN}`);
        await sheet.locator('#connection-login').fill('e2e@example.com');
        await sheet.locator('#connection-password').fill('not-the-password');
        await sheet.locator('[data-connection-test]').click();
        const verdict = sheet.locator('[data-connection-verdict]');
        await verdict.waitFor({ timeout: 40000 });
        check(
            'tried, PromptX itself is asked, and a wrong password is said plainly',
            (await verdict.innerText()).includes(
                'PromptX did not take that email and password.',
            ),
            await verdict.innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/connection-promptx.png` });

        await sheet.getByRole('button', { name: 'Cancel' }).click();
        await page
            .locator('[data-connection]', { hasText: ACCOUNT_NAME })
            .waitFor();
        check(
            'a signed-in account says who it is signed in as',
            (
                await page
                    .locator('[data-connection]', { hasText: ACCOUNT_NAME })
                    .innerText()
            ).includes('Signed in as e2e@example.com'),
        );
        check(
            'nothing tried was kept',
            tinker(
                `echo ${user}->connections()->where('name', 'e2e try ${RUN}')->count();`,
            ) === '0',
        );
    },
    { canvas: false },
);

// --- its steps, in a group of their own ---
const failedEditor = await runBoard(
    `/workflows/${ref}`,
    async ({ page, check }) => {
        const settings = page.locator('[data-step-settings]');

        const group = page.locator('[data-step-palette] section').filter({
            has: page.locator('h3', { hasText: /^PromptX$/ }),
        });
        const kinds = await group
            .locator('[data-palette-step]')
            .evaluateAll((steps) =>
                steps.map((step) => step.getAttribute('data-palette-step')),
            );
        check(
            'PromptX has a group of its own, with a step for each of its calls',
            kinds.join(', ') ===
                'promptx.flow_create, promptx.flow, promptx.flow_import, promptx.flow_publish, promptx.runs, promptx.run',
            kinds.join(', '),
        );

        await page.locator('[data-step="runs"]').click();
        await settings.waitFor();
        check(
            'it acts as an account chosen from one’s connections, and keeps to the runs chosen',
            (
                await settings.locator('#setting-connection').innerText()
            ).includes(ACCOUNT_NAME) &&
                (await settings.locator('#setting-status').innerText()).includes(
                    'Failed',
                ),
            await settings.locator('#setting-status').innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-promptx.png` });

        // --- run: PromptX itself is asked ---
        await page.locator('[data-run]').click();
        const run = page.locator('[data-run-panel]');
        await run
            .locator('[data-run-step="runs"][data-run-step-status="failed"]')
            .waitFor({ timeout: 60000 });
        await run.locator('[data-run-step="runs"]').click();
        const said = await run.locator('[data-run-error]').first().innerText();
        check(
            'PromptX refuses a token it never gave out, and the run says what to do',
            said.includes('sign in again'),
            said,
        );
        check(
            'the run never shows the token',
            !(await page.content()).includes('e2e-not-a-real-token'),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-promptx-run.png` });
    },
    { canvas: false },
);

// --- on a phone ---
const failedPhone = await runBoard(
    '/settings/connections',
    async ({ page, check, afterwards }) => {
        afterwards(forget);

        const sheet = page.locator('[data-slot="sheet-content"]');

        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="promptx"]').click();
        await sheet.locator('#connection-password').waitFor();
        check(
            'on a phone the form fits, and nothing runs off the side',
            await page.evaluate(
                () =>
                    document.documentElement.scrollWidth <=
                    document.documentElement.clientWidth,
            ),
        );
        await page.screenshot({ path: `${SHOTS}/connection-promptx-phone.png` });
    },
    { canvas: false, width: 390, height: 844 },
);

// One part's failures are not forgotten for another's passing
if (failedForm || failedEditor || failedPhone) {
    process.exitCode = 1;
}
