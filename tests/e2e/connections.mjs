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
    open,
    reopen,
    until,
} from './harness.mjs';

const HOST = process.env.E2E_OLLAMA ?? 'http://ollama:11434';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';

// Named for this run, so one left behind by an earlier run is never mistaken for it
const RUN = Date.now().toString().slice(-6);
const MODEL_NAME = `e2e model ${RUN}`;
const BOT_NAME = `e2e bot ${RUN}`;
const KEY_NAME = `e2e key ${RUN}`;
const MEMBER_NAME = `e2e member ${RUN}`;
const PAGE_NAME = `e2e page ${RUN}`;
const NUMBER_NAME = `e2e number ${RUN}`;

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

        // A LinkedIn member: the app's id and secret are typed, and the
        // member signs in on LinkedIn's own page -- there is no token to paste
        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="linkedin"]').click();
        check(
            'a LinkedIn member asks for its app’s id and secret, and for no token',
            (await sheet.locator('#connection-client_id').count()) === 1 &&
                (await sheet.locator('#connection-client_secret').count()) ===
                    1 &&
                (await sheet.locator('#connection-access_token').count()) === 0,
        );
        await sheet.locator('#connection-name').fill(MEMBER_NAME);
        await sheet.locator('#connection-client_id').fill('e2e-client');
        await sheet.locator('#connection-client_secret').fill('e2e-secret');
        await sheet.locator('[data-connection-save]').click();
        await row(MEMBER_NAME).waitFor();

        const wayIn = row(MEMBER_NAME).locator('[data-connection-sign-in]');
        check(
            'kept under Social, it says it is not signed in, and shows the way there and the address to come back to',
            (await page
                .locator('[data-group="social"]', { hasText: MEMBER_NAME })
                .count()) === 1 &&
                (await row(MEMBER_NAME).innerText()).includes(
                    'Not signed in yet',
                ) &&
                /\/settings\/connections\/[^/]+\/sign-in$/.test(
                    (await wayIn.locator('a').getAttribute('href')) ?? '',
                ) &&
                // The app's public address when it has one, else where it is open
                /\/(hooks\/linkedin|settings\/connections)\/signed-in$/.test(
                    await wayIn.locator('code').innerText(),
                ),
            await row(MEMBER_NAME).innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/connections-linkedin.png` });

        // As LinkedIn leaves it once its member has said yes
        tinker(
            `$c = ${user}->connections()->where('name', '${MEMBER_NAME}')->first(); $c->settings = [...$c->settings, 'member' => 'e2e', 'member_name' => 'E2E Member', 'expires_at' => now()->addDays(60)->toIso8601String()]; $c->secrets = [...$c->secrets, 'access_token' => 'e2e-token']; $c->save();`,
        );
        await reopen(page);
        check(
            'signed in, it says who as and until when, and asks for nothing more',
            /Signed in as E2E Member until \S/.test(
                await row(MEMBER_NAME).innerText(),
            ) &&
                (await row(MEMBER_NAME)
                    .locator('[data-connection-sign-in]')
                    .count()) === 0,
            await row(MEMBER_NAME).innerText(),
        );
        check(
            'the page never holds its secret or its token',
            !/e2e-secret|e2e-token/.test(await page.content()),
        );

        // A Facebook Page signs in the same way, at Facebook: its own words on the row
        await page.locator('[data-connection-add]').click();
        check(
            'Meta’s four are there to connect: three to sign in to, and WhatsApp’s number',
            (await sheet
                .locator(
                    '[data-service="facebook"], [data-service="instagram"], [data-service="threads"], [data-service="whatsapp"]',
                )
                .count()) === 4,
        );
        await sheet.locator('[data-service="facebook"]').click();
        await sheet.locator('#connection-name').fill(PAGE_NAME);
        await sheet.locator('#connection-client_id').fill('e2e-app');
        await sheet.locator('#connection-client_secret').fill('e2e-secret');
        check(
            'a Page may be named, for an account that looks after several',
            (await sheet.locator('#connection-page').count()) === 1,
        );
        await sheet.locator('[data-connection-save]').click();
        await row(PAGE_NAME).waitFor();
        const toFacebook = row(PAGE_NAME).locator('[data-connection-sign-in]');
        check(
            'it says where Facebook is told of the address, and signs in with Facebook',
            (await toFacebook.innerText()).includes('Facebook Login') &&
                /Sign in with\s+Facebook/.test(await toFacebook.innerText()) &&
                (await toFacebook.locator('code').innerText()).includes(
                    'facebook/signed-in',
                ),
            await toFacebook.innerText(),
        );

        // A WhatsApp number: pasted, tried with Meta, and its webhook shown with its verify token
        await page.locator('[data-connection-add]').click();
        await sheet.locator('[data-service="whatsapp"]').click();
        await sheet.locator('#connection-name').fill(NUMBER_NAME);
        await sheet.locator('#connection-phone_number_id').fill('100000000001');
        await sheet.locator('#connection-access_token').fill('e2e-not-a-token');
        await sheet.locator('#connection-app_secret').fill('e2e-app-secret');
        await sheet.locator('[data-connection-save]').click();
        await sheet.locator('[data-connection-verdict]').waitFor({
            timeout: 20000,
        });
        const refused = await sheet
            .locator('[data-connection-verdict]')
            .innerText();
        check(
            'WhatsApp is asked, and a token it does not know is said so without repeating it',
            /WhatsApp/.test(refused) && !refused.includes('e2e-not-a-token'),
            refused,
        );
        await sheet.locator('[data-connection-save]').click();
        await row(NUMBER_NAME).waitFor();
        const metaHook = row(NUMBER_NAME).locator('[data-connection-hook]');
        check(
            'kept under Messaging, it shows its webhook address and the verify token Meta asks for',
            (await page
                .locator('[data-group="messaging"]', { hasText: NUMBER_NAME })
                .count()) === 1 &&
                /\/hooks\/whatsapp\//.test(await metaHook.innerText()) &&
                /verify token [0-9a-f]{32}/.test(await metaHook.innerText()),
            await metaHook.innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/connections-meta.png` });

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
            // A dropdown of its models, with a switch beside it for the free ones
            await page.waitForSelector('[data-free-toggle]');
            const advertised = Number(
                (
                    await page
                        .locator('label', {
                            has: page.locator('[data-free-toggle]'),
                        })
                        .innerText()
                ).match(/\((\d+)\)/)?.[1],
            );
            await page.locator('[data-model-picker]').click();
            const texts = await until(async () => {
                const now = await page
                    .locator('[data-model-list] [role="option"]')
                    .allInnerTexts();

                return now.length > 1 ? now : null;
            });
            const total = texts.length;
            // Each free one says so on a line of its own, under its name
            const badged = texts.filter((text) =>
                text.split('\n').some((line) => line.trim() === 'free'),
            ).length;
            check(
                'a host that gives prices offers a switch for the free ones',
                advertised > 0 && advertised < 200,
                `${advertised} free`,
            );
            check(
                'free ones are marked in the full list',
                badged > 0,
                `${badged} marked of ${total} listed`,
            );
            await page.keyboard.press('Escape');
            await page
                .locator('[data-model-list]')
                .waitFor({ state: 'detached' });

            await page.locator('[data-free-toggle]').click();
            await page.locator('[data-model-picker]').click();
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
            await page.keyboard.press('Escape');
            await page
                .locator('[data-model-list]')
                .waitFor({ state: 'detached' });

            await reopen(page);
            await page.locator('[data-connection-add]').click();
            await sheet.locator('[data-service="openrouter"]').click();
            await sheet.locator('[data-connection-test]').click();
            await page.waitForSelector('text=/Works: \\d+ model/', {
                timeout: 30000,
            });
            await page.waitForSelector('[data-free-toggle]');
            check(
                'the switch is remembered',
                (await page
                    .locator('[data-free-toggle]')
                    .getAttribute('aria-checked')) === 'true',
            );
            await page.locator('[data-free-toggle]').click(); // leave it as found
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
        await reopen(page);
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

        await open(page, `${base}/p/${project}/settings`);
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
            await open(viewer, `${base}/p/${project}/settings/connections`);
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
