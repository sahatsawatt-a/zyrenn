// Several workers at once, on the real database: runs that branch twelve
// ways and meet again, all started together. Each step that the branches
// meet at must start once, however the workers interleave.
//
// Give it several workers first, and put them back after:
//   docker compose up -d --scale worker=3 worker
//   node tests/e2e/workflow-workers.mjs
//   docker compose up -d --scale worker=1 worker
import { tinker } from './harness.mjs';

const RUNS = Number(process.env.RUNS ?? 8);
const BRANCHES = 12;
const TITLE = `e2e workers ${Date.now().toString().slice(-6)}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const branches = Array.from({ length: BRANCHES }, (_, i) => `b${i + 1}`);
const definition = {
    nodes: [
        {
            id: 'start',
            key: 'start',
            type: 'trigger.manual',
            config: { payload: '{"n": 1}' },
        },
        ...branches.map((b, i) => ({
            id: b,
            key: b,
            type: 'data.set',
            config: { fields: [{ name: 'n', value: String(i + 1) }] },
        })),
        {
            id: 'join',
            key: 'join',
            type: 'data.set',
            config: {
                fields: [
                    {
                        name: 'total',
                        value: `{{ sum(${branches.map((b) => `input.${b}.n`).join(', ')}) }}`,
                    },
                ],
            },
        },
    ],
    edges: [
        ...branches.map((b) => ({ id: `s-${b}`, source: 'start', target: b })),
        ...branches.map((b) => ({ id: `${b}-j`, source: b, target: 'join' })),
    ],
};

tinker(
    `$w = (new App\\Models\\Workflow\\Workflow)->ownedBy(${user}, ${user}); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(definition)}', true); $w->save(); $v = $w->freeze('test', ${user}); foreach (range(1, ${RUNS}) as $i) { App\\Support\\Workflow\\Engine::start($w, $v, ${user}); } echo 'ok';`,
);

const results = [];
const check = (label, passed, detail = '') =>
    results.push(
        `${passed ? 'PASS' : 'FAIL'}  ${label}${detail ? ` — ${detail}` : ''}`,
    );

let state = '';

for (let waited = 0; waited < Number(process.env.WAIT ?? 90); waited++) {
    state = tinker(
        `$runs = App\\Models\\Workflow\\Workflow::where('title', '${TITLE}')->first()->runs()->get(); echo json_encode(['open' => $runs->filter(fn ($r) => ! $r->status->isFinal())->count(), 'succeeded' => $runs->filter(fn ($r) => $r->status->value === 'succeeded')->count(), 'joins' => App\\Models\\Workflow\\WorkflowNodeRun::whereIn('workflow_run_id', $runs->pluck('id'))->where('node_key', 'join')->get()->map(fn ($s) => [$s->attempt, $s->output['total'] ?? null])->all(), 'counted' => $runs->filter(fn ($r) => $r->executed_steps !== (int) $r->steps()->sum('attempt'))->count(), 'retried' => App\\Models\\Workflow\\WorkflowNodeRun::whereIn('workflow_run_id', $runs->pluck('id'))->where('attempt', '>', 1)->count()]);`,
    );

    if (JSON.parse(state).open === 0) {
        break;
    }

    await new Promise((resolve) => setTimeout(resolve, 1000));
}

const found = JSON.parse(state);
check(
    `all ${RUNS} runs ended, each succeeding`,
    found.open === 0 && found.succeeded === RUNS,
    state,
);
check(
    'the step every branch meets at started once in each run, with every branch in it',
    found.joins.length === RUNS &&
        found.joins.every(([attempt, total]) => attempt === 1 && total === 78),
);
// A step whose worker died is begun again, and that counts against the run's budget too
check(
    'each run counted every step it began, tries again included',
    found.counted === 0,
    `${found.retried} steps begun again after a worker died`,
);

tinker(
    `App\\Models\\Workflow\\Workflow::where('title', '${TITLE}')->delete();`,
);

console.log(results.join('\n'));
process.exitCode = results.some((line) => line.startsWith('FAIL')) ? 1 : 0;
