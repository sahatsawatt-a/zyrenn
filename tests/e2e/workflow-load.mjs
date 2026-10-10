// A burst of webhooks at the running app, the way a busy sender would send
// them: how fast the hook answers, whether every event starts exactly one
// run (repeats too), and how many runs the workers finish a second. Not a
// browser suite -- it calls the hook itself -- so it runs only when named:
//
//     node tests/e2e/workflow-load.mjs              # 200 events, 20 at a time
//     E2E_LOAD=400 E2E_LOAD_AT_ONCE=40 node tests/e2e/workflow-load.mjs
//
// Each hook takes 600 requests a minute (3000 from one address); more is
// answered 429, which senders retry.
import { tinker } from './harness.mjs';

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';
const EVENTS = Number(process.env.E2E_LOAD ?? 200);
const AT_ONCE = Number(process.env.E2E_LOAD_AT_ONCE ?? 20);
const REPEATS = Math.round(EVENTS / 10);
const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e load ${RUN}`;
const TOKEN = `load${RUN}`.padEnd(32, 'x');
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

const results = [];
const problems = [];
const check = (label, passed, detail = '') => {
    results.push(
        `${passed ? 'PASS' : 'FAIL'}  ${label}${detail ? ` — ${detail}` : ''}`,
    );

    if (!passed) {
        problems.push(label);
    }
};

const definition = {
    nodes: [
        {
            id: 'hook',
            key: 'hook',
            type: 'trigger.webhook',
            position: { x: 0, y: 0 },
            config: { token: TOKEN, dedupe: 'id' },
        },
        {
            id: 'shape',
            key: 'shape',
            type: 'data.set',
            position: { x: 300, y: 0 },
            config: {
                fields: [{ name: 'id', value: '{{ trigger.body.id }}' }],
            },
        },
        {
            id: 'log',
            key: 'log',
            type: 'output.log',
            position: { x: 600, y: 0 },
            config: { message: 'got {{ input.id }}' },
        },
    ],
    edges: [
        { id: 'e1', source: 'hook', sourceHandle: 'out', target: 'shape' },
        { id: 'e2', source: 'shape', sourceHandle: 'out', target: 'log' },
    ],
};

const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(definition)}', true); $w->save(); $w->freeze(App\\Models\\Workflow\\WorkflowVersion::PUBLISHED, $u); $w->forceFill(['enabled' => true])->saveQuietly(); App\\Support\\Workflow\\Listeners::rebuild($w->fresh()); echo $w->ref_id;`,
);
const runs = (status = '') =>
    Number(
        tinker(
            `echo App\\Models\\Workflow\\Workflow::where('ref_id', '${ref}')->first()->runs()${status ? `->where('status', '${status}')` : ''}->count();`,
        ),
    );

const percentile = (values, p) => {
    const sorted = [...values].sort((a, b) => a - b);

    return sorted[
        Math.min(sorted.length - 1, Math.round(p * (sorted.length - 1)))
    ];
};

try {
    // Each event once, then a tenth of them again
    const sends = [
        ...Array.from({ length: EVENTS }, (_, n) => n + 1),
        ...Array.from({ length: REPEATS }, (_, n) => n + 1),
    ];
    const statuses = {};
    const took = [];
    const began = Date.now();
    let next = 0;

    const sender = async () => {
        while (next < sends.length) {
            const id = sends[next++];
            const at = performance.now();
            const response = await fetch(`${APP}/hooks/w/${TOKEN}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: `ev-${id}` }),
            });
            took.push(performance.now() - at);
            statuses[response.status] = (statuses[response.status] ?? 0) + 1;
        }
    };

    await Promise.all(Array.from({ length: AT_ONCE }, sender));
    const sent = Date.now() - began;

    check(
        'every send is taken',
        (statuses[202] ?? 0) === sends.length,
        JSON.stringify(statuses),
    );
    check(
        'the hook answers quickly under the burst',
        percentile(took, 0.95) < 1500,
        `p50 ${Math.round(percentile(took, 0.5))} ms, p95 ${Math.round(percentile(took, 0.95))} ms, ${sends.length} sends in ${(sent / 1000).toFixed(1)} s`,
    );

    // Until every run has ended, or two minutes
    let finished = 0;
    const waitFrom = Date.now();

    while (Date.now() - waitFrom < 120000) {
        finished = runs('succeeded') + runs('failed');

        if (finished >= EVENTS) {
            break;
        }

        await new Promise((resolve) => setTimeout(resolve, 1000));
    }

    const all = (Date.now() - began) / 1000;
    check(
        'each event starts exactly one run, repeats none',
        runs() === EVENTS,
        `${runs()} runs for ${EVENTS} events sent ${sends.length} times`,
    );
    check(
        'and every run succeeds',
        runs('succeeded') === EVENTS,
        `${runs('succeeded')} succeeded, ${(EVENTS / all).toFixed(1)} runs/s end to end (${all.toFixed(1)} s)`,
    );

    // Where any that didn't finish stand, to say why
    if (runs('succeeded') !== EVENTS) {
        results.push(
            `INFO  unfinished: ${tinker(
                `echo json_encode(App\\Models\\Workflow\\WorkflowNodeRun::whereHas('run', fn ($r) => $r->whereHas('workflow', fn ($w) => $w->where('ref_id', '${ref}'))->whereNotIn('status', ['succeeded'])) ->selectRaw('node_key, status, count(*) as n')->groupBy('node_key', 'status')->get());`,
            )} jobs: ${tinker(`echo DB::table('jobs')->count().' / failed '.DB::table('failed_jobs')->count();`)}`,
        );
    }

    const health = tinker(
        `$h = App\\Support\\Workflow\\Health::of(${user}, 1); echo json_encode($h['queue_ms']);`,
    );
    results.push(`INFO  wait for a worker, last hour: ${health}`);
} catch (error) {
    problems.push(`threw: ${error.message.split('\n')[0].slice(0, 200)}`);
} finally {
    tinker(`${user}->workflows()->where('title', '${TITLE}')->delete();`);
}

console.log(results.join('\n'));
console.log('problems:', problems.length ? problems.join('; ') : 'none');
process.exitCode = problems.length ? 1 : 0;
