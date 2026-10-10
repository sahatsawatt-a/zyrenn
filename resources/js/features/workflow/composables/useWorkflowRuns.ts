import { onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';
import type {
    Problem,
    Run,
    RunStatus,
    RunSummary,
    StepStatus,
} from '@/features/workflow/types';
import { xsrfToken } from '@/lib/utils';
import {
    answer as answerRoute,
    cancel as cancelRoute,
    show as showRun,
} from '@/routes/workflow-runs';
import { index as runsIndex, store as startRun } from '@/routes/workflows/runs';

// A workflow's runs as the editor shows them: the one being looked at,
// followed until it is over, and the latest few before it. The server is
// what is true. Over the socket (App\Events\WorkflowRunChanged) comes where
// each step stands, at once; what a step was given and gave out is asked for
// after. Without the socket, the page asks again and again instead; with it,
// only now and then, in case a message was lost.

const FINAL = ['succeeded', 'failed', 'cancelled'];
const EVERY_MS = 600;
// While live updates arrive, asking is only a safety net
const LIVE_EVERY_MS = 10000;
// A run that only waits -- for a time, for a person -- changes seldom
const WAITING_EVERY_MS = 3000;

const json = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
};

// What the socket says of a run: where it and each of its steps stand
export type RunHeard = {
    run: string;
    status: RunStatus;
    seq: number;
    steps: { node_id: string; status: StepStatus; handle: string | null }[];
};

// Words an AI step is writing, as the socket tells them (App\Events\WorkflowRunText)
export type TextHeard = {
    run: string;
    node_id: string;
    attempt: number;
    at: number;
    text: string;
};

// What a step has written so far, by step; `gap` when some was missed --
// opened part way, or a message lost -- and the start is not shown
export type Written = { attempt: number; text: string; gap: boolean };

// Counted as the server counts, by character, an emoji as one
const length = (text: string) => Array.from(text).length;

export function useWorkflowRuns(refId: string, live: Ref<boolean>) {
    const run = ref<Run | null>(null);
    const writing = ref<Record<string, Written>>({});
    const history = ref<RunSummary[]>([]);
    const starting = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let refresh: ReturnType<typeof setTimeout> | undefined;
    // The newest picture applied, so an older message arriving late is passed over
    let seq = 0;

    const follow = () => {
        clearTimeout(timer);

        if (!run.value || FINAL.includes(run.value.status)) {
            return;
        }

        timer = setTimeout(
            async () => {
                if (run.value) {
                    await load(run.value.ref_id, false);
                }

                follow();
            },
            live.value
                ? LIVE_EVERY_MS
                : run.value.status === 'waiting'
                  ? WAITING_EVERY_MS
                  : EVERY_MS,
        );
    };

    /** Look at one run, and follow it if it is still going. */
    async function load(ref: string, andFollow = true) {
        const response = await fetch(showRun(ref).url, { headers: json });

        if (!response.ok) {
            return;
        }

        const body = await response.json();

        // Another run on show: its messages are counted afresh
        if (run.value?.ref_id !== ref) {
            seq = 0;
            writing.value = {};
        }

        const wasGoing =
            run.value?.ref_id === ref && !FINAL.includes(run.value.status);
        run.value = body.run;

        // It has just ended: the list should say so too
        if (wasGoing && FINAL.includes(body.run.status)) {
            void loadHistory();
        }

        if (andFollow) {
            follow();
        }
    }

    async function loadHistory() {
        const response = await fetch(runsIndex(refId).url, { headers: json });

        if (response.ok) {
            history.value = (await response.json()).runs;
        }
    }

    /**
     * Run the saved draft. Answers with what stands in the way, a reason it
     * could not, or nothing when it has started.
     */
    async function start(revision: number): Promise<Problem[] | string | null> {
        starting.value = true;

        try {
            const response = await fetch(startRun(refId).url, {
                method: 'POST',
                headers: {
                    ...json,
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ revision }),
            });
            const body = await response.json().catch(() => ({}));

            if (response.status === 422 && body.problems) {
                return body.problems;
            }

            if (!response.ok) {
                return body.message ?? 'It could not be run.';
            }

            run.value = body.run;
            void loadHistory();
            follow();

            return null;
        } catch {
            return 'The connection was lost.';
        } finally {
            starting.value = false;
        }
    }

    const post = async (url: string, body: object = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                ...json,
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });
        const answer = await response.json().catch(() => ({}));

        if (!response.ok) {
            return answer.message ?? 'That could not be done.';
        }

        run.value = answer.run;
        follow();

        return null;
    };

    /** Approve or reject a step that asks; answers with why not, or nothing. */
    const answer = (nodeId: string, approved: boolean, note: string) =>
        run.value
            ? post(answerRoute([run.value.ref_id, nodeId]).url, {
                  approved,
                  note: note || null,
              })
            : Promise.resolve(null);

    /** Stop the run on show. */
    const cancel = async () => {
        if (!run.value) {
            return null;
        }

        const why = await post(cancelRoute(run.value.ref_id).url);
        void loadHistory();

        return why;
    };

    /**
     * A run moved on, as the socket tells it: its steps are recoloured at
     * once, and the rest asked for a moment later -- straight away when it
     * has ended. Another run than the one on show only freshens the list.
     */
    function heard(news: RunHeard) {
        if (news.run !== run.value?.ref_id) {
            void loadHistory();

            return;
        }

        if (news.seq < seq) {
            return;
        }

        seq = news.seq;

        // It has just ended: the list should say so too
        if (FINAL.includes(news.status) && !FINAL.includes(run.value.status)) {
            void loadHistory();
        }

        const now = Object.fromEntries(
            news.steps.map((step) => [step.node_id, step]),
        );
        run.value = {
            ...run.value,
            status: news.status,
            steps: run.value.steps.map((step) => ({
                ...step,
                status: now[step.node_id]?.status ?? step.status,
                handle: now[step.node_id]?.handle ?? step.handle,
            })),
        };

        clearTimeout(refresh);
        refresh = setTimeout(
            () => {
                if (run.value) {
                    void load(run.value.ref_id);
                }
            },
            FINAL.includes(news.status) ? 0 : 250,
        );
    }

    /**
     * Words a step of the run on show is writing. Each message says where in
     * the answer its words start: what follows on is added, what was heard
     * already is passed over, and a jump leaves a gap.
     */
    function heardText(news: TextHeard) {
        if (news.run !== run.value?.ref_id) {
            return;
        }

        const had = writing.value[news.node_id];
        // A step tried again starts its answer afresh
        const so = had && had.attempt === news.attempt ? had : null;
        const known = so ? length(so.text) : 0;
        let text = so?.text ?? '';
        let gap = so?.gap ?? news.at > 0;

        if (news.at <= known) {
            text += Array.from(news.text)
                .slice(known - news.at)
                .join('');
        } else {
            text += news.text;
            gap = true;
        }

        writing.value = {
            ...writing.value,
            [news.node_id]: { attempt: news.attempt, text, gap },
        };
    }

    onBeforeUnmount(() => {
        clearTimeout(timer);
        clearTimeout(refresh);
    });

    return {
        run,
        history,
        starting,
        start,
        load,
        loadHistory,
        answer,
        cancel,
        heard,
        heardText,
        writing,
    };
}
