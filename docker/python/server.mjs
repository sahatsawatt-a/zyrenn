#!/usr/bin/env node
/**
 * The Python runner: runs Python someone wrote in a workflow's "Run code"
 * step (App\Support\Workflow\Code\PythonRunner), somewhere it can do nothing
 * but compute.
 *
 * Each run gets a process of its own (child.mjs) and, in a thread there
 * (run.mjs), a fresh Pyodide -- CPython in WebAssembly -- with nothing
 * outside it to reach: this container is on a network with no way out, the
 * process may read its own files but write and start nothing, and the
 * thread loses fetch, process and the rest before the code starts. The code
 * is stopped when its time is up, the process killed when its memory passes
 * what it was given. One run at a time.
 *
 *   GET  /healthz        -> 200 "ok"
 *   POST /run            -> {code, data: {trigger, input, steps}, seconds, libraries, memory}
 *                        -> 200 {ok: true, value, logs}
 *                           or 200 {ok: false, error, line, logs}
 *   POST /check          -> {libraries} -> 200 {ok, error}: they load, in a fresh interpreter
 *   GET  /files          -> 200 [{name, size}]: what is kept to import
 *   PUT  /files/:name    -> the file's bytes, kept (a Pyodide package or a wheel)
 *   DELETE /files/:name  -> taken away
 */
import { fork } from 'node:child_process';
import { createWriteStream } from 'node:fs';
import { readdir, readFile, rename, stat, unlink } from 'node:fs/promises';
import http from 'node:http';
import { join } from 'node:path';

const PORT = Number(process.env.PYTHON_PORT || 3000);
const PACKAGES = '/cache/packages';
const MAX_BODY = 4 * 1024 * 1024;
const MAX_FILE = 200 * 1024 * 1024;
const MAX_SECONDS = 60;
// How long the interpreter and its libraries may take to load, apart from the run itself
const LOADING_SECONDS = 60;
const MEMORY = { least: 128, most: 900, usual: 512 };
const FILE_NAME =
    /^[A-Za-z0-9][A-Za-z0-9._+-]{0,200}\.(whl|zip|tar|tar\.gz|tgz|so)$/;

// One run at a time: each holds an interpreter of its own
let queue = Promise.resolve();
const oneAtATime = (work) => {
    const turn = queue.then(work, work);
    queue = turn.catch(() => {});

    return turn;
};

/** The memory a process holds now, in bytes, as the kernel counts it. */
async function rssOf(pid) {
    try {
        const status = await readFile(`/proc/${pid}/status`, 'utf8');

        return Number(/VmRSS:\s+(\d+) kB/.exec(status)?.[1] ?? 0) * 1024;
    } catch {
        return 0;
    }
}

/**
 * One run in a process of its own (child.mjs), which may read but not
 * write, start nothing, and is killed outright when its memory passes what
 * it was given or its time runs out -- so a run can neither leave anything
 * behind for the next, nor outlast what it was given.
 */
function inChild(job, seconds, memoryMb) {
    return new Promise((resolve) => {
        const child = fork(new URL('./child.mjs', import.meta.url), [], {
            execArgv: [
                '--permission',
                '--allow-fs-read=/srv',
                '--allow-fs-read=/opt/runner',
                '--allow-fs-read=/cache',
                '--allow-worker',
                '--max-old-space-size=512',
            ],
            stdio: ['ignore', 'ignore', 'ignore', 'ipc'],
            serialization: 'advanced',
        });
        let settled = false;

        const finish = (answer) => {
            if (settled) {
                return;
            }

            settled = true;
            clearInterval(watch);
            clearTimeout(overall);
            child.kill('SIGKILL');
            resolve(answer);
        };

        const watch = setInterval(async () => {
            if ((await rssOf(child.pid)) > memoryMb * 1024 * 1024) {
                finish({
                    ok: false,
                    error: `It ran out of memory: more than ${memoryMb} MB.`,
                    line: null,
                    logs: [],
                });
            }
        }, 50);
        // The child keeps its own time; this is for one that can't
        const overall = setTimeout(
            () =>
                finish({
                    ok: false,
                    error: `It ran for more than ${seconds} seconds and was stopped.`,
                    line: null,
                    logs: [],
                }),
            (LOADING_SECONDS + seconds + 5) * 1000,
        );

        child.once('message', finish);
        child.once('error', () =>
            finish({
                ok: false,
                error: 'It could not be run.',
                line: null,
                logs: [],
            }),
        );
        child.once('exit', () =>
            finish({
                ok: false,
                error: 'It stopped before it said how it went.',
                line: null,
                logs: [],
            }),
        );
        child.send({ ...job, seconds, loading: LOADING_SECONDS });
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

const json = (request) =>
    new Promise((resolve, reject) => {
        const parts = [];
        let size = 0;

        request.on('data', (part) => {
            size += part.length;

            if (size > MAX_BODY) {
                reject(
                    Object.assign(new Error('Too much to run.'), {
                        status: 413,
                    }),
                );
                request.destroy();
            } else {
                parts.push(part);
            }
        });
        request.on('end', () => {
            try {
                resolve(JSON.parse(Buffer.concat(parts).toString('utf8')));
            } catch {
                reject(Object.assign(new Error('Not JSON.'), { status: 400 }));
            }
        });
    });

const librariesOf = (asked) =>
    (Array.isArray(asked?.libraries) ? asked.libraries : [])
        .filter(
            (one) =>
                typeof one === 'string' &&
                /^[A-Za-z0-9._-]+([=@]=?[A-Za-z0-9._+!-]+)?$/.test(one),
        )
        .slice(0, 30);

/** A file sent to be kept: written aside first, so a broken upload leaves nothing half there. */
function keep(request, name) {
    return new Promise((resolve, reject) => {
        const part = join(PACKAGES, `.${name}.part`);
        const out = createWriteStream(part);
        let size = 0;

        request.on('data', (chunk) => {
            size += chunk.length;

            if (size > MAX_FILE) {
                request.destroy();
                out.destroy();
                void unlink(part).catch(() => {});
                reject(
                    Object.assign(new Error('The file is too large.'), {
                        status: 413,
                    }),
                );
            }
        });
        request.pipe(out);
        out.on('finish', async () => {
            await rename(part, join(PACKAGES, name));
            resolve(size);
        });
        out.on('error', reject);
    });
}

http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://runner');

    try {
        if (request.method === 'GET' && url.pathname === '/healthz') {
            return answer(response, 200, 'ok');
        }

        if (request.method === 'POST' && url.pathname === '/run') {
            const asked = await json(request);

            if (typeof asked?.code !== 'string') {
                return answer(response, 422, { error: 'There is no code.' });
            }

            const seconds = Math.max(
                1,
                Math.min(MAX_SECONDS, Math.round(Number(asked.seconds) || 5)),
            );
            const memory = Math.max(
                MEMORY.least,
                Math.min(
                    MEMORY.most,
                    Math.round(Number(asked.memory) || MEMORY.usual),
                ),
            );

            return answer(
                response,
                200,
                await oneAtATime(() =>
                    inChild(
                        {
                            code: asked.code,
                            data: asked.data ?? {},
                            libraries: librariesOf(asked),
                        },
                        seconds,
                        memory,
                    ),
                ),
            );
        }

        if (request.method === 'POST' && url.pathname === '/check') {
            const asked = await json(request);
            const ran = await oneAtATime(() =>
                inChild(
                    { code: 'None', data: {}, libraries: librariesOf(asked) },
                    10,
                    MEMORY.most,
                ),
            );

            return answer(response, 200, {
                ok: ran.ok,
                error: ran.ok ? null : ran.error,
            });
        }

        if (request.method === 'GET' && url.pathname === '/files') {
            const names = (await readdir(PACKAGES)).filter(
                (name) => !name.startsWith('.'),
            );
            const files = await Promise.all(
                names.map(async (name) => ({
                    name,
                    size: (await stat(join(PACKAGES, name))).size,
                })),
            );

            return answer(response, 200, files);
        }

        const file = /^\/files\/([^/]+)$/.exec(url.pathname);

        if (file && ['PUT', 'DELETE'].includes(request.method)) {
            const name = decodeURIComponent(file[1]);

            if (!FILE_NAME.test(name)) {
                return answer(response, 422, {
                    error: "That is not a package's file name.",
                });
            }

            if (request.method === 'DELETE') {
                await unlink(join(PACKAGES, name)).catch(() => {});

                return answer(response, 200, { deleted: name });
            }

            return answer(response, 200, {
                name,
                size: await keep(request, name),
            });
        }

        return answer(response, 404, { error: 'Not found.' });
    } catch (problem) {
        return answer(response, problem?.status ?? 500, {
            error: problem?.message ?? 'It went wrong.',
        });
    }
}).listen(PORT, () => console.log(`python runner on :${PORT}`));
