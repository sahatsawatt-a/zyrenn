import { computed, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import type { Run, RunSummary } from '@/features/workflow/types';
import { show as showRun } from '@/routes/workflow-runs';

// A run's data, for setting a step up from what really came: what its
// trigger gave, what came into the step, what each step before it gave out.
// The run on show when there is one, else the latest; any of the recent ones
// may be chosen instead. Runs are matched to the steps by id, so a key
// renamed since still finds its data.

export type Sample = {
    // What a formula calls it: trigger, input, node("key")
    name: string;
    value: unknown;
    // Whether that run got as far
    reached: boolean;
    // The step whose output it is, when it is one's
    id?: string;
};

const FINAL = ['succeeded', 'failed', 'cancelled'];

export function useTestData(
    onShow: Ref<Run | null>,
    history: Ref<RunSummary[]>,
) {
    const chosen = ref<string | null>(null);
    const fetched = shallowRef<Run | null>(null);
    const loading = ref(false);
    // Runs that have ended don't change, so each is asked for once
    const ended = new Map<string, Run>();

    const which = computed(
        () =>
            chosen.value ??
            onShow.value?.ref_id ??
            history.value[0]?.ref_id ??
            null,
    );

    // The run on show is followed already; any other is asked for
    const run = computed(() =>
        onShow.value && onShow.value.ref_id === which.value
            ? onShow.value
            : fetched.value?.ref_id === which.value
              ? fetched.value
              : null,
    );

    watch(
        which,
        async (ref) => {
            if (!ref || ref === onShow.value?.ref_id) {
                return;
            }

            if (ended.has(ref)) {
                fetched.value = ended.get(ref)!;

                return;
            }

            loading.value = true;

            try {
                const response = await fetch(showRun(ref).url, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.ok && which.value === ref) {
                    const body: Run = (await response.json()).run;
                    fetched.value = body;

                    if (FINAL.includes(body.status)) {
                        ended.set(ref, body);
                    }
                }
            } finally {
                loading.value = false;
            }
        },
        { immediate: true },
    );

    /**
     * What a step can read, from that run: the trigger, what came in (not
     * for a trigger), and each step before it by its key.
     */
    function samples(
        stepId: string,
        trigger: boolean,
        before: { id: string; key: string }[],
        parents: string[],
    ): Sample[] {
        const steps = new Map(
            (run.value?.steps ?? []).map((step) => [step.node_id, step]),
        );
        const gave = (id: string) => {
            const step = steps.get(id);

            return step?.status === 'succeeded'
                ? { value: step.output, reached: true }
                : { value: undefined, reached: false };
        };
        const started = run.value?.steps.find((step) =>
            step.node_type.startsWith('trigger.'),
        );

        const found: Sample[] = [
            {
                name: 'trigger',
                id: started?.node_id,
                ...(started ? gave(started.node_id) : gave('')),
            },
        ];

        if (!trigger) {
            const own = steps.get(stepId);
            const from = parents.filter((id) => gave(id).reached);
            found.push({
                name: 'input',
                ...(own?.input
                    ? { value: own.input.input, reached: true }
                    : // Not reached itself: what its one way in gave
                      from.length === 1
                      ? gave(from[0])
                      : { value: undefined, reached: false }),
            });
        }

        return [
            ...found,
            ...before.map((step) => ({
                name: `node("${step.key}")`,
                id: step.id,
                ...gave(step.id),
            })),
        ];
    }

    return { chosen, which, run, loading, samples };
}
