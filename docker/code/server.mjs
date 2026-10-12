#!/usr/bin/env node
/**
 * The code runner: runs a workflow's "Run code" step (App\Support\Workflow\
 * Steps\RunCode), which is JavaScript someone wrote, somewhere it can do
 * nothing but compute.
 *
 * The code never runs in Node. Each run gets a thread of its own (run.mjs)
 * holding a QuickJS interpreter compiled to WebAssembly: a JavaScript with no
 * files, no network, no process and no modules to import -- only the data it
 * is handed and what it gives back. It is stopped when its time is up, kept
 * to 128 MB of memory, and the thread is thrown away after, so one run can
 * leave nothing behind for the next.
 *
 *   GET  /healthz -> 200 "ok"
 *   POST /run     -> {code, data: {trigger, input, steps}, seconds}
 *                 -> 200 {ok: true, value, logs}
 *                    or 200 {ok: false, error, line, logs} when the code threw,
 *                    ran out of time or memory, or gave back what can't be kept
 *   Anything else wrong with the request is a JSON {"error"} with a 4xx/5xx.
 */
import http from 'node:http';
import { Worker } from 'node:worker_threads';

const PORT = Number(process.env.CODE_PORT || 3000);
const MAX_BODY = 4 * 1024 * 1024; // the code and the data it reads
const MAX_SECONDS = 30;
const MAX_AT_ONCE = 4; // threads running; more than this wait their turn

let running = 0;
const waiting = [];

const turn = () =>
    running < MAX_AT_ONCE
        ? (running++, Promise.resolve())
        : new Promise((resolve) => waiting.push(resolve));

const done = () => {
    const next = waiting.shift();

    if (next) {
        next();
    } else {
        running--;
    }
};

/** One run, in a thread that is stopped for good when its time is up. */
function run(code, data, seconds) {
    return new Promise((resolve) => {
        const worker = new Worker(new URL('./run.mjs', import.meta.url), {
            workerData: { code, data, seconds },
            // The thread itself, beside the interpreter's own limit
            resourceLimits: { maxOldGenerationSizeMb: 256, stackSizeMb: 4 },
        });
        // The interpreter stops itself at its time; this is for one that can't
        const stop = setTimeout(
            () => {
                void worker.terminate();
                resolve({
                    ok: false,
                    error: `It ran for more than ${seconds} seconds and was stopped.`,
                    logs: [],
                });
            },
            seconds * 1000 + 2000,
        );

        worker.once('message', (answer) => {
            clearTimeout(stop);
            void worker.terminate();
            resolve(answer);
        });
        worker.once('error', (error) => {
            clearTimeout(stop);
            resolve({
                ok: false,
                error: /memory/i.test(String(error?.message))
                    ? 'It ran out of memory.'
                    : 'It could not be run.',
                logs: [],
            });
        });
    });
}

const answer = (response, status, body) => {
    const text = typeof body === 'string' ? body : JSON.stringify(body);

    response.writeHead(status, {
        'Content-Type':
            typeof body === 'string' ? 'text/plain' : 'application/json',
        'Content-Length': Buffer.byteLength(text),
    });
    response.end(text);
};

http.createServer((request, response) => {
    if (request.method === 'GET' && request.url === '/healthz') {
        return answer(response, 200, 'ok');
    }

    if (request.method !== 'POST' || request.url !== '/run') {
        return answer(response, 404, { error: 'Not found.' });
    }

    const parts = [];
    let size = 0;

    request.on('data', (part) => {
        size += part.length;

        if (size > MAX_BODY) {
            answer(response, 413, { error: 'Too much to run.' });
            request.destroy();
        } else {
            parts.push(part);
        }
    });

    request.on('end', async () => {
        let asked;

        try {
            asked = JSON.parse(Buffer.concat(parts).toString('utf8'));
        } catch {
            return answer(response, 400, { error: 'Not JSON.' });
        }

        if (typeof asked?.code !== 'string') {
            return answer(response, 422, { error: 'There is no code.' });
        }

        const seconds = Math.max(
            1,
            Math.min(MAX_SECONDS, Math.round(Number(asked.seconds) || 5)),
        );

        await turn();

        try {
            answer(
                response,
                200,
                await run(asked.code, asked.data ?? {}, seconds),
            );
        } finally {
            done();
        }
    });
}).listen(PORT, () => console.log(`code runner on :${PORT}`));
