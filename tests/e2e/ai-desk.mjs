// A project's AI desk, against the model on this machine (Ollama): a
// knowledge base made of its notes on the Knowledge page and found by
// meaning, tried with a question; then a customer's question answered from
// it in their chat, and their request for a feature thanked for, tagged and
// left to a person -- who makes a ticket of it.
//
// Needs Ollama with qwen2.5:7b and nomic-embed-text, reachable as
// http://ollama:11434 (E2E_OLLAMA), and E2E_MEMBER.
import {
    SHOTS,
    deleteProject,
    open,
    runBoard,
    shareProject,
    tinker,
} from './harness.mjs';

const OLLAMA = process.env.E2E_OLLAMA ?? 'http://ollama:11434';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);
// A model answering takes seconds, and one being loaded longer
const MODEL = { timeout: 120000, every: 1000 };

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `AI desk ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const pick = async (dropdown, value) => {
            await dropdown.click();
            await page.locator(`[data-pick="${value}"]`).click();
        };

        // What the shop knows, and the model it asks: set up behind the scenes
        const PROJECT = `App\\Models\\Project::where((new App\\Models\\Project)->getRouteKeyName(), '${project}')->firstOrFail()`;
        const ai = tinker(
            `$p = ${PROJECT}; $u = $p->members()->firstOrFail();
            foreach ([['Opening hours', 'We are open from 9 in the morning to 6 in the evening, Monday to Saturday. We are closed on Sundays.'], ['Shipping', 'We ship anywhere in Thailand within two days. Shipping is free over 1,000 baht.']] as [$t, $m]) {
                $n = new App\\Models\\Note\\Note; $n->project_id = $p->id; $n->created_by = $u->id; $n->title = $t; $n->content = App\\Support\\Markdown\\TiptapMarkdown::toDoc($m); $n->save();
            }
            $c = new App\\Models\\Connection(['name' => 'Local model', 'service' => 'ollama', 'settings' => ['base_url' => '${OLLAMA}', 'default_model' => 'qwen2.5:7b']]); $c->ownedBy($p, $u)->save();
            echo $c->ref_id;`,
        );

        // ------------------------------------------- A knowledge base
        await open(page, `${base}/p/${project}/knowledge`);
        await page.getByText('No knowledge yet').waitFor();
        await test('knowledge-new').click();
        await test('knowledge-title').fill('Shop answers');
        await test('knowledge-make').click();
        await soon(
            'made, it reads every note at once',
            async () =>
                /2 passages/.test(await test('knowledge-state').innerText()),
            async () => await test('knowledge-state').innerText(),
            { timeout: 30000, every: 500 },
        );

        await test('knowledge-settings').click();
        await pick(test('knowledge-connection'), ai);
        await test('knowledge-embed').fill('nomic-embed-text');
        await test('knowledge-embed').press('Tab');
        await soon(
            'given an embedding model, it is read again to be found by meaning',
            async () =>
                /by meaning/.test(
                    await test('knowledge-settings').innerText(),
                ) &&
                /2 passages/.test(await test('knowledge-state').innerText()),
            async () => await test('knowledge-state').innerText(),
            MODEL,
        );

        await test('knowledge-question').fill('Do you deliver to Chiang Mai?');
        await test('knowledge-question').press('Enter');
        await test('knowledge-found').waitFor({ timeout: 60000 });
        check(
            'a question finds the passage that answers it first',
            (
                await test('knowledge-found').locator('li').first().innerText()
            ).startsWith('Shipping'),
            await test('knowledge-found').innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/ai-desk-knowledge.png` });

        // -------------------------------------------------- An agent
        await open(page, `${base}/p/${project}/agents`);
        await page.getByText('No agents yet').waitFor();
        await test('agent-new').click();
        await test('agent-preset-desk').click();
        await test('agent-sheet').waitFor();
        check(
            'made from "Customer desk", it takes the model and knowledge there are',
            (await test('agent-version').count()) === 1 &&
                /qwen2\.5:7b/.test(await test('agent-version').innerText()),
            await test('agent-version').innerText(),
        );

        await test('agent-try-message').fill(
            'What time do you open on Saturday?',
        );
        await test('agent-try').click();
        await soon(
            'tried, it answers from what it knows, saying which version did',
            async () =>
                (await test('agent-answer').count()) === 1 &&
                /Version 1/.test(await test('agent-answer').innerText()),
            async () =>
                (await test('agent-answer').count())
                    ? await test('agent-answer').innerText()
                    : '',
            MODEL,
        );
        check(
            'with the passages it answered from beside it',
            /Opening hours/.test(
                await test('agent-answer')
                    .locator('[data-answer-sources]')
                    .innerText(),
            ),
            await test('agent-answer').innerText(),
        );

        // Another model: kept as a new version, the first still there to go back to
        await test('agent-sheet').locator('[data-model-picker]').click();
        await page.getByText('qwen2.5:14b', { exact: true }).click();
        await test('agent-note').fill('try the larger model');
        await test('agent-save').click();
        await soon(
            'changed, it is version 2, with the first kept',
            async () =>
                (await test('agent-version').count()) === 2 &&
                /qwen2\.5:14b/.test(
                    await test('agent-version').first().innerText(),
                ),
        );
        await test('agent-use-1').click();
        await soon(
            'and the first is used again, as version 3',
            async () =>
                (await test('agent-version').count()) === 3 &&
                /qwen2\.5:7b/.test(
                    await test('agent-version').first().innerText(),
                ) &&
                /Back to version 1/.test(
                    await test('agent-version').first().innerText(),
                ),
        );
        await page.screenshot({ path: `${SHOTS}/ai-desk-agent.png` });
        await page.keyboard.press('Escape');
        await test('agent-sheet').waitFor({ state: 'detached' });

        // ---------------------------------------------- The AI desk
        const agent = tinker(
            `echo App\\Models\\Agent\\Agent::query()->of(${PROJECT})->sole()->ref_id;`,
        );
        tinker(
            `$p = ${PROJECT}; $u = $p->members()->firstOrFail();
            $d = App\\Support\\Workflow\\Templates::all()['ai-desk']['definition'];
            $d['nodes'] = array_map(fn ($n) => $n['key'] === 'ask' ? [...$n, 'config' => [...$n['config'], 'agent' => '${agent}']] : $n, $d['nodes']);
            $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($p, $u); $w->title = 'AI'; $w->forceFill(['definition' => $d])->save();
            echo $w->ref_id;`,
        );
        const [workflow, revision] = tinker(
            `$w = ${PROJECT}->workflows()->sole(); echo $w->ref_id.' '.$w->revision;`,
        ).split(' ');
        const call = (method, url, data) =>
            page.evaluate(
                async ({ method, url, data }) => {
                    const init = {
                        method,
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': decodeURIComponent(
                                document.cookie.match(
                                    /XSRF-TOKEN=([^;]+)/,
                                )?.[1] ?? '',
                            ),
                        },
                    };
                    init.body = JSON.stringify(data);

                    return (await fetch(url, init)).status;
                },
                { method, url, data },
            );
        check(
            'the desk is made from its template, published and switched on',
            (await call('POST', `${base}/workflows/${workflow}/publish`, {
                revision: Number(revision),
            })) === 200 &&
                (await call('PATCH', `${base}/workflows/${workflow}/enabled`, {
                    enabled: true,
                })) === 200,
        );

        // A customer's chat, and what they write in it, as a bridge carries it in
        const room = tinker(
            `$p = ${PROJECT}; $r = App\\Models\\Chat\\ChatRoom::startGroup($p, $p->members()->firstOrFail(), 'Ada on LINE'); $r->forceFill(['link' => ['service' => 'line', 'connection' => 'none', 'chat' => 'U1']])->save(); $r->becomeCustomers(); echo $r->ref_id;`,
        );
        const writes = (words) =>
            tinker(
                `$r = App\\Models\\Chat\\ChatRoom::where('ref_id', '${room}')->firstOrFail(); $m = $r->messages()->create(['role' => 'workflow', 'content' => '${words}', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]); broadcast(new App\\Events\\ChatMessagePosted($r, $m)); echo $m->id;`,
            );
        const said = () => page.locator('[data-chat-messages]').innerText();

        await open(page, `${base}/chats/${room}`);
        await page.locator('[data-chat-desk]').waitFor();
        check(
            'AI answers here until someone takes the chat',
            (await test('desk-ai').getAttribute('data-state')) === 'checked',
        );

        writes('What time do you open on Saturday?');
        await soon(
            'their question is answered from what the shop knows',
            async () =>
                (await page.locator('[data-chat-workflow]').count()) >= 1 &&
                /\b9\b|nine/i.test(
                    await page
                        .locator('[data-chat-workflow]')
                        .last()
                        .innerText(),
                ),
            said,
            MODEL,
        );
        await soon('and the chat waits on them', async () =>
            /Waiting/.test(await test('desk-status').innerText()),
        );

        // What it answered from, shown to the team under its answer, to be read
        const answered = page.locator('[data-chat-workflow]').filter({
            has: page.locator('[data-answer-source]'),
        });
        await answered.first().locator('[data-answer-source]').first().click();
        await page.locator('[data-answer-passage]').first().waitFor();
        check(
            'the answer shows its source, opened to the passage itself',
            /Opening hours/.test(await answered.first().innerText()) &&
                /9 in the morning/.test(
                    await page
                        .locator('[data-answer-passage]')
                        .first()
                        .innerText(),
                ),
            await answered.first().innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/ai-desk-sources.png` });

        writes(
            'Could you add gift wrapping to your shop? I would love that feature.',
        );
        await soon(
            'a request is thanked for, tagged, and noted for a person',
            async () =>
                (await test('desk-tag').count()) === 1 &&
                /request/.test(
                    await page.locator('[data-team-notes]').innerText(),
                ),
            said,
            MODEL,
        );
        check(
            'it is tagged as a feature',
            (await test('desk-tag').innerText()).includes('feature'),
            await test('desk-tag').innerText(),
        );
        await soon('and left open for someone', async () =>
            /Open/.test(await test('desk-status').innerText()),
        );
        await page.screenshot({ path: `${SHOTS}/ai-desk-chat.png` });

        // ------------------------------------------- A person makes the ticket
        const asked = page
            .locator('[data-chat-workflow]')
            .filter({ hasText: 'Could you add gift wrapping' });
        await asked.hover();
        await asked.locator('[data-test="chat-promote"]').click();
        await test('promote-dialog').waitFor();
        await test('promote-feature').click();
        await test('promote-title').fill('Gift wrapping');
        await test('promote-save').click();
        await soon(
            'the request is a ticket of the project',
            async () => (await test('desk-ticket').count()) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/ai-desk-ticket.png` });

        // -------------------------------- AI off: a reply suggested instead
        await test('desk-ai').click();
        await soon(
            'AI is switched off in this chat',
            async () =>
                (await test('desk-ai').getAttribute('data-state')) ===
                'unchecked',
        );
        const before = await page.locator('[data-chat-workflow]').count();
        writes('Do you deliver to Chiang Mai?');
        await soon('their message arrives, and nothing answers it', async () =>
            /Chiang Mai/.test(await said()),
        );
        await test('suggest-reply').click();
        const input = page.locator('[data-chat-input]');
        await soon(
            'a reply is suggested in what is being written, for a person to send',
            async () => (await input.inputValue()).trim().length > 10,
            async () => await input.inputValue(),
            MODEL,
        );
        check(
            'it says where it came from before it is sent',
            /Shipping/.test(
                await page.locator('[data-suggestion]').innerText(),
            ),
            await page.locator('[data-suggestion]').innerText(),
        );
        check(
            'and nothing went to them on its own',
            (await page.locator('[data-chat-workflow]').count()) === before + 1,
        );
        await page.screenshot({ path: `${SHOTS}/ai-desk-suggest.png` });
        await page.keyboard.press('Enter');
        await soon('sent by the person, it is answered', async () =>
            /Waiting/.test(await test('desk-status').innerText()),
        );

        // ------------------------------------------------------ On a phone
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, `${base}/p/${project}/knowledge`);
        await test('knowledge-base').waitFor();
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        check(
            'the knowledge page fits a phone',
            overflow <= 0,
            `${overflow}px too wide`,
        );
        await page.screenshot({ path: `${SHOTS}/ai-desk-phone.png` });
    },
    { canvas: false },
);
