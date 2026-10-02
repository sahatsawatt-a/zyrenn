// A chat answered by a real model: a connection added on the settings page,
// a room started from the list, a question put to Ollama, and the answer
// drawn the way a note is -- then still there after a reload.
//
// Needs an Ollama the app can reach, with the model below pulled:
//   E2E_OLLAMA=http://172.16.8.1:11434 E2E_OLLAMA_MODEL=qwen2.5:7b node tests/e2e/chat.mjs
import { deleteProject, runBoard, shareProject, tinker } from './harness.mjs';

const HOST = process.env.E2E_OLLAMA ?? 'http://172.16.8.1:11434';
const MODEL = process.env.E2E_OLLAMA_MODEL ?? 'qwen2.5:7b';

// Named for this run, so one left behind by an earlier run is never mistaken for it
const NAME = `e2e ollama ${Date.now().toString().slice(-6)}`;

await runBoard(
    '/settings/ai-connections',
    async ({ page, check, afterwards }) => {
        const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
        // Rooms are removed with the connection's name on them; the rooms themselves by title
        afterwards(() =>
            tinker(
                `${user}->aiConnections()->where('name', '${NAME}')->delete(); ${user}->chatRooms()->where('title', 'like', 'Reply with exactly%')->delete();`,
            ),
        );

        await page.getByRole('button', { name: /add a connection/i }).click();
        // Not a login: without this a browser fills the host with an email and the key with a password
        const attr = (id, name) => page.locator(id).getAttribute(name);
        check(
            'the form says it is not a login, so a browser does not fill it from one',
            (await attr('#ai-key', 'autocomplete')) === 'new-password' &&
                (await attr('#ai-name', 'autocomplete')) === 'off' &&
                (await attr('#ai-host', 'autocomplete')) === 'off' &&
                (await attr('#ai-key', 'data-1p-ignore')) !== null,
        );

        const kinds = await page.locator('[data-kind]').count();
        check(
            'more than two kinds can be connected',
            kinds >= 6,
            `${kinds} kinds`,
        );

        await page.locator('[data-kind="custom"]').click();
        check(
            'one with no usual address asks for it',
            (await page.locator('#ai-host').getAttribute('required')) !== null,
        );
        // OpenRouter lists its models, with their prices, without a key: a try
        // of the form shows which cost nothing. Needs the app to reach the internet.
        await page.locator('[data-kind="openrouter"]').click();
        await page.locator('[data-connection-test]').click();
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
            await page
                .getByRole('button', { name: /add a connection/i })
                .click();
            await page.locator('[data-kind="openrouter"]').click();
            await page.locator('[data-connection-test]').click();
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

        await page.locator('[data-kind="ollama"]').click();

        // A host that cannot be reached: Save tries it first, and keeps nothing
        await page.locator('#ai-name').fill(NAME);
        await page.locator('#ai-host').fill('http://127.0.0.1:9');
        await page.locator('[data-connection-save]').click();
        await page.waitForSelector('[data-connection-verdict]');
        const verdict = await page
            .locator('[data-connection-verdict]')
            .innerText();
        check(
            'a host that does not answer is reported before anything is saved',
            verdict.includes('Could not reach') &&
                (await page
                    .locator('[data-connection]', { hasText: NAME })
                    .count()) === 0,
            verdict,
        );
        check(
            'and saving it takes asking twice',
            (await page.locator('[data-connection-save]').innerText()).includes(
                'Save anyway',
            ),
        );

        // Mending the host makes the verdict stale, so it is tried again
        await page.locator('#ai-host').fill(HOST);
        check(
            'changing the host clears the verdict',
            (await page.locator('[data-connection-verdict]').count()) === 0,
        );
        await page.locator('[data-connection-test]').click();
        await page.waitForSelector('text=/Works: \\d+ model/', {
            timeout: 30000,
        });
        check('Test connection finds the models without saving', true);
        check(
            'it has saved nothing yet',
            (await page
                .locator('[data-connection]', { hasText: NAME })
                .count()) === 0,
        );

        await page.locator('[data-model-picker]').click();
        await page.locator('input[aria-label="Search models"]').fill(MODEL);
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        check(
            'the usual model is chosen from what the host offers',
            (await page.locator('[data-model-picker]').innerText()).includes(
                MODEL,
            ),
        );

        await page.screenshot({
            path: '/tmp/zyrenn-e2e/connection-form.png',
            fullPage: true,
        });
        await page.locator('[data-connection-save]').click();
        await page.waitForSelector(`text=${NAME}`);
        check('a connection that works is saved', true);

        const row = page.locator('li', { hasText: NAME });
        check(
            'it never shows a key it was not given',
            !(await row.innerText()).includes('key saved'),
        );

        await row.getByRole('button', { name: /check/i }).click();
        await page.waitForSelector('text=/Works: \\d+ model/', {
            timeout: 30000,
        });
        check('Check reaches the host and finds its models', true);

        await page.goto(new URL('/chats', page.url()).href, {
            waitUntil: 'networkidle',
        });
        await page.getByRole('button', { name: /new chat/i }).click();
        await page.waitForURL(/\/chats\/\w+/, { timeout: 30000 });
        await page.waitForSelector('[data-chat-input]');
        check(
            'a new chat is answered by the connection just made',
            (await page.locator('[data-chat-no-agent]').count()) === 0,
        );

        await page
            .locator('[data-chat-input]')
            .fill(
                'Reply with exactly this Markdown and nothing else:\n\n# Fruit list\n\n- apple\n- pear\n\n**Done**',
            );
        await page.locator('[data-chat-input]').press('Enter');

        await page.waitForSelector('[data-chat-user]');
        check('what was said appears at once', true);

        await page.waitForSelector('[data-chat-assistant] .ProseMirror', {
            timeout: 120000,
        });
        check('the answer arrives', true);

        const answer = page.locator('[data-chat-assistant] .ProseMirror');
        await page.waitForSelector('[data-chat-assistant] .ProseMirror li');
        check(
            'it is drawn as a note is: a heading and a list',
            (await answer.locator('h1, h2').count()) >= 1 &&
                (await answer.locator('li').count()) >= 2,
            await answer.innerText(),
        );
        check(
            'and is only for reading',
            (await answer.getAttribute('contenteditable')) === 'false',
        );
        check(
            "the answer's stream has been replaced by it",
            (await page.locator('[data-chat-streaming]').count()) === 0,
        );

        await page.waitForTimeout(500);
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('[data-chat-assistant] .ProseMirror li');
        check(
            'after a reload both messages are still there',
            (await page.locator('[data-chat-user]').count()) === 1 &&
                (await page.locator('[data-chat-assistant]').count()) === 1,
        );
        check(
            'and the chat is named after the first message',
            (
                await page
                    .locator('input[aria-label="Chat title"]')
                    .inputValue()
            ).startsWith('Reply with exactly'),
        );

        await page
            .locator('[data-chat-input]')
            .fill('Now say only the word: ok');
        await page.locator('[data-chat-input]').press('Enter');
        await page.waitForFunction(
            () =>
                document.querySelectorAll('[data-chat-assistant]').length === 2,
            null,
            { timeout: 120000 },
        );
        check('a second question is answered with the first in mind', true);
        await page.screenshot({ path: '/tmp/zyrenn-e2e/chat.png' });

        // The agent's settings: pick a model from what the host offers
        await page.getByRole('button', { name: /agent/i }).click();
        // The connection is chosen from the app's own dropdown, not the browser's
        await page.locator('[data-connection-select]').click();
        await page.waitForSelector('[role="option"]');
        const choices = await page.getByRole('option').allInnerTexts();
        check(
            'the connection dropdown lists none and the connections, with their kinds',
            choices.length >= 2 &&
                choices[0].includes('None') &&
                choices.some(
                    (text) => text.includes(NAME) && text.includes('Ollama'),
                ),
            choices.join(' | '),
        );
        await page.screenshot({
            path: '/tmp/zyrenn-e2e/connection-dropdown.png',
        });

        await page.getByRole('option', { name: /^None/ }).click();
        await page.waitForTimeout(300);
        check(
            'choosing none takes the model choice away',
            (await page.locator('[data-model-picker]').count()) === 0,
        );

        await page.locator('[data-connection-select]').click();
        await page.getByRole('option', { name: new RegExp(NAME) }).click();
        await page.waitForSelector('[data-model-picker]');
        check(
            'choosing it again brings it back, starting from its usual model',
            (await page.locator('[data-model-picker]').innerText()).includes(
                'the usual',
            ),
        );

        await page.locator('[data-model-picker]').click();
        await page.waitForSelector('[data-model-list] [role="option"]');
        const all = await page
            .locator('[data-model-list] [role="option"]')
            .count();
        check(
            'the model list shows what the host offers',
            all >= 2,
            `${all} options`,
        );

        await page.locator('input[aria-label="Search models"]').fill('qwen');
        const narrowed = await page
            .locator('[data-model-list] [role="option"]')
            .allInnerTexts();
        check(
            'typing narrows it, with the typed name offered as it is',
            narrowed.some((text) => text.includes('qwen2.5:7b')) &&
                narrowed.some((text) => text.startsWith('Use “qwen”')) &&
                !narrowed.some((text) => text.includes('gemma')),
            narrowed.join(' | '),
        );

        // Not the usual model, so that picking it can be told from not picking
        await page
            .locator('input[aria-label="Search models"]')
            .fill('qwen3:8b');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const picked = await page.locator('[data-model-picker]').innerText();
        check(
            'Enter picks it and closes the list',
            (await page.locator('[data-model-list]').count()) === 0 &&
                picked.includes('qwen3:8b') &&
                !picked.includes('the usual'),
            picked,
        );

        await page.getByRole('button', { name: /^save$/i }).click();
        await page.waitForTimeout(800);
        await page.getByRole('button', { name: /agent/i }).click();
        check(
            'the choice is kept',
            (await page.locator('[data-model-picker]').innerText()).includes(
                'qwen3:8b',
            ),
        );

        await page.locator('[data-model-picker]').click();
        await page.waitForSelector('[data-model-list] [role="option"]');
        await page.getByRole('option', { name: /the usual/i }).click();
        await page.waitForTimeout(400);
        check(
            'and the usual model can be gone back to',
            (await page.locator('[data-model-picker]').innerText()).includes(
                'the usual',
            ),
        );
        await page.screenshot({ path: '/tmp/zyrenn-e2e/chat-models.png' });
        await page.keyboard.press('Escape');

        // A personal chat belongs to its owner, not to a group: a project's sidebar has none
        const chatLink = page
            .locator('[data-sidebar="menu"]')
            .getByRole('link', { name: 'AI chat', exact: true });
        await page.goto(new URL('/notes', page.url()).href, {
            waitUntil: 'networkidle',
        });
        check('your own sidebar has AI chat', (await chatLink.count()) === 1);

        const { project, base } = await shareProject(
            page,
            `Chat check ${Date.now().toString().slice(-6)}`,
            'e2e-member@example.com',
            'viewer',
        );
        await page.goto(`${base}/p/${project}/notes`, {
            waitUntil: 'networkidle',
        });
        check(
            "a project's sidebar does not",
            (await chatLink.count()) === 0 &&
                (await page.getByRole('link', { name: /^notes$/i }).count()) >=
                    1,
        );
        await deleteProject(page, base, project);
    },
    { canvas: false },
);
