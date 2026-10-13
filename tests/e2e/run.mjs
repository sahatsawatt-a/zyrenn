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
//
// workflow-workers scales the queue workers up and down, so it runs only when
// named. The app serves 4 requests at a time (PHP_CLI_SERVER_WORKERS); more
// than 3 lanes mostly wait on it.
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { KEPT, tinker } from './harness.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const TIMINGS = join(KEPT, 'timings.json');
const ALONE = ['workflow-workers', 'workflow-load'];
// Suites that fail at full speed for a reason of the app's, run as slowly as
// before until it is fixed. note-links: the "Show as" menu of a pasted link
// closes at once when the paste comes quickly after the card before it.
const STEADY = { 'note-links': 40 };
// guide-shots takes the guide's pictures: it checks nothing, and is run by hand
const NOT_SUITES = ['harness', 'run', 'guide-shots'];

const args = process.argv.slice(2);
const headed = args.includes('--headed');
const named = args.filter((arg) => !arg.startsWith('--'));
const lanes = Math.max(1, Number(process.env.E2E_LANES ?? 3));

const all = readdirSync(HERE)
    .filter((file) => file.endsWith('.mjs'))
    .map((file) => basename(file, '.mjs'))
    .filter((name) => !NOT_SUITES.includes(name));

const unknown = named.filter((name) => !all.includes(name));

if (unknown.length) {
    console.error(`No such suite: ${unknown.join(', ')}`);
    process.exit(2);
}

const timings = (() => {
    try {
        return JSON.parse(readFileSync(TIMINGS, 'utf8'));
    } catch {
        return {};
    }
})();

// Longest first; never timed, first of all
const queue = (named.length ? named : all.filter((n) => !ALONE.includes(n)))
    .map((name) => ({ name, took: timings[name] ?? Infinity }))
    .sort((a, b) => b.took - a.took)
    .map(({ name }) => name);

const used = Math.min(lanes, queue.length);

// Each lane's accounts: lane 1 the seeded pair, the others alike in name
const accounts = (lane) =>
    lane === 1
        ? { owner: 'test@example.com', member: 'e2e-member@example.com' }
        : {
              owner: `e2e-lane${lane}@example.com`,
              member: `e2e-lane${lane}-member@example.com`,
          };

if (used > 1) {
    const made = Array.from({ length: used - 1 }, (_, at) => accounts(at + 2))
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

const seconds = (ms) => `${(ms / 1000).toFixed(1)}s`;
const pad = Math.max(...queue.map((name) => name.length));
const finished = [];
const began = Date.now();

function runOne(name, lane) {
    const { owner, member } = accounts(lane);
    const started = Date.now();

    return new Promise((resolve) => {
        const child = spawn('node', [join(HERE, `${name}.mjs`)], {
            env: {
                ...process.env,
                HEADED: headed ? '1' : '0',
                SLOWMO:
                    process.env.SLOWMO ??
                    String(STEADY[name] ?? (headed ? 40 : 0)),
                E2E_EMAIL: owner,
                E2E_MEMBER: member,
            },
        });
        let output = '';
        child.stdout.on('data', (chunk) => (output += chunk));
        child.stderr.on('data', (chunk) => (output += chunk));
        child.on('close', (code) => {
            const took = Date.now() - started;
            // A suite of its own making says only by how it exits
            const passed = code === 0 && !/^problems: (?!none$)/m.test(output);
            finished.push({ name, passed, took, output });
            console.log(
                `${passed ? '✓' : '✗'} ${name.padEnd(pad)}  ${seconds(took).padStart(6)}  lane ${lane}${
                    passed
                        ? ''
                        : `  ${(output.match(/^problems: (.*)$/m)?.[1] ?? `exited ${code}`).slice(0, 140)}`
                }`,
            );

            // E2E_TIMES=1: each suite says where its time went
            for (const line of output.match(/^TIME .*$/gm) ?? []) {
                console.log(`    ${line.slice(6)}`);
            }

            resolve();
        });
    });
}

async function lane(number) {
    while (queue.length) {
        await runOne(queue.shift(), number);
    }
}

console.log(
    `${queue.length} suites, ${used} ${used === 1 ? 'lane' : 'lanes'}${headed ? ', headed' : ''}\n`,
);
await Promise.all(Array.from({ length: used }, (_, at) => lane(at + 1)));

// Only a whole run says how long a suite takes; one that fell over early doesn't
for (const { name, took } of finished.filter((suite) => suite.passed)) {
    timings[name] = Math.round(took / 100) / 10;
}

mkdirSync(KEPT, { recursive: true });
writeFileSync(TIMINGS, JSON.stringify(timings, null, 2));

const failed = finished.filter((suite) => !suite.passed);

for (const { name, output } of failed) {
    console.log(`\n── ${name} ──`);
    console.log(
        output
            .split('\n')
            .filter((line) => !line.startsWith('PASS'))
            .join('\n')
            .trim(),
    );
}

const total = finished.reduce((sum, suite) => sum + suite.took, 0);
console.log(
    `\n${finished.length - failed.length} passed, ${failed.length} failed in ${seconds(Date.now() - began)} (${seconds(total)} of suites)`,
);
process.exitCode = failed.length ? 1 : 0;
