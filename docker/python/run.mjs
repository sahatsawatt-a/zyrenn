/**
 * One run of someone's Python (server.mjs), in a thread of its own: a fresh
 * Pyodide, made for this run and gone with the thread.
 *
 * The code reads `trigger`, `input`, `steps` and `node("key")` as a
 * workflow's formulas do, and may `await`. What it gives out is `result`
 * when it sets one, else what its last line works out to. `print()` goes to
 * the run's log. The libraries asked for are loaded first, from what the
 * app put in the cache (PythonRunner::install): Pyodide's own packages by
 * name, any other as its wheel.
 */
import {
    constants as fsConstants,
    existsSync,
    readdirSync,
    readFileSync,
} from 'node:fs';
import { constants as osConstants } from 'node:os';
import { registerHooks } from 'node:module';
import { join } from 'node:path';
import { parentPort, workerData } from 'node:worker_threads';
import { loadPyodide } from '/opt/runner/node_modules/pyodide/pyodide.mjs';

const PACKAGES = '/cache/packages';
const MAX_OUT = 1024 * 1024; // what it may give back, as JSON
const MAX_LOGS = 100;
const MAX_LOG = 2000;

// What the thread loses before the code runs: every way out of it that the
// code could reach through Pyodide's `js` module or run_js
const OUTSIDE = [
    'fetch',
    'XMLHttpRequest',
    'WebSocket',
    'EventSource',
    'Request',
    'Response',
    'Headers',
    'process',
    'require',
    'importScripts',
    'Worker',
    'SharedWorker',
    'BroadcastChannel',
    'MessageChannel',
    'navigator',
    'Deno',
    'Bun',
];

const { code, data, seconds, libraries, interrupt } = workerData;

const logs = [];
const say = (line) => {
    if (logs.length < MAX_LOGS) {
        logs.push(String(line).slice(0, MAX_LOG));
    }
};

/** A name as PyPI compares names: "Typing_Extensions" is "typing-extensions". */
const normal = (name) => name.toLowerCase().replace(/[-_.]+/g, '-');

/** The cached wheel of a library not Pyodide's own: its newest, or the version asked for. */
function wheelOf(name, version) {
    const wanted = normal(name);
    const wheels = readdirSync(PACKAGES)
        .filter((file) => file.endsWith('.whl'))
        .map((file) => ({
            file,
            name: normal(file.split('-')[0]),
            version: file.split('-')[1],
        }))
        .filter(
            (one) =>
                one.name === wanted && (!version || one.version === version),
        )
        .sort((a, b) =>
            b.version.localeCompare(a.version, undefined, { numeric: true }),
        );

    return wheels[0]?.file ?? null;
}

/** What of a package Pyodide ships, or of what it depends on, the app has not put in the cache. */
function notKept(lock, name, seen = new Set()) {
    const one = lock[normal(name)] ?? lock[name.toLowerCase()];

    if (!one || seen.has(one.name)) {
        return [];
    }

    seen.add(one.name);

    return [
        ...(existsSync(join(PACKAGES, one.file_name)) ? [] : [one.name]),
        ...(one.depends ?? []).flatMap((depends) =>
            notKept(lock, depends, seen),
        ),
    ];
}

// Before the code: Python made available, with its libraries
const SETUP = `
import json as __json

def __plain(value):
    # numpy's and pandas' own kinds, as plain lists and records
    if hasattr(value, 'to_dict'):
        try:
            return value.to_dict(orient='records')
        except TypeError:
            return value.to_dict()
    if hasattr(value, 'tolist'):
        return value.tolist()
    if hasattr(value, 'item'):
        return value.item()
    if isinstance(value, (set, frozenset, tuple)):
        return list(value)
    return str(value)

def __out(value):
    return __json.dumps(value, default=__plain, allow_nan=False)

__d = __json.loads(__data)
trigger = __d.get('trigger')
input = __d.get('input')
steps = __d.get('steps') or {}

def node(key):
    return steps.get(key)

del __d, __data
`;

function failed(error) {
    const text = String(error?.message ?? error);
    // The last frame in the code itself, by the name it was run under
    const lines = [...text.matchAll(/File "<code>", line (\d+)/g)];
    const last = text.trim().split('\n').filter(Boolean).pop() ?? text;

    return {
        ok: false,
        error: /KeyboardInterrupt/.test(text)
            ? `It ran for more than ${seconds} seconds and was stopped.`
            : /MemoryError|out of memory|Cannot enlarge memory/i.test(text)
              ? 'It ran out of memory.'
              : /RecursionError|Maximum call stack/i.test(text)
                ? 'It called itself too deeply.'
                : /has been restricted|cannot be loaded here|cannot be used here/i.test(
                        text,
                    )
                  ? 'That is not allowed here: code cannot start programs, change files or reach the network.'
                  : last.slice(0, 1000),
        line: lines.length ? Number(lines[lines.length - 1][1]) : null,
        logs,
    };
}

async function run() {
    // Emscripten asks process.binding() for the file flags as it starts, which
    // Node's permission model refuses: it is given those, and nothing else
    const refused = process.binding.bind(process);
    process.binding = (name) =>
        name === 'constants'
            ? { fs: fsConstants, os: osConstants }
            : refused(name);

    const py = await loadPyodide({
        indexURL: '/opt/runner/node_modules/pyodide/',
        packageCacheDir: PACKAGES,
        stdout: say,
        stderr: say,
    });
    const lock =
        JSON.parse(
            readFileSync(
                '/opt/runner/node_modules/pyodide/pyodide-lock.json',
                'utf8',
            ),
        ).packages ?? {};

    for (const asked of libraries ?? []) {
        const [name, version] = asked.split(/[=@]=?/);

        if (lock[normal(name)] || lock[name.toLowerCase()]) {
            // Pyodide would go looking for it on the web, which is not there
            const missing = notKept(lock, name);

            if (missing.length) {
                return {
                    ok: false,
                    error: `The library "${asked}" is not added${missing.includes(lock[normal(name)]?.name) ? '' : ` (it needs ${missing.join(', ')})`}: an admin adds it on the Code page.`,
                    line: null,
                    logs,
                };
            }

            await py.loadPackage(name, {
                messageCallback: () => {},
                errorCallback: say,
            });
            continue;
        }

        const wheel = wheelOf(name, version);

        if (!wheel) {
            return {
                ok: false,
                error: `The library "${asked}" is not added: an admin adds it on the Code page.`,
                line: null,
                logs,
            };
        }

        // A Uint8Array of its own: Pyodide does not take Node's Buffer
        py.unpackArchive(
            new Uint8Array(readFileSync(join(PACKAGES, wheel))),
            'wheel',
        );
    }

    // Nothing outside it from here on. No module loads any more -- run_js
    // could import node:net, and the app is on this network -- and the ways
    // to Node's own parts round that, or to other processes, are taken from
    // process itself, for whatever still holds it
    registerHooks({
        resolve(specifier) {
            throw new Error(`"${specifier}" cannot be loaded here.`);
        },
    });

    for (const name of [
        'getBuiltinModule',
        'binding',
        '_linkedBinding',
        'dlopen',
        'kill',
        '_kill',
        'send',
        'chdir',
        'setuid',
        'setgid',
    ]) {
        try {
            process[name] = () => {
                throw new Error(`process.${name} cannot be used here.`);
            };
        } catch {
            // not there to take
        }
    }

    for (const name of OUTSIDE) {
        try {
            delete globalThis[name];
        } catch {
            // not deletable: set to nothing instead
        }

        try {
            globalThis[name] = undefined;
        } catch {
            // read-only, and so of no use to anyone
        }
    }

    const scope = py.globals.get('dict')();
    scope.set('__data', JSON.stringify(data ?? {}));
    await py.runPythonAsync(SETUP, { globals: scope });
    py.setInterruptBuffer(interrupt);

    parentPort.postMessage({ started: true });

    try {
        const last = await py.runPythonAsync(code, {
            globals: scope,
            filename: '<code>',
        });
        const value = scope.has('result') ? scope.get('result') : last;
        const out = scope.get('__out')(value);

        if (out.length > MAX_OUT) {
            return {
                ok: false,
                error: 'What it gives back is more than 1 MB.',
                line: null,
                logs,
            };
        }

        return { ok: true, value: JSON.parse(out), logs };
    } catch (error) {
        return failed(error);
    }
}

try {
    parentPort.postMessage(await run());
} catch (error) {
    parentPort.postMessage(failed(error));
}
