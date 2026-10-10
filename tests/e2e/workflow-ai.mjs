// An Ask AI step answered by a real model, its answer shown in the run panel
// as it is written, then kept as the step's output.
//
// Needs an Ollama the app can reach, with the model below pulled:
//   E2E_OLLAMA=http://172.16.8.1:11434 E2E_OLLAMA_MODEL=qwen2.5:7b node tests/e2e/workflow-ai.mjs
import { SHOTS, runBoard, tinker } from './harness.mjs';

const HOST = process.env.E2E_OLLAMA ?? 'http://172.16.8.1:11434';
const MODEL = process.env.E2E_OLLAMA_MODEL ?? 'qwen2.5:7b';
const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e ai ${RUN}`;
const NAME = `e2e ollama ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const connection = tinker(
    `echo ${user}->connections()->create(['name' => '${NAME}', 'service' => 'ollama', 'settings' => ['base_url' => '${HOST}', 'default_model' => '${MODEL}']])->ref_id;`,
);

// Long enough an answer that it is seen being written
const definition = {
    nodes: [
        {
            id: 'start',
            key: 'start',
            type: 'trigger.manual',
            position: { x: 0, y: 0 },
            config: {},
        },
        {
            id: 'ask',
            key: 'ask',
            type: 'ai.chat',
            position: { x: 320, y: 0 },
            config: {
                connection,
                prompt: 'Count from 1 to 40 in English words, separated by commas, and nothing else.',
            },
        },
    ],
    edges: [{ id: 'e1', source: 'start', sourceHandle: 'out', target: 'ask' }],
};

const ref = tinker(
    `$w = (new App\\Models\\Workflow\\Workflow)->ownedBy(${user}, ${user}); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(definition)}', true); $w->save(); echo $w->ref_id;`,
);

await runBoard(
    `/workflows/${ref}`,
    async ({ page, check, afterwards }) => {
        afterwards(() =>
            tinker(
                `${user}->workflows()->where('title', '${TITLE}')->delete(); ${user}->connections()->where('name', '${NAME}')->delete();`,
            ),
        );

        const runStatus = page.locator('[data-run-status]');
        const stream = page.locator('[data-run-stream]');

        await page.locator('[data-step="ask"]').waitFor();
        await page.locator('[data-run]').click();

        await stream.waitFor({ timeout: 60000 });
        const early = await stream.innerText();
        await page.screenshot({ path: `${SHOTS}/workflow-ai-writing.png` });
        await page.waitForTimeout(700);
        const later = (await stream.count()) ? await stream.innerText() : '';
        check(
            'the answer shows in the run panel while it is being written',
            /is writing\s*\S/i.test(early),
            early.replace(/\s+/g, ' ').slice(0, 120),
        );
        check(
            'and grows as it comes',
            later === '' || later.length >= early.length,
            `${early.length} → ${later.length}`,
        );

        await runStatus
            .filter({ hasText: 'Succeeded' })
            .waitFor({ timeout: 120000 });
        await page.locator('[data-run-step="ask"]').click();
        await page.locator('[data-run-output]').waitFor();
        const output = await page.locator('[data-run-output]').innerText();
        check(
            'once done, the writing gives way to the step’s output',
            (await stream.count()) === 0 && /forty/i.test(output),
            output.replace(/\s+/g, ' ').slice(0, 120),
        );
    },
    { canvas: false },
);
