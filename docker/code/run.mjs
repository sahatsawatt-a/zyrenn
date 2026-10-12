/**
 * One run of someone's code (server.mjs), in a thread of its own: a QuickJS
 * interpreter in WebAssembly, made for this run and gone with the thread.
 *
 * The code is the body of a function. It reads `trigger`, `input`,
 * `node("key")` and `steps` as a workflow's formulas do, may `await`, and
 * what it returns is what the step gives out. `console.log` goes to the run's
 * log. There is nothing else to reach: no require, no fetch, no timers.
 */
import { parentPort, workerData } from 'node:worker_threads';
import {
    newQuickJSWASMModuleFromVariant,
    newVariant,
    RELEASE_SYNC,
    shouldInterruptAfterDeadline,
} from 'quickjs-emscripten';

// All the memory the interpreter can ever have, in 64 KB pages: 128 MB. Its
// own limit (setMemoryLimit) is not kept by this build, so the ceiling is on
// the WebAssembly memory itself: past it, the code gets "out of memory".
const MEMORY_PAGES = 2048;
const STACK = 512 * 1024;
const MAX_OUT = 1024 * 1024; // what it may give back, as JSON
const MAX_LOGS = 100;
const MAX_LOG = 2000;

const { code, data, seconds } = workerData;
const logs = [];

// The lines before the code itself, taken off the line an error names
const BEFORE = 1;
const source = `(async () => { const __d = JSON.parse(__data); const trigger = __d.trigger, input = __d.input, steps = __d.steps ?? {}; const node = (key) => steps[key]; const __say = (...parts) => __log(parts.map((part) => { if (typeof part === 'string') return part; try { return JSON.stringify(part) ?? String(part); } catch { return String(part); } }).join(' ')); const console = { log: __say, info: __say, warn: __say, error: __say, debug: __say }; const __out = await (async function () { "use strict";
${code}
}).call(undefined); return __out === undefined ? 'null' : (JSON.stringify(__out) ?? 'null'); })()`;

function lineOf(error) {
    const found = /code\.js:(\d+)/.exec(String(error?.stack ?? ''));
    const line = found ? Number(found[1]) - BEFORE : null;

    return line !== null && line >= 1 ? line : null;
}

function failed(error) {
    const said =
        error && typeof error === 'object' ? error : { message: String(error) };
    const message = String(said.message ?? said);

    return {
        ok: false,
        error: /interrupted/i.test(message)
            ? `It ran for more than ${seconds} seconds and was stopped.`
            : /out of memory/i.test(message)
              ? 'It ran out of memory.'
              : /stack overflow/i.test(message)
                ? 'It called itself too deeply.'
                : said.name && said.name !== 'Error'
                  ? `${said.name}: ${message}`
                  : message,
        line: lineOf(said),
        logs,
    };
}

async function run() {
    const QuickJS = await newQuickJSWASMModuleFromVariant(
        newVariant(RELEASE_SYNC, {
            wasmMemory: new WebAssembly.Memory({
                initial: 256,
                maximum: MEMORY_PAGES,
            }),
        }),
    );
    const runtime = QuickJS.newRuntime();

    runtime.setMaxStackSize(STACK);
    runtime.setInterruptHandler(
        shouldInterruptAfterDeadline(Date.now() + seconds * 1000),
    );

    const vm = runtime.newContext();

    try {
        const json = vm.newString(JSON.stringify(data ?? {}));
        vm.setProp(vm.global, '__data', json);
        json.dispose();

        const log = vm.newFunction('__log', (line) => {
            if (logs.length < MAX_LOGS) {
                logs.push(String(vm.getString(line)).slice(0, MAX_LOG));
            }
        });
        vm.setProp(vm.global, '__log', log);
        log.dispose();

        const started = vm.evalCode(source, 'code.js');

        if (started.error) {
            const error = vm.dump(started.error);
            started.error.dispose();

            return failed(error);
        }

        // What the code waits on is only its own promises: run them through
        const jobs = runtime.executePendingJobs();

        if (jobs.error) {
            const error = vm.dump(jobs.error);
            jobs.error.dispose();
            started.value.dispose();

            return failed(error);
        }

        const state = vm.getPromiseState(started.value);
        started.value.dispose();

        if (state.type === 'pending') {
            return {
                ok: false,
                error: 'It waits for something that never comes: there is nothing outside it to wait for.',
                line: null,
                logs,
            };
        }

        if (state.type === 'rejected') {
            const error = vm.dump(state.error);
            state.error.dispose();

            return failed(error);
        }

        const out = vm.getString(state.value);
        state.value.dispose();

        if (out.length > MAX_OUT) {
            return {
                ok: false,
                error: 'What it gives back is more than 1 MB.',
                line: null,
                logs,
            };
        }

        return { ok: true, value: JSON.parse(out), logs };
    } finally {
        try {
            vm.dispose();
            runtime.dispose();
        } catch {
            // Gone with the thread either way
        }
    }
}

void run()
    .catch((error) => failed(error))
    .then((answer) => parentPort.postMessage(answer));
