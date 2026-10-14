// An agent given tools, on its page: Zyrenn's own -- reading a project's
// tables -- switched on one by one, kept as a new version, and tried: it
// looks the price up with them, and shows what it used beside its answer.
//
// Needs Ollama with qwen2.5:7b, reachable as http://ollama:11434 (E2E_OLLAMA).
// With E2E_ACCOUNT=real (tests/e2e/.env) it runs on the account kept for real
// services instead, in its own space, through its OpenRouter connection and a
// free model (E2E_REAL_MODEL), and takes away what it made.
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
const MODEL = { timeout: 180000, every: 1000 };
const REAL = process.env.E2E_ACCOUNT === 'real';
const FREE =
    process.env.E2E_REAL_MODEL ?? 'nvidia/nemotron-3-super-120b-a12b:free';

if (REAL && !FREE.endsWith(':free')) {
    throw new Error(`Only free models are used: ${FREE} is not one`);
}

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const base = new URL(page.url()).origin;
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const USER = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->firstOrFail()`;
        const TITLE = `Agent tools ${RUN} prices`;
        let project = null;
        let owner;

        if (REAL) {
            // One's own space, and the OpenRouter connection there already
            owner = USER;
            afterwards(() =>
                tinker(
                    `$u = ${USER}; App\\Models\\Agent\\Agent::query()->of($u)->where('created_at', '>=', now()->subHour())->where('name', 'Helper')->get()->each->delete(); $u->tables()->where('title', '${TITLE}')->get()->each(function ($t) { App\\Support\\Table\\TableStorage::drop($t); $t->delete(); }); echo 'ok';`,
                ),
            );
        } else {
            ({ project } = await shareProject(
                page,
                `Agent tools ${RUN}`,
                MEMBER,
            ));
            afterwards(() => deleteProject(page, base, project));
            owner = `App\\Models\\Project::where((new App\\Models\\Project)->getRouteKeyName(), '${project}')->firstOrFail()`;
            tinker(
                `$p = ${owner}; $u = $p->members()->firstOrFail(); $c = new App\\Models\\Connection(['name' => 'Local model', 'service' => 'ollama', 'settings' => ['base_url' => '${OLLAMA}', 'default_model' => 'qwen2.5:7b']]); $c->ownedBy($p, $u)->save(); echo 'ok';`,
            );
        }

        // A price list, behind the scenes
        tinker(
            `$o = ${owner}; $t = App\\Models\\Table\\Table::factory()->create(['user_id' => $o instanceof App\\Models\\User ? $o->id : null, 'project_id' => $o instanceof App\\Models\\Project ? $o->id : null, 'title' => '${TITLE}']);
            App\\Support\\Table\\TableStorage::newColumn($t, ['label' => 'Item', 'type' => 'varchar']);
            App\\Support\\Table\\TableStorage::newColumn($t, ['label' => 'Price', 'type' => 'varchar']);
            $t = $t->fresh('columns');
            foreach ([['Mug', '250 baht'], ['Tote bag', '390 baht'], ['Notebook', '120 baht']] as [$i, $v]) { App\\Support\\Table\\TableStorage::insertRow($t, [$t->columns->firstWhere('label', 'Item')->name => $i, $t->columns->firstWhere('label', 'Price')->name => $v]); }
            echo 'ok';`,
        );

        await open(
            page,
            REAL ? `${base}/agents` : `${base}/p/${project}/agents`,
        );
        await test('agent-new').click();
        await test('agent-preset-helper').click();
        await test('agent-sheet').waitFor();

        // On the real account: its OpenRouter connection, and a free model chosen from the dropdown
        if (REAL) {
            await test('agent-sheet').locator('[data-model-picker]').click();
            await page.locator(`[data-model="${FREE}"]`).click();
            check(
                'a free model is chosen from the dropdown',
                (
                    await test('agent-sheet')
                        .locator('[data-model-picker]')
                        .innerText()
                ).includes(FREE),
            );
        }

        // ------------------------------------------------------ Its tools
        await test('agent-tools-toggle').click();
        await page.locator('[data-tool="get-table"]').waitFor();
        check(
            "Zyrenn's own tools are listed, those that change things marked",
            /changes things/.test(await test('agent-tools').innerText()),
        );
        await page.locator('[data-tool="list-tables"]').click();
        await page.locator('[data-tool="get-table"]').click();
        await test('agent-note').fill('can read the price list');
        await test('agent-save').click();
        await soon(
            'given two tools, it is version 2',
            async () =>
                (await test('agent-version').count()) === 2 &&
                /2 given/.test(await test('agent-tools-toggle').innerText()),
        );

        // ------------------------------------------------------ Trying it
        await test('agent-try-message').fill('How much is a tote bag?');
        await test('agent-try').click();
        await soon(
            'it looks the price up with its tools, and says it',
            async () =>
                (await test('agent-answer').count()) === 1 &&
                /390/.test(await test('agent-answer').innerText()),
            async () =>
                (await test('agent-answer').count())
                    ? await test('agent-answer').innerText()
                    : '',
            MODEL,
        );
        const used = test('agent-answer').locator('[data-answer-action]');
        check(
            'and shows which tools it used',
            (await used.count()) >= 1,
            await test('agent-answer').innerText(),
        );
        await used.last().click();
        await page.locator('[data-answer-action-detail]').waitFor();
        check(
            'opened, a tool shows what it was asked and what it said',
            /390 baht/.test(
                await page.locator('[data-answer-action-detail]').innerText(),
            ),
            await page.locator('[data-answer-action-detail]').innerText(),
        );
        await test('agent-sheet').screenshot({
            path: `${SHOTS}/agent-tools.png`,
        });
    },
    { canvas: false },
);
