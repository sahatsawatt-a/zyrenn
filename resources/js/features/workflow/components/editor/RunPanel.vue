<script setup lang="ts">
import {
    Check,
    CircleAlert,
    History,
    Play,
    ScrollText,
    Square,
    Timer,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import ActivityList from '@/features/audit/ActivityList.vue';
import type { Activity } from '@/features/audit/ActivityList.vue';
import type { Written } from '@/features/workflow/composables/useWorkflowRuns';
import { duration, statusLook } from '@/features/workflow/steps';
import type {
    Run,
    RunStep,
    RunSummary,
    StepNode,
} from '@/features/workflow/types';

// Under the canvas: how a run went, step by step -- what each was given,
// what its settings came to, what it gave out -- and the runs before it.

const props = defineProps<{
    run: Run | null;
    history: RunSummary[];
    // The run's steps by id, as the canvas names them
    steps: Record<string, Pick<StepNode, 'label' | 'key'>>;
    selectedId: string | null;
    // What its AI steps are writing, as they write it
    writing: Record<string, Written>;
    // Who published it, switched it on, ran it by hand
    activity: Activity[];
}>();

const tab = defineModel<'run' | 'history' | 'activity'>('tab', {
    required: true,
});

const emit = defineEmits<{
    select: [nodeId: string | null];
    open: [ref: string];
    close: [];
    answer: [nodeId: string, approved: boolean, note: string];
    cancel: [];
}>();

const note = ref('');

// What waits for someone, with what it asks
const asking = computed(
    () =>
        props.run?.steps.filter(
            (step) =>
                step.status === 'waiting' &&
                (step.waiting_for === 'approval' ||
                    step.waiting_for === 'resend'),
        ) ?? [],
);

const going = computed(
    () =>
        !!props.run &&
        ['queued', 'running', 'waiting'].includes(props.run.status),
);

// In the order they ran; the ones that never began after
const ordered = computed(() =>
    [...(props.run?.steps ?? [])].sort((a, b) => {
        if (a.started_at && b.started_at) {
            return a.started_at.localeCompare(b.started_at);
        }

        return a.started_at ? -1 : b.started_at ? 1 : 0;
    }),
);

const chosen = computed<RunStep | undefined>(() =>
    props.run?.steps.find((step) => step.node_id === props.selectedId),
);

// Steps writing now: the chosen one, or every one when none is
const streaming = computed(() =>
    (props.run?.steps ?? []).filter(
        (step) =>
            step.status === 'running' &&
            props.writing[step.node_id] &&
            (!chosen.value || chosen.value.node_id === step.node_id),
    ),
);

const logs = computed(() =>
    (props.run?.logs ?? []).filter(
        (log) => !chosen.value || log.node_id === chosen.value.node_id,
    ),
);

const label = (step: RunStep) =>
    props.steps[step.node_id]?.label ?? step.node_key;

const pretty = (value: unknown) =>
    value === undefined ? '' : JSON.stringify(value, null, 2);

const at = (time: string | null) =>
    time ? new Date(time).toLocaleTimeString() : '';

const versionText = (summary: RunSummary) =>
    summary.version.kind === 'test'
        ? 'the draft'
        : `version ${summary.version.number}`;

const reasons: Record<string, string> = {
    node_failed: 'A step failed',
    budget_exceeded: 'It began too many steps',
};
</script>

<template>
    <section
        class="bg-background flex h-72 shrink-0 flex-col border-t"
        data-run-panel
    >
        <div class="flex items-center gap-2 border-b px-3 py-1.5">
            <div class="bg-muted/50 flex gap-1 rounded-lg p-0.5">
                <button
                    v-for="(text, key) in {
                        run: 'Run',
                        history: 'History',
                        activity: 'Activity',
                    }"
                    :key="key"
                    type="button"
                    class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="
                        tab === key
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="tab = key"
                >
                    <Play v-if="key === 'run'" class="size-3.5" />
                    <History v-else-if="key === 'history'" class="size-3.5" />
                    <ScrollText v-else class="size-3.5" />
                    {{ text }}
                </button>
            </div>

            <template v-if="run && tab === 'run'">
                <span
                    class="flex items-center gap-1.5 text-xs font-medium"
                    :class="statusLook(run.status).tone"
                    data-run-status
                >
                    <component
                        :is="statusLook(run.status).icon"
                        class="size-3.5"
                        :class="{ 'animate-spin': statusLook(run.status).spin }"
                    />
                    {{ statusLook(run.status).label }}
                </span>
                <span class="text-muted-foreground truncate text-xs">
                    {{ versionText(run) }} · {{ at(run.created_at) }}
                    <template v-if="run.duration_ms !== null">
                        · {{ duration(run.duration_ms) }}</template
                    >
                    <template v-if="run.started_by">
                        · by {{ run.started_by }}</template
                    >
                </span>
            </template>

            <Button
                v-if="tab === 'run' && going && run?.can.act"
                size="sm"
                variant="ghost"
                class="text-destructive hover:text-destructive ml-auto h-7"
                data-run-cancel
                @click="emit('cancel')"
            >
                <Square /> Stop
            </Button>

            <Button
                size="icon-sm"
                variant="ghost"
                :class="{
                    'ml-auto': !(tab === 'run' && going && run?.can.act),
                }"
                aria-label="Close the run panel"
                @click="emit('close')"
            >
                <X />
            </Button>
        </div>

        <!-- One run -->
        <div v-if="tab === 'run'" class="flex min-h-0 flex-1">
            <p v-if="!run" class="text-muted-foreground m-auto text-sm">
                Press Run to try the workflow as it is saved.
            </p>

            <template v-else>
                <ol class="w-64 shrink-0 overflow-y-auto border-r py-1">
                    <li v-for="step in ordered" :key="step.node_id">
                        <button
                            type="button"
                            class="hover:bg-muted/60 flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm"
                            :class="{
                                'bg-muted': step.node_id === selectedId,
                                'opacity-60': [
                                    'skipped',
                                    'cancelled',
                                    'pending',
                                ].includes(step.status),
                            }"
                            :data-run-step="step.node_key"
                            :data-run-step-status="step.status"
                            @click="
                                emit(
                                    'select',
                                    step.node_id === selectedId
                                        ? null
                                        : step.node_id,
                                )
                            "
                        >
                            <component
                                :is="statusLook(step.status).icon"
                                class="size-4 shrink-0"
                                :class="[
                                    statusLook(step.status).tone,
                                    {
                                        'animate-spin': statusLook(step.status)
                                            .spin,
                                    },
                                ]"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                label(step)
                            }}</span>
                            <span
                                class="text-muted-foreground shrink-0 text-[11px] tabular-nums"
                                >{{ duration(step.duration_ms) }}</span
                            >
                        </button>
                    </li>
                </ol>

                <div class="min-w-0 flex-1 overflow-y-auto p-3 text-sm">
                    <!-- Someone's answer is wanted -->
                    <div
                        v-for="step in asking"
                        :key="step.node_id"
                        class="mb-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3"
                        data-approval
                    >
                        <p
                            class="mb-1 text-xs font-medium text-amber-700 dark:text-amber-400"
                        >
                            {{
                                step.waiting_for === 'resend'
                                    ? `${label(step)} may not have gone out`
                                    : `${label(step)} asks`
                            }}
                            <template v-if="step.resume_at">
                                · until
                                {{ new Date(step.resume_at).toLocaleString() }}
                            </template>
                        </p>
                        <p class="mb-2 text-sm">{{ step.wait?.message }}</p>
                        <div v-if="run.can.act" class="flex flex-wrap gap-2">
                            <input
                                v-model="note"
                                class="bg-background focus-visible:ring-ring/50 h-8 min-w-40 flex-1 rounded-md border px-2 text-xs outline-none focus-visible:ring-[3px]"
                                placeholder="A note, if you like"
                                aria-label="Note"
                                maxlength="500"
                            />
                            <Button
                                size="sm"
                                data-approve
                                @click="
                                    emit('answer', step.node_id, true, note)
                                "
                            >
                                <Check />
                                {{
                                    step.waiting_for === 'resend'
                                        ? 'Send it again'
                                        : 'Approve'
                                }}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                data-reject
                                @click="
                                    emit('answer', step.node_id, false, note)
                                "
                            >
                                <X />
                                {{
                                    step.waiting_for === 'resend'
                                        ? "Don't"
                                        : 'Reject'
                                }}
                            </Button>
                        </div>
                        <p v-else class="text-muted-foreground text-xs">
                            Someone who can edit the workflow answers.
                        </p>
                    </div>

                    <!-- An answer as it is written -->
                    <div
                        v-for="step in streaming"
                        :key="`writing-${step.node_id}`"
                        class="mb-3"
                        data-run-stream
                    >
                        <p
                            class="text-muted-foreground mb-1 text-[11px] font-medium uppercase"
                        >
                            {{ label(step) }} is writing
                        </p>
                        <p
                            class="bg-muted/40 max-h-48 overflow-auto rounded-md border p-2 text-xs leading-relaxed whitespace-pre-wrap"
                        >
                            <span
                                v-if="writing[step.node_id].gap"
                                class="text-muted-foreground"
                                >…</span
                            >{{ writing[step.node_id].text
                            }}<span
                                class="bg-foreground/60 ml-0.5 inline-block h-3 w-1.5 animate-pulse align-middle"
                            />
                        </p>
                    </div>

                    <!-- What went wrong, first -->
                    <div
                        v-if="(chosen?.error ?? null) || (!chosen && run.error)"
                        class="border-destructive/30 bg-destructive/5 text-destructive mb-3 flex gap-2 rounded-lg border p-2.5 text-xs"
                        data-run-error
                    >
                        <CircleAlert class="mt-px size-3.5 shrink-0" />
                        <span>
                            <template v-if="!chosen && run.reason"
                                >{{ reasons[run.reason] ?? run.reason }}:
                            </template>
                            {{ chosen ? chosen.error?.message : run.error }}
                        </span>
                    </div>

                    <template v-if="chosen">
                        <p class="mb-2 text-xs">
                            <span class="font-medium">{{ label(chosen) }}</span>
                            <span class="text-muted-foreground">
                                ·
                                {{
                                    statusLook(
                                        chosen.status,
                                    ).label.toLowerCase()
                                }}<template
                                    v-if="
                                        chosen.handle && chosen.handle !== 'out'
                                    "
                                >
                                    · went "{{ chosen.handle }}"</template
                                ></span
                            >
                        </p>

                        <p
                            v-if="
                                chosen.status === 'waiting' &&
                                chosen.waiting_for === 'delay'
                            "
                            class="mb-3 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400"
                        >
                            <Timer class="size-3.5" />
                            Waiting until
                            {{ new Date(chosen.resume_at!).toLocaleString() }}
                        </p>
                        <p
                            v-if="
                                chosen.status === 'waiting' &&
                                chosen.waiting_for === 'children'
                            "
                            class="mb-3 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400"
                            data-run-children
                        >
                            <Timer class="size-3.5" />
                            Waiting for the runs it started, one for each of
                            {{
                                Array.isArray(chosen.wait?.items)
                                    ? chosen.wait.items.length
                                    : 'its'
                            }}
                            items
                        </p>
                        <p
                            v-if="chosen.wait?.answer"
                            class="text-muted-foreground mb-3 text-xs"
                        >
                            {{
                                chosen.wait.answer.approved
                                    ? 'Approved'
                                    : 'Rejected'
                            }}
                            by {{ chosen.wait.answer.by
                            }}<template v-if="chosen.wait.answer.note"
                                >: “{{ chosen.wait.answer.note }}”</template
                            >
                        </p>
                        <div
                            v-if="chosen.input || chosen.output !== null"
                            class="grid gap-3 lg:grid-cols-3"
                        >
                            <div v-if="chosen.input">
                                <p
                                    class="text-muted-foreground mb-1 text-[11px] font-medium uppercase"
                                >
                                    Input
                                </p>
                                <pre
                                    class="bg-muted/40 max-h-40 overflow-auto rounded-md border p-2 font-mono text-[11px]"
                                    data-run-input
                                    >{{
                                        pretty(chosen.input.input) || 'nothing'
                                    }}</pre>
                            </div>
                            <div
                                v-if="
                                    chosen.input &&
                                    Object.keys(chosen.input.settings).length
                                "
                            >
                                <p
                                    class="text-muted-foreground mb-1 text-[11px] font-medium uppercase"
                                >
                                    Settings worked out
                                </p>
                                <pre
                                    class="bg-muted/40 max-h-40 overflow-auto rounded-md border p-2 font-mono text-[11px]"
                                    >{{ pretty(chosen.input.settings) }}</pre>
                            </div>
                            <div v-if="chosen.status === 'succeeded'">
                                <p
                                    class="text-muted-foreground mb-1 text-[11px] font-medium uppercase"
                                >
                                    Output
                                </p>
                                <pre
                                    class="bg-muted/40 max-h-40 overflow-auto rounded-md border p-2 font-mono text-[11px]"
                                    data-run-output
                                    >{{ pretty(chosen.output) }}</pre>
                            </div>
                        </div>
                        <p v-else class="text-muted-foreground text-xs">
                            {{
                                chosen.status === 'skipped'
                                    ? 'It was on a way the run did not take.'
                                    : 'It has not run.'
                            }}
                        </p>
                    </template>

                    <p
                        v-else-if="!run.error"
                        class="text-muted-foreground mb-3 text-xs"
                    >
                        Choose a step to see what it was given and gave out.
                    </p>

                    <div v-if="logs.length" class="mt-3">
                        <p
                            class="text-muted-foreground mb-1 text-[11px] font-medium uppercase"
                        >
                            Log
                        </p>
                        <ul
                            class="bg-muted/40 divide-y rounded-md border font-mono text-[11px]"
                            data-run-logs
                        >
                            <li
                                v-for="(log, index) in logs"
                                :key="index"
                                class="flex gap-2 px-2 py-1"
                                :class="{
                                    'text-amber-700 dark:text-amber-400':
                                        log.level === 'warning',
                                    'text-destructive': log.level === 'error',
                                }"
                            >
                                <span class="text-muted-foreground shrink-0">{{
                                    at(log.created_at)
                                }}</span>
                                <span class="break-all">{{ log.message }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>
        </div>

        <!-- The runs before -->
        <div
            v-else-if="tab === 'activity'"
            class="min-h-0 flex-1 overflow-y-auto p-3"
            data-run-activity
        >
            <ActivityList
                :activity="activity"
                empty="No one has published, switched on or run it by hand yet."
            />
        </div>

        <div v-else class="min-h-0 flex-1 overflow-y-auto">
            <p
                v-if="!history.length"
                class="text-muted-foreground p-6 text-center text-sm"
            >
                It has not been run yet.
            </p>
            <ul v-else class="divide-y">
                <li v-for="past in history" :key="past.ref_id">
                    <button
                        type="button"
                        class="hover:bg-muted/60 flex w-full items-center gap-3 px-3 py-2 text-left text-sm"
                        :data-history-run="past.ref_id"
                        @click="emit('open', past.ref_id)"
                    >
                        <component
                            :is="statusLook(past.status).icon"
                            class="size-4 shrink-0"
                            :class="[
                                statusLook(past.status).tone,
                                {
                                    'animate-spin': statusLook(past.status)
                                        .spin,
                                },
                            ]"
                        />
                        <span class="w-24 shrink-0">{{
                            statusLook(past.status).label
                        }}</span>
                        <span
                            class="text-muted-foreground min-w-0 flex-1 truncate text-xs"
                        >
                            {{ new Date(past.created_at).toLocaleString() }} ·
                            {{ versionText(past) }}
                            <template v-if="past.started_by">
                                · by {{ past.started_by }}</template
                            >
                        </span>
                        <span
                            class="text-muted-foreground shrink-0 text-xs tabular-nums"
                            >{{ duration(past.duration_ms) }}</span
                        >
                    </button>
                </li>
            </ul>
        </div>
    </section>
</template>
