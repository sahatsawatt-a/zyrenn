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
 * Libraries an admin added (App\Support\Workflow\Code\CodeLibraries) are
 * fetched from npm here without their install scripts, bundled into one file
 * by esbuild -- which only reads them -- and tried in the sandbox before they
 * are kept. Their code, too, only ever runs inside QuickJS.
 *
 *   GET  /healthz -> 200 "ok"
 *   POST /run     -> {code, data: {trigger, input, steps}, seconds, libraries: [{name, source}]}
 *                 -> 200 {ok: true, value, logs}
 *                    or 200 {ok: false, error, line, logs} when the code threw,
 *                    ran out of time or memory, or gave back what can't be kept
 *   POST /install -> {name, version}
 *                 -> 200 {ok: true, version, size, source} or {ok: false, error}
 *   Anything else wrong with the request is a JSON {"error"} with a 4xx/5xx.
 */
import { execFile } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import http from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Worker } from 'node:worker_threads';

const PORT = Number(process.env.CODE_PORT || 3000);
const MAX_BODY = 16 * 1024 * 1024; // the code, the data it reads and its libraries
const MAX_SECONDS = 120; // a workflow's step is stopped at 150
const MAX_BUNDLE = 3 * 1024 * 1024; // one library, bundled
const NPM_NAME = /^(@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/;
const VERSION = /^[A-Za-z0-9.^~<>=*!+,| -]{1,64}$/;
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
function run(code, data, seconds, libraries = []) {
    return new Promise((resolve) => {
        const worker = new Worker(new URL('./run.mjs', import.meta.url), {
            workerData: { code, data, seconds, libraries },
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

const npm = (args, cwd) =>
    new Promise((resolve, reject) =>
        execFile(
            'npm',
            args,
            { cwd, timeout: 150_000, maxBuffer: 4 * 1024 * 1024 },
            (error, stdout, stderr) =>
                error
                    ? reject(new Error(String(stderr || error.message)))
                    : resolve(stdout),
        ),
    );

/**
 * A package from npm made into one file the sandbox can load: fetched with
 * what it needs and none of its install scripts, bundled for a browser (so
 * one that needs Node's own modules is turned down, saying which), and tried.
 */
async function install(name, version) {
    if (!NPM_NAME.test(name) || name.length > 214) {
        return { ok: false, error: 'That is not an npm package’s name.' };
    }

    if (version && !VERSION.test(version)) {
        return { ok: false, error: 'That is not a version.' };
    }

    const dir = await mkdtemp(join(tmpdir(), 'lib-'));

    try {
        try {
            await npm(
                [
                    'install',
                    '--ignore-scripts',
                    '--no-audit',
                    '--no-fund',
                    '--omit=dev',
                    '--no-package-lock',
                    '--prefix',
                    dir,
                    version ? `${name}@${version}` : name,
                ],
                dir,
            );
        } catch (error) {
            const said = String(error.message);

            return {
                ok: false,
                error: /E404|404 Not Found/.test(said)
                    ? `npm has no package “${name}”${version ? ` at ${version}` : ''}.`
                    : /ETARGET|No matching version/.test(said)
                      ? `npm has no version of “${name}” that is ${version}.`
                      : `npm could not install it: ${said.split('\n').find((line) => /ERR!/.test(line)) ?? said.slice(0, 300)}`,
            };
        }

        const installed = JSON.parse(
            await readFile(
                join(dir, 'node_modules', name, 'package.json'),
                'utf8',
            ),
        ).version;
        const { build } = await import('esbuild');
        let source;

        try {
            const built = await build({
                stdin: {
                    contents: `module.exports = require(${JSON.stringify(name)});`,
                    resolveDir: dir,
                    loader: 'js',
                },
                bundle: true,
                format: 'cjs',
                platform: 'browser',
                target: 'es2020',
                minify: true,
                legalComments: 'none',
                write: false,
                logLevel: 'silent',
                define: { 'process.env.NODE_ENV': '"production"' },
            });

            source = built.outputFiles[0].text;
        } catch (error) {
            const needs = (error.errors ?? [])
                .map((one) => /Could not resolve "([^"]+)"/.exec(one.text)?.[1])
                .filter(Boolean);

            return {
                ok: false,
                error: needs.length
                    ? `It needs Node’s own ${[...new Set(needs)].slice(0, 4).join(', ')}, which code here can’t have: only libraries that just compute can be used.`
                    : `It could not be bundled: ${String(error.errors?.[0]?.text ?? error.message).slice(0, 300)}`,
            };
        }

        if (Buffer.byteLength(source) > MAX_BUNDLE) {
            return {
                ok: false,
                error: `Bundled, it is ${Math.round(Buffer.byteLength(source) / 1024 / 1024)} MB: more than the 3 MB a library may be.`,
            };
        }

        // Tried where it will run: loaded, and reached by require()
        const tried = await run(
            `const lib = require(${JSON.stringify(name)}); return { kind: typeof lib };`,
            {},
            15,
            [{ name, source }],
        );

        if (!tried.ok) {
            return {
                ok: false,
                error: `It does not load where code runs: ${tried.error}`,
            };
        }

        return {
            ok: true,
            version: installed,
            size: Buffer.byteLength(source),
            source,
        };
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
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

    if (
        request.method !== 'POST' ||
        !['/run', '/install'].includes(request.url)
    ) {
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

        if (request.url === '/install') {
            if (typeof asked?.name !== 'string') {
                return answer(response, 422, { error: 'There is no name.' });
            }

            try {
                return answer(
                    response,
                    200,
                    await install(
                        asked.name.trim(),
                        typeof asked.version === 'string'
                            ? asked.version.trim()
                            : '',
                    ),
                );
            } catch (error) {
                return answer(response, 200, {
                    ok: false,
                    error: `It could not be installed: ${String(error?.message ?? error).slice(0, 300)}`,
                });
            }
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
                await run(
                    asked.code,
                    asked.data ?? {},
                    seconds,
                    Array.isArray(asked.libraries) ? asked.libraries : [],
                ),
            );
        } finally {
            done();
        }
    });
}).listen(PORT, () => console.log(`code runner on :${PORT}`));
