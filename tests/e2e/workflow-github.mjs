// GitHub: a connection by a token, a usual repository and a webhook secret,
// whose page shows where GitHub is to send; its calls as steps of GitHub's own
// group, in parts; and a trigger whose settings follow what it waits for.
// Nothing here reaches GitHub: the connection is made, not tried, and
// nothing is run.
import { paletteGroup, runBoard, SHOTS, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e github ${RUN}`;
const ACCOUNT_NAME = `e2e github ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const account = tinker(
    `$u = ${user}; $c = new App\\Models\\Connection(['name' => '${ACCOUNT_NAME}', 'service' => 'github', 'settings' => ['repo' => 'zyrenn/app']]); $c->secrets = ['token' => 'e2e-not-a-real-token', 'webhook_secret' => 'e2e-secret']; $c->ownedBy($u, $u)->save(); echo $c->ref_id;`,
);

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'heard',
                    key: 'heard',
                    type: 'trigger.github',
                    label: 'Something happens on GitHub',
                    position: { x: 0, y: 120 },
                    config: { connection: account, on: 'issues' },
                },
                {
                    id: 'say',
                    key: 'say',
                    type: 'github.comment',
                    label: 'GitHub: comment',
                    position: { x: 320, y: 120 },
                    config: {
                        connection: account,
                        number: '{{ trigger.issue.number }}',
                        body: 'Thanks, filed.',
                    },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'heard',
                    sourceHandle: 'out',
                    target: 'say',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

const forget = () =>
    tinker(
        `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); $u->connections()->where('name', '${ACCOUNT_NAME}')->delete();`,
    );

// --- the connection: a token, a usual repository, a webhook secret ---
const failedForm = await runBoard(
    '/settings/connections',
    async ({ page, check }) => {
        const sheet = page.locator('[data-slot="sheet-content"]');

        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="github"]').click();
        await sheet.locator('#connection-token').waitFor();
        check(
            'a GitHub account asks for a token, a usual repository and a webhook secret',
            (await sheet.locator('#connection-repo').count()) === 1 &&
                (await sheet.locator('#connection-webhook_secret').count()) ===
                    1,
        );
        await page.screenshot({ path: `${SHOTS}/connection-github.png` });
        await sheet.getByRole('button', { name: 'Cancel' }).click();

        const saved = page.locator('[data-connection]', {
            hasText: ACCOUNT_NAME,
        });
        await saved.waitFor();
        check(
            'a saved one says which repository it works on',
            (await saved.innerText()).includes('Works on zyrenn/app'),
            await saved.innerText(),
        );

        // Its row shows where GitHub is to send what happens, and how to set it
        const hook = saved.locator('[data-connection-hook]');
        check(
            'it shows its webhook address for GitHub, and where to paste it',
            (await hook.innerText()).includes(`/hooks/github/${account}`) &&
                (await hook.innerText()).includes('Payload URL'),
            await hook.innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/connection-github-hook.png` });
    },
    { canvas: false },
);

// --- its steps, in a group of their own; the trigger's settings ---
const failedEditor = await runBoard(
    `/workflows/${ref}`,
    async ({ page, check }) => {
        const settings = page.locator('[data-step-settings]');
        const group = await paletteGroup(page, 'GitHub');
        const kinds = await group
            .locator('[data-palette-step]')
            .evaluateAll((steps) =>
                steps.map((step) => step.getAttribute('data-palette-step')),
            );
        check(
            'GitHub has a group of its own: issues, pull requests, code, Actions',
            [
                'github.issue_create',
                'github.pull_create',
                'github.file',
                'github.dispatch',
                'github.request',
            ].every((kind) => kinds.includes(kind)),
            kinds.join(', '),
        );

        await page.locator('[data-step="heard"]').click();
        await settings.waitFor();
        check(
            'the trigger listens through the connection chosen, for issues',
            (
                await settings.locator('#setting-connection').innerText()
            ).includes(ACCOUNT_NAME) &&
                (await settings.locator('#setting-on').innerText()).includes(
                    'An issue',
                ),
        );
        check(
            'waiting for issues, it asks for actions but not a branch',
            (await settings.locator('#setting-actions').count()) === 1 &&
                (await settings.locator('#setting-branch').count()) === 0,
        );

        await settings.locator('#setting-on').click();
        await page.getByRole('option', { name: 'Commits are pushed' }).click();
        await settings.locator('#setting-branch').waitFor();
        check(
            'waiting for pushes, it asks for a branch instead',
            (await settings.locator('#setting-actions').count()) === 0,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-github.png` });
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
        await sheet.locator('[data-service="github"]').click();
        await sheet.locator('#connection-token').waitFor();
        check(
            'on a phone the form fits, and nothing runs off the side',
            await page.evaluate(
                () =>
                    document.documentElement.scrollWidth <=
                    document.documentElement.clientWidth,
            ),
        );
        await page.screenshot({ path: `${SHOTS}/connection-github-phone.png` });
    },
    { canvas: false, width: 390, height: 844 },
);

// One part's failures are not forgotten for another's passing
if (failedForm || failedEditor || failedPhone) {
    process.exitCode = 1;
}
