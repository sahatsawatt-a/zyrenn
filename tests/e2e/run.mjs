// Every browser suite, several at once and quietly, in the order that ends
// soonest: the longest first, each lane taking the next as it comes free, so
// no long suite is left to run alone at the end. How long each took is kept
// for next time; a suite never timed counts as the longest.
//
// A lane has its own two accounts, so suites running side by side never see
// each other's notes, projects or chats. Lane 1 is the seeded pair
// (test@example.com, e2e-member@example.com); the others are made here.
//
//     node tests/e2e/run.mjs                  # everything, 3 lanes
//     node tests/e2e/run.mjs workflows chat   # only these
//     E2E_LANES=1 node tests/e2e/run.mjs      # one at a time
//     node tests/e2e/run.mjs --headed         # watch them
//     E2E_TIMES=1 node tests/e2e/run.mjs maps # and where each one's time went
//     node tests/e2e/run.mjs --listen         # run what the app asks for
//
// Each run is written down as it goes, in runs/ beside the timings, for the
// app's page of test runs to show. With --listen the runner waits for that
// page instead: it starts what is asked for in requests/, one run at a time,
// and says it is there in listener.json.
//
// workflow-workers scales the queue workers up and down, so it runs only when
// named. The app serves 4 requests at a time (PHP_CLI_SERVER_WORKERS); more
// than 3 lanes mostly wait on it.
import { spawn } from 'node:child_process';
import {
    existsSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    renameSync,
    rmSync,
    unlinkSync,
    writeFileSync,
} from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { KEPT, tinker } from './harness.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const TIMINGS = join(KEPT, 'timings.json');
const RUNS = join(KEPT, 'runs');
const REQUESTS = join(KEPT, 'requests');
const LISTENER = join(KEPT, 'listener.json');
// How many runs are kept, pictures and all; the oldest go first
const RUNS_KEPT = 20;
const ALONE = ['workflow-workers', 'workflow-load'];
// Suites that fail at full speed for a reason of the app's, run as slowly as
// before until it is fixed. note-links: the "Show as" menu of a pasted link
// closes at once when the paste comes quickly after the card before it.
const STEADY = { 'note-links': 40 };
// guide-shots takes the guide's pictures: it checks nothing, and is run by hand
const NOT_SUITES = ['harness', 'run', 'guide-shots'];

const args = process.argv.slice(2);
const named = args.filter((arg) => !arg.startsWith('--'));

const all = readdirSync(HERE)
    .filter((file) => file.endsWith('.mjs'))
    .map((file) => basename(file, '.mjs'))
    .filter((name) => !NOT_SUITES.includes(name));

const unknown = named.filter((name) => !all.includes(name));

if (unknown.length) {
    console.error(`No such suite: ${unknown.join(', ')}`);
    process.exit(2);
}

const readTimings = () => {
    try {
        return JSON.parse(readFileSync(TIMINGS, 'utf8'));
    } catch {
        return {};
    }
};

// Each lane's accounts: lane 1 the seeded pair (or E2E_EMAIL and E2E_MEMBER,
// to keep clear of someone else's run), the others alike in name
const accounts = (lane) =>
    lane === 1
        ? {
              owner: process.env.E2E_EMAIL ?? 'test@example.com',
              member: process.env.E2E_MEMBER ?? 'e2e-member@example.com',
          }
        : {
              owner: `e2e-lane${lane}@example.com`,
              member: `e2e-lane${lane}-member@example.com`,
          };

const seconds = (ms) => `${(ms / 1000).toFixed(1)}s`;

/** Written whole and swapped in, so the app never reads half of one. */
function writeJson(path, value) {
    writeFileSync(`${path}.part`, JSON.stringify(value, null, 2));
    renameSync(`${path}.part`, path);
}

/** Only the newest RUNS_KEPT runs stay, each with its pictures. */
function prune() {
    const kept = readdirSync(RUNS)
        .filter((file) => file.endsWith('.json'))
        .sort()
        .reverse();

    for (const file of kept.slice(RUNS_KEPT)) {
        const id = basename(file, '.json');
        rmSync(join(RUNS, file), { force: true });
        rmSync(join(RUNS, id), { recursive: true, force: true });
    }
}

/**
 * Runs the suites `names` in `lanes` lanes, longest first, saying each one
 * as it ends and writing the whole run down in runs/<id>.json as it goes.
 * `watch` shows the browser, full screen and slow, as when run by hand to be
 * watched. `stopped()` is asked between suites, and stops any still going.
 */
async function runSuites(
    names,
    { id, from, lanes, headed, watch = false, stopped = () => false },
) {
    const timings = readTimings();
    const queue = names
        .map((name) => ({ name, took: timings[name] ?? Infinity }))
        .sort((a, b) => b.took - a.took)
        .map(({ name }) => name);
    const used = Math.min(lanes, queue.length);
    const shown = headed || watch;

    if (used > 1) {
        const made = Array.from({ length: used - 1 }, (_, at) =>
            accounts(at + 2),
        )
            .flatMap(({ owner, member }) => [
                [owner, 'Test User'],
                [member, 'E2E Member'],
            ])
            .map(
                ([email, name]) =>
                    `App\\Models\\User::firstOrCreate(['email' => '${email}'], ['name' => '${name}', 'password' => Illuminate\\Support\\Facades\\Hash::make('password'), 'email_verified_at' => now()]);`,
            )
            .join(' ');
        tinker(made);
    }

    mkdirSync(RUNS, { recursive: true });
    const record = join(RUNS, `${id}.json`);
    const run = {
        id,
        from,
        began: Date.now(),
        ended: null,
        lanes: used,
        watched: shown,
        stopped: false,
        suites: queue.map((name) => ({
            name,
            state: 'waiting',
            lane: null,
            took: null,
            expected: timings[name] ?? null,
            problems: null,
            lines: [],
        })),
    };

    // Written at most a few times a second, and once more at the end
    let writing = null;
    const write = (now = false) => {
        if (now) {
            clearTimeout(writing);
            writing = null;

            return writeJson(record, run);
        }

        writing ??= setTimeout(() => {
            writing = null;
            writeJson(record, run);
        }, 300);
    };
    write(true);
    prune();

    const pad = Math.max(...queue.map((name) => name.length));
    const finished = [];
    const going = new Set();

    const runOne = (name, lane) => {
        const { owner, member } = accounts(lane);
        const started = Date.now();
        const suite = run.suites.find((one) => one.name === name);
        Object.assign(suite, { state: 'running', lane, began: started });
        write();

        return new Promise((resolve) => {
            const child = spawn('node', [join(HERE, `${name}.mjs`)], {
                env: {
                    ...process.env,
                    HEADED: shown ? '1' : '0',
                    SLOWMO:
                        process.env.SLOWMO ??
                        String(
                            watch ? 600 : (STEADY[name] ?? (headed ? 40 : 0)),
                        ),
                    ...(watch ? { FULL: '1' } : {}),
                    E2E_EMAIL: owner,
                    E2E_MEMBER: member,
                    E2E_LIVE: '1',
                    // A run the app asked for keeps its pictures, for the app to show
                    ...(from === 'app'
                        ? { E2E_SHOTS: join(RUNS, id, name) }
                        : {}),
                },
            });
            going.add(child);
            let output = '';
            const take = (chunk) => {
                output += chunk;

                for (const line of String(chunk).match(/^LIVE .*$/gm) ?? []) {
                    suite.lines.push(line.slice(5));
                }

                write();
            };
            child.stdout.on('data', take);
            child.stderr.on('data', take);
            child.on('close', (code) => {
                going.delete(child);
                const took = Date.now() - started;
                // A suite of its own making says only by how it exits
                const passed =
                    code === 0 && !/^problems: (?!none$)/m.test(output);
                const problems =
                    output.match(/^problems: (.*)$/m)?.[1] ??
                    (run.stopped ? 'stopped' : `exited ${code}`);
                finished.push({ name, passed, took, output });
                Object.assign(suite, {
                    state: passed ? 'passed' : 'failed',
                    took,
                    problems: passed ? null : problems,
                    // What it said in the end: every check, and anything else it printed
                    lines: output
                        .split('\n')
                        .filter((line) => line && !/^(LIVE|TIME) /.test(line))
                        .slice(-400),
                });
                write();
                console.log(
                    `${passed ? '✓' : '✗'} ${name.padEnd(pad)}  ${seconds(took).padStart(6)}  lane ${lane}${
                        passed ? '' : `  ${problems.slice(0, 140)}`
                    }`,
                );

                // E2E_TIMES=1: each suite says where its time went
                for (const line of output.match(/^TIME .*$/gm) ?? []) {
                    console.log(`    ${line.slice(6)}`);
                }

                resolve();
            });
        });
    };

    const lane = async (number) => {
        while (queue.length) {
            if (stopped()) {
                run.stopped = true;
                break;
            }

            await runOne(queue.shift(), number);
        }
    };

    // Asked between suites, and of the ones going now
    const watching = setInterval(() => {
        if (stopped() && !run.stopped) {
            run.stopped = true;

            for (const child of going) {
                child.kill('SIGTERM');
            }
        }
    }, 500);

    console.log(
        `${queue.length} suites, ${used} ${used === 1 ? 'lane' : 'lanes'}${shown ? ', headed' : ''}\n`,
    );
    await Promise.all(Array.from({ length: used }, (_, at) => lane(at + 1)));
    clearInterval(watching);

    // Only a whole run says how long a suite takes; one that fell over early doesn't
    const timed = readTimings();

    for (const { name, took } of finished.filter((suite) => suite.passed)) {
        timed[name] = Math.round(took / 100) / 10;
    }

    mkdirSync(KEPT, { recursive: true });
    writeFileSync(TIMINGS, JSON.stringify(timed, null, 2));

    for (const suite of run.suites.filter((one) => one.state === 'waiting')) {
        suite.state = 'stopped';
    }

    run.ended = Date.now();
    write(true);

    const failed = finished.filter((suite) => !suite.passed);

    for (const { name, output } of failed) {
        console.log(`\n── ${name} ──`);
        console.log(
            output
                .split('\n')
                .filter((line) => !/^(PASS|LIVE)/.test(line))
                .join('\n')
                .trim(),
        );
    }

    const total = finished.reduce((sum, suite) => sum + suite.took, 0);
    console.log(
        `\n${finished.length - failed.length} passed, ${failed.length} failed in ${seconds(Date.now() - run.began)} (${seconds(total)} of suites)`,
    );

    return failed.length;
}

// A run's name: when it began, so the names sort the way the runs went
const newId = () =>
    new Date().toISOString().replace(/[-:]/g, '').replace(/\..*/, '') +
    Math.random().toString(36).slice(2, 6);

if (!args.includes('--listen')) {
    const failed = await runSuites(
        named.length ? named : all.filter((n) => !ALONE.includes(n)),
        {
            id: newId(),
            from: 'cli',
            lanes: Math.max(1, Number(process.env.E2E_LANES ?? 3)),
            headed: args.includes('--headed'),
        },
    );
    process.exitCode = failed ? 1 : 0;
} else {
    mkdirSync(REQUESTS, { recursive: true });
    const since = Date.now();
    let busy = null;

    // Said every two seconds, so the app can tell a runner that has gone away
    const beat = () =>
        writeJson(LISTENER, {
            pid: process.pid,
            since,
            beat: Date.now(),
            busy,
            suites: all,
            alone: ALONE,
            timings: readTimings(),
        });
    beat();
    const beating = setInterval(beat, 2000);

    for (const signal of ['SIGINT', 'SIGTERM']) {
        process.on(signal, () => {
            clearInterval(beating);
            rmSync(LISTENER, { force: true });
            process.exit(0);
        });
    }

    console.log('Waiting for runs asked for in the app…');

    for (;;) {
        const next = readdirSync(REQUESTS)
            .filter((file) => file.endsWith('.json'))
            .sort()[0];

        if (!next) {
            await new Promise((resolve) => setTimeout(resolve, 1000));
            continue;
        }

        const path = join(REQUESTS, next);
        let asked;

        try {
            asked = JSON.parse(readFileSync(path, 'utf8'));
        } catch {
            asked = null;
        }

        unlinkSync(path);
        const id = basename(next, '.json');
        const names = (asked?.suites ?? []).filter((name) =>
            all.includes(name),
        );
        const stop = join(REQUESTS, `${id}.stop`);

        busy = id;
        beat();
        await runSuites(
            names.length ? names : all.filter((n) => !ALONE.includes(n)),
            {
                id,
                from: 'app',
                // One window at a time for whoever watches
                lanes: asked?.watch
                    ? 1
                    : Math.min(3, Math.max(1, Number(asked?.lanes ?? 3))),
                headed: false,
                watch: Boolean(asked?.watch),
                stopped: () => existsSync(stop),
            },
        ).catch((error) => console.error(error));
        rmSync(stop, { force: true });
        busy = null;
        beat();
    }
}
