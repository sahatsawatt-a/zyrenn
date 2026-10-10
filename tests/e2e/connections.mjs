// Connections: one's own in settings and a project's in the project, each a
// model's host, a bot or an API key. Adding one picks a service first, then
// asks only what that service needs; a service that can be tried is tried
// before it is kept. A project's viewer sees them, and cannot change them.
//
// Needs an Ollama the app can reach:
//   E2E_OLLAMA=http://ollama:11434 node tests/e2e/connections.mjs
import {
    SHOTS,
    deleteProject,
    runBoard,
    shareProject,
    signIn,
    tinker,
} from './harness.mjs';

const HOST = process.env.E2E_OLLAMA ?? 'http://ollama:11434';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';

// Named for this run, so one left behind by an earlier run is never mistaken for it
const RUN = Date.now().toString().slice(-6);
const MODEL_NAME = `e2e model ${RUN}`;
const BOT_NAME = `e2e bot ${RUN}`;
const KEY_NAME = `e2e key ${RUN}`;

await runBoard(
    '/settings/connections',
    async ({ page, check, afterwards }) => {
        const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
        afterwards(() =>
            tinker(
                `${user}->connections()->where('name', 'like', 'e2e %${RUN}%')->delete();`,
            ),
        );

        const sheet = page.locator('[data-slot="sheet-content"]');
        const row = (name) =>
            page.locator('[data-connection]', { hasText: name });

        // --- one's own, starting from nothing ---
        const empty = (await page.locator('[data-connection]').count()) === 0;

        if (empty) {
            check(
                'with nothing yet, it offers ways to begin',
                (await page.locator('[data-starter]').count()) === 3,
            );
            await page.screenshot({ path: `${SHOTS}/connections-empty.png` });
            await page
                .getByRole('button', { name: /choose from everything/i })
                .click();
        } else {
            await page.locator('[data-connection-add]').click();
        }

        await sheet.waitFor();
        const tiles = await sheet.locator('[data-service]').count();
        check(
            'adding starts from every service, in its group',
            tiles >= 11 &&
                (await sheet.getByText('AI models').count()) === 1 &&
                (await sheet.getByText('Messaging').count()) === 1 &&
                (await sheet.getByText('HTTP & webhooks').count()) === 1,
            `${tiles} services`,
        );
        await page.screenshot({ path: `${SHOTS}/connections-pick.png` });

        // A Telegram bot: its own fields, nothing of a model's
        await sheet.locator('[data-service="telegram"]').click();
        check(
            'a bot asks for its token and how it receives, not for a host',
            (await sheet.locator('#connection-bot_token').count()) === 1 &&
                (await sheet.locator('#connection-base_url').count()) === 0 &&
                (await sheet
                    .locator('[data-choice="poll"]')
                    .getAttribute('aria-checked')) === 'true',
        );
        check(
            'the form says it is not a login, so a browser does not fill it from one',
            (await sheet
                .locator('#connection-bot_token')
                .getAttribute('autocomplete')) === 'new-password' &&
                (await sheet
                    .locator('#connection-name')
                    .getAttribute('autocomplete')) === 'off',
        );

        await sheet.locator('#connection-name').fill(BOT_NAME);
        await sheet.locator('#connection-bot_token').fill('not a token');
        await sheet.locator('[data-connection-save]').click();
        await sheet.locator('[data-connection-verdict]').waitFor();
        check(
            'a token of the wrong shape is caught when it is tried',
            (
                await sheet.locator('[data-connection-verdict]').innerText()
            ).includes('not a bot token'),
            await sheet.locator('[data-connection-verdict]').innerText(),
        );

        await sheet
            .locator('#connection-bot_token')
            .fill('123456:e2e-not-a-real-token');
        await sheet.locator('[data-connection-test]').click();
        await page.waitForFunction(
            () =>
                document
                    .querySelector('[data-connection-verdict]')
                    ?.textContent?.includes('Telegram'),
            null,
            { timeout: 20000 },
        );
        const told = await sheet
            .locator('[data-connection-verdict]')
            .innerText();
        check(
            'Telegram is asked, and a token it does not know is said so without repeating it',
            /Telegram/.test(told) && !told.includes('e2e-not-a-real-token'),
            told,
        );
        await page.screenshot({ path: `${SHOTS}/connections-bot.png` });

        await sheet.locator('[data-connection-save]').click();
        await row(BOT_NAME).waitFor();
        check(
            'it can still be kept, under Messaging',
            (await page
                .locator('[data-group="messaging"]', { hasText: BOT_NAME })
                .count()) === 1 &&
                (await row(BOT_NAME).innerText()).includes('asking Telegram'),
        );
        check(
            'the page never holds the token',
            !(await page.content()).includes('e2e-not-a-real-token'),
        );

        await page.locator('[data-connection-add]').click();
        // OpenRouter lists its models, with their prices, without a key: a try
        // of the form shows which cost nothing. Needs the app to reach the internet.
        await sheet.locator('[data-service="openrouter"]').click();
        await sheet.locator('[data-connection-test]').click();
        const reachable = await page
            .waitForSelector('text=/Works: \\d+ model/', { timeout: 30000 })
            .then(() => true)
            .catch(() => false);

        if (reachable) {
            await page.locator('[data-model-picker]').click();
            await page.waitForSelector('[data-free-toggle]');
            const total = await page
                .locator('[data-model-list] [role="option"]')
                .count();
            const advertised = Number(
                (await page.locator('[data-free-toggle]').innerText()).match(
                    /\((\d+)\)/,
                )?.[1],
            );
            const badged = await page
                .locator('[data-model-list] [role="option"]', {
                    hasText: /\bfree\b/,
                })
                .count();
            check(
                'a host that gives prices offers a switch for the free ones',
                advertised > 0 && advertised < 200,
                `${advertised} free`,
            );
            check(
                'free ones are marked in the full list',
                badged > 0,
                `${badged} marked of ${total} shown`,
            );

            await page.locator('[data-free-toggle]').click();
            await page.waitForTimeout(200);
            // The first row is "no usual model", which is a choice and not a model
            const onlyFree = (
                await page
                    .locator('[data-model-list] [role="option"]')
                    .allInnerTexts()
            ).filter((text) => !text.startsWith('No usual model'));
            check(
                'switched on, only the free ones are listed',
                (await page
                    .locator('[data-free-toggle]')
                    .getAttribute('aria-checked')) === 'true' &&
                    onlyFree.length === advertised,
                `${onlyFree.length} listed of ${advertised} free`,
            );

            await page
                .locator('input[aria-label="Search models"]')
                .fill('zzzz-no-such');
            check(
                'and a typed name still narrows them, or is offered as typed',
                (await page
                    .getByRole('option', { name: /Use “zzzz-no-such”/ })
                    .count()) === 1,
            );

            await page.keyboard.press('Escape');
            await page.waitForTimeout(300);
            await page.reload({ waitUntil: 'networkidle' });
            await page.locator('[data-connection-add]').click();
            await sheet.locator('[data-service="openrouter"]').click();
            await sheet.locator('[data-connection-test]').click();
            await page.waitForSelector('text=/Works: \\d+ model/', {
                timeout: 30000,
            });
            await page.locator('[data-model-picker]').click();
            await page.waitForSelector('[data-free-toggle]');
            check(
                'the switch is remembered',
                (await page
                    .locator('[data-free-toggle]')
                    .getAttribute('aria-checked')) === 'true',
            );
            await page.locator('[data-free-toggle]').click(); // leave it as found
            await page.keyboard.press('Escape');
        } else {
            console.log(
                'SKIP  the free-model switch: the app could not reach openrouter.ai',
            );
        }

        await page.waitForTimeout(300);
        await sheet.getByRole('button', { name: 'Cancel' }).click();
        await sheet.waitFor({ state: 'hidden' });

        // A model on Ollama: tried, its models listed, one chosen
        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="ollama"]').click();
        await sheet.locator('#connection-name').fill(MODEL_NAME);
        await sheet.locator('#connection-base_url').fill(HOST);
        await sheet.locator('[data-connection-test]').click();
        await sheet.getByText(/Works: \d+ model/).waitFor({ timeout: 30000 });
        check('a model host is tried and its models counted', true);

        await sheet.locator('[data-model-picker]').click();
        await page.locator('[data-model-list] [role="option"]').nth(1).click();
        await page.waitForTimeout(300);
        const model = await sheet.locator('[data-model-picker]').innerText();
        check(
            'a usual model is chosen from them',
            !/no usual model/i.test(model),
            model,
        );
        check(
            "choosing a model keeps the host's verdict, so saving does not try it again",
            (await sheet.locator('[data-connection-verdict]').count()) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/connections-model.png` });

        await sheet.locator('[data-connection-save]').click();
        await row(MODEL_NAME).waitFor();
        check(
            'it is kept under AI models, with its host',
            (await page
                .locator('[data-group="ai"]', { hasText: MODEL_NAME })
                .count()) === 1 &&
                (await row(MODEL_NAME).innerText()).includes(
                    HOST.replace(/^https?:\/\//, ''),
                ),
        );

        await row(MODEL_NAME).getByRole('button', { name: /test/i }).click();
        await row(MODEL_NAME)
            .getByText(/Works: \d+ model/)
            .waitFor({ timeout: 30000 });
        check('a saved one is tested from its row', true);
        await page.screenshot({
            path: `${SHOTS}/connections-list.png`,
            fullPage: true,
        });

        // Changing it: another AI service may be chosen, not anything else
        await row(MODEL_NAME)
            .getByRole('button', { name: /more for/i })
            .click();
        await page.getByRole('menuitem', { name: /edit/i }).click();
        await sheet.waitFor();
        check(
            'editing an AI one offers the other AI services, not a bot',
            (await sheet.locator('[data-service="openrouter"]').count()) ===
                1 &&
                (await sheet.locator('[data-service="telegram"]').count()) ===
                    0,
        );
        await sheet.locator('#connection-name').fill(`${MODEL_NAME} renamed`);
        await sheet.locator('[data-connection-save]').click();
        await row(`${MODEL_NAME} renamed`).waitFor();
        check('a change is kept', true);

        // Removing asks first
        await row(BOT_NAME)
            .getByRole('button', { name: /more for/i })
            .click();
        await page.getByRole('menuitem', { name: /remove/i }).click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Delete' })
            .click();
        await row(BOT_NAME).waitFor({ state: 'detached' });
        check('a removed one is gone', true);

        // A LINE channel says where LINE sends its messages, to paste in LINE's console
        tinker(
            `${user}->connections()->create(['name' => 'e2e line ${RUN}', 'service' => 'line', 'settings' => [], 'secrets' => ['channel_access_token' => 't', 'channel_secret' => 's']]);`,
        );
        afterwards(() =>
            tinker(
                `${user}->connections()->where('name', 'e2e line ${RUN}')->delete();`,
            ),
        );
        await page.reload({ waitUntil: 'networkidle' });
        const hook = row(`e2e line ${RUN}`).locator('[data-connection-hook]');
        const said = await hook.innerText();
        check(
            'a LINE channel shows its webhook address, where to paste it, and copies it',
            /\/hooks\/line\/\w+/.test(said) &&
                said.includes('LINE Developers') &&
                (await hook
                    .getByRole('button', { name: /copy .*webhook address/i })
                    .count()) === 1,
            said.replace(/\s+/g, ' '),
        );
        afterwards(() =>
            tinker(
                `${user}->chatRooms()->where('created_at', '>', now()->subHour())->where('title', '')->delete();`,
            ),
        );

        // --- a project's ---
        const { project, base } = await shareProject(
            page,
            `Connections check ${RUN}`,
            MEMBER,
            'viewer',
        );
        afterwards(() => deleteProject(page, base, project));

        await page.goto(`${base}/p/${project}/settings`, {
            waitUntil: 'networkidle',
        });
        await page.locator('[data-test="project-connections"]').click();
        await page.waitForURL(/\/p\/\w+\/settings\/connections$/);
        check(
            'a project has a connections page of its own, reached from its settings',
            (await page.locator('[data-connection]').count()) === 0,
        );

        await page
            .getByRole('button', { name: /choose from everything/i })
            .click();
        await sheet.locator('[data-service="http_header"]').click();
        check(
            'an API key has nothing to try ahead, so no Test',
            (await sheet.locator('[data-connection-test]').count()) === 0 &&
                (await sheet
                    .locator('#connection-header_name')
                    .inputValue()) === 'Authorization',
        );
        await sheet.locator('#connection-name').fill(KEY_NAME);
        await sheet
            .locator('#connection-base_url')
            .fill('https://api.example.com/v2');
        await sheet
            .locator('#connection-header_value')
            .fill('Bearer e2e-secret');
        await sheet.locator('[data-connection-save]').click();
        await row(KEY_NAME).waitFor();
        const shown = await row(KEY_NAME).innerText();
        check(
            'it is kept for the project, saying who added it and where it may be sent',
            shown.includes('added by') && shown.includes('api.example.com/v2'),
            shown,
        );

        const { context, page: viewer } = await signIn(
            page.context().browser(),
            base,
            MEMBER,
        );
        try {
            await viewer.goto(`${base}/p/${project}/settings/connections`, {
                waitUntil: 'networkidle',
            });
            check(
                'a viewer sees it, and nothing to change it with',
                (await viewer
                    .locator('[data-connection]', { hasText: KEY_NAME })
                    .count()) === 1 &&
                    (await viewer.locator('[data-connection-add]').count()) ===
                        0 &&
                    (await viewer
                        .getByRole('button', { name: /more for/i })
                        .count()) === 0,
            );
            check(
                'and never the secret',
                !(await viewer.content()).includes('e2e-secret'),
            );
            await viewer.screenshot({
                path: `${SHOTS}/connections-viewer.png`,
                fullPage: true,
            });
        } finally {
            await context.close();
        }
    },
    { canvas: false },
);
