/**
 * One run's own process (server.mjs starts it, with Node's permission model
 * letting it read but not write or start anything). It runs the code in a
 * thread (run.mjs) so that it can stop it: Python is asked to stop -- a
 * KeyboardInterrupt at its next check -- when its time is up, and the thread
 * goes two seconds later for code that catches that.
 */
import { Worker } from 'node:worker_threads';

process.once('message', (job) => {
    const interrupt = new Int32Array(new SharedArrayBuffer(4));
    const worker = new Worker(new URL('./run.mjs', import.meta.url), {
        workerData: { ...job, interrupt },
        resourceLimits: { maxOldGenerationSizeMb: 384, stackSizeMb: 8 },
    });
    let timers = [];
    let said = false;
    let stopping = false;

    const say = (answer) => {
        if (said) {
            return;
        }

        said = true;
        timers.forEach(clearTimeout);
        process.send(answer, () => process.exit(0));
    };

    // Loading has a time of its own; the run's starts when it says so
    timers.push(
        setTimeout(
            () =>
                say({
                    ok: false,
                    error: 'Python and its libraries took too long to load.',
                    line: null,
                    logs: [],
                }),
            job.loading * 1000,
        ),
    );

    worker.on('message', (message) => {
        if (message?.started) {
            timers.forEach(clearTimeout);
            timers = [
                setTimeout(() => {
                    stopping = true;
                    Atomics.store(interrupt, 0, 2);
                }, job.seconds * 1000),
                setTimeout(
                    () =>
                        say({
                            ok: false,
                            error: `It ran for more than ${job.seconds} seconds and was stopped.`,
                            line: null,
                            logs: [],
                        }),
                    job.seconds * 1000 + 2000,
                ),
            ];

            return;
        }

        say(message);
    });
    // What escapes Python altogether: stopped for its time, out of memory,
    // or reaching for what the sandbox does not give it
    worker.once('error', (error) => {
        const text = String(error?.message ?? error);

        say({
            ok: false,
            error:
                stopping || /KeyboardInterrupt/.test(text)
                    ? `It ran for more than ${job.seconds} seconds and was stopped.`
                    : /memory|allocation/i.test(text)
                      ? 'It ran out of memory.'
                      : /restricted|cannot be loaded here/i.test(text)
                        ? 'That is not allowed here: code cannot start programs, change files or reach the network.'
                        : `It could not be run: ${text.slice(0, 300)}`,
            line: null,
            logs: [],
        });
    });
    worker.once('exit', () =>
        say({
            ok: false,
            error: 'It stopped before it said how it went.',
            line: null,
            logs: [],
        }),
    );
});
