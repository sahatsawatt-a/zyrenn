// Post on LinkedIn in the editor: a step of LinkedIn's own group, set up
// from dropdowns -- the member it posts as, and what goes with the words,
// which shows only the settings that choice needs. Run, it asks LinkedIn
// itself; the member here was never signed in there, so LinkedIn refuses
// and the run says to sign in again. Nothing is ever posted.
//
// Needs the app to reach the internet.
import {
    paletteGroup,
    paletteGroups,
    runBoard,
    SHOTS,
    tinker,
} from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e linkedin ${RUN}`;
const MEMBER_NAME = `e2e member ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const member = tinker(
    `$u = ${user}; $c = new App\\Models\\Connection(['name' => '${MEMBER_NAME}', 'service' => 'linkedin', 'settings' => ['client_id' => 'e2e', 'member' => 'e2e', 'member_name' => 'E2E Member', 'expires_at' => now()->addDays(30)->toIso8601String()]]); $c->secrets = ['client_secret' => 'e2e', 'access_token' => 'e2e-not-a-real-token']; $c->ownedBy($u, $u)->save(); echo $c->ref_id;`,
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
                    config: { payload: '{"what": "beans"}' },
                },
                {
                    id: 'post',
                    key: 'post',
                    type: 'linkedin.post',
                    label: 'Post on LinkedIn',
                    position: { x: 320, y: 120 },
                    config: {
                        connection: member,
                        text: 'Out today: {{ trigger.what }}',
                    },
                },
            ],
            edges: [
                {
                    id: 'e1',
                    source: 'start',
                    sourceHandle: 'out',
                    target: 'post',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

const failed = await runBoard(
    `/workflows/${ref}`,
    async ({ page, check }) => {
        const settings = page.locator('[data-step-settings]');
        const pick = async (where, option) => {
            await where.click();
            await page
                .getByRole('option', { name: option, exact: true })
                .click();
        };
        const shown = async (name) =>
            (await settings.locator(`#setting-${name}`).count()) === 1;

        // --- in the palette, under its own service ---
        const group = await paletteGroup(page, 'LinkedIn');
        const kinds = await group
            .locator('[data-palette-step]')
            .evaluateAll((steps) =>
                steps.map((step) => step.getAttribute('data-palette-step')),
            );
        check(
            'LinkedIn has a group of its own, with a step for each of its calls',
            kinds.length === 8 &&
                kinds[0] === 'linkedin.post' &&
                kinds.includes('linkedin.comment') &&
                kinds.includes('linkedin.me'),
            kinds.join(', '),
        );

        const groups = (await paletteGroups(page)).map((title) =>
            title.toUpperCase(),
        );
        // Each opened, its first post or trigger in it
        const firsts = [];

        for (const [title, kind] of [
            ['WhatsApp', 'trigger.whatsapp'],
            ['Facebook', 'facebook.post'],
            ['Instagram', 'instagram.post'],
            ['Threads', 'threads.post'],
        ]) {
            firsts.push(
                await (
                    await paletteGroup(page, title)
                )
                    .locator(`[data-palette-step="${kind}"]`)
                    .count(),
            );
        }

        check(
            'Meta’s four have groups of their own too, each with its steps',
            ['WHATSAPP', 'FACEBOOK', 'INSTAGRAM', 'THREADS'].every((group) =>
                groups.includes(group),
            ) && firsts.every((count) => count === 1),
            groups.join(', '),
        );

        // --- set up from dropdowns ---
        await page.locator('[data-step="post"]').click();
        await settings.waitFor();
        check(
            'it posts as a LinkedIn member chosen from one’s connections',
            (
                await settings.locator('#setting-connection').innerText()
            ).includes(MEMBER_NAME),
            await settings.locator('#setting-connection').innerText(),
        );
        check(
            'with nothing else, it asks for no link and no picture',
            !(await shown('link')) &&
                !(await shown('picture')) &&
                !(await shown('title')),
        );

        await pick(
            settings.locator('#setting-with'),
            'A link, shown as a card',
        );
        check(
            'with a link, it asks for the link and its title',
            (await shown('link')) &&
                (await shown('title')) &&
                !(await shown('picture')),
        );
        await pick(
            settings.locator('#setting-with'),
            'A picture from the Drive',
        );
        check(
            'with a picture, it asks for one of the Drive’s',
            (await shown('picture')) && !(await shown('link')),
        );
        await pick(settings.locator('#setting-with'), 'A poll');
        check(
            'with a poll, it asks for the question, its answers and how long',
            (await shown('question')) &&
                (await shown('answers')) &&
                (await shown('lasts')) &&
                !(await shown('picture')),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-linkedin.png` });
        await pick(settings.locator('#setting-with'), 'Nothing else');
        await page
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });

        // --- run: LinkedIn itself is asked ---
        await page.locator('[data-run]').click();
        const run = page.locator('[data-run-panel]');
        await run
            .locator('[data-run-step="post"][data-run-step-status="failed"]')
            .waitFor({ timeout: 60000 });
        await run.locator('[data-run-step="post"]').click();
        const said = await run.locator('[data-run-error]').first().innerText();
        check(
            'LinkedIn refuses a member it never signed in, and the run says what to do',
            said.includes('sign in again on the connection'),
            said,
        );
        check(
            'the run never shows the token',
            !(await page.content()).includes('e2e-not-a-real-token'),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-linkedin-run.png` });
    },
    { canvas: false },
);

// --- on a phone, opened as one ---
await runBoard(
    `/workflows/${ref}`,
    async ({ page, check, afterwards }) => {
        afterwards(() =>
            tinker(
                `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); $u->connections()->where('name', '${MEMBER_NAME}')->delete();`,
            ),
        );

        const settings = page.locator('[data-step-settings]');

        await page.locator('[data-step="post"]').click();
        await settings.waitFor();
        check(
            'on a phone its settings open over the canvas, and nothing runs off the side',
            (await settings.locator('#setting-text').count()) === 1 &&
                (await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <=
                        document.documentElement.clientWidth,
                )),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-linkedin-phone.png` });
    },
    { canvas: false, width: 390, height: 844 },
);

// The first part's failures are not forgotten for the second's passing
if (failed) {
    process.exitCode = 1;
}
