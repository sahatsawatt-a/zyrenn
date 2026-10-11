<script setup lang="ts">
import {
    Check,
    ChevronRight,
    CircleAlert,
    History,
    Play,
    ScrollText,
    Square,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ActivityList from '@/features/audit/ActivityList.vue';
import type { Activity } from '@/features/audit/ActivityList.vue';
import RunStepDetail from '@/features/workflow/components/editor/RunStepDetail.vue';
import type { Written } from '@/features/workflow/composables/useWorkflowRuns';
import { duration, statusLook } from '@/features/workflow/steps';
import type {
    Run,
    RunStep,
    RunSummary,
    StepNode,
} from '@/features/workflow/types';

// Under the editor: how a run went, read down the page -- each step on a
// line with what it gave, in the order they ran. A step opened shows what it
// was given beside what it gave, part by part, and opens larger over the
// page. And the runs before this one.

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
    // On a narrow screen: more of its height, and its top in fewer words
    compact?: boolean;
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

const logsOf = (step?: RunStep) =>
    (props.run?.logs ?? []).filter(
        (log) => !step || log.node_id === step.node_id,
    );

// A failed run with no step to say why says it itself
const unplaced = computed(
    () =>
        !!props.run?.error &&
        !props.run.steps.some((step) => step.status === 'failed'),
);

const label = (step: RunStep) =>
    props.steps[step.node_id]?.label ?? step.node_key;

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

// ---- a step in a line ----

const MUTED = 'text-muted-foreground';

const clip = (text: string, most: number) => {
    const line = text.replace(/\s+/g, ' ').trim();

    return line.length > most ? `${line.slice(0, most)}…` : line;
};

// One part, in a few words
function brief(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return 'empty';
    }

    if (Array.isArray(value)) {
        return `${value.length} ${value.length === 1 ? 'item' : 'items'}`;
    }

    return clip(String(value), 60);
}

const holdsParts = (value: unknown) =>
    !!value && typeof value === 'object' && !Array.isArray(value);

// What a step gave: its first few plain parts by name, then its lists --
// what holds parts of its own is left to be opened -- or the one thing it is
function preview(value: unknown): string {
    if (!holdsParts(value)) {
        return typeof value === 'string' ? clip(value, 160) : brief(value);
    }

    const parts = Object.entries(value as Record<string, unknown>);
    const plain = parts.filter(([, part]) => typeof part !== 'object');
    const lists = parts.filter(([, part]) => Array.isArray(part));
    const said = [...plain, ...lists].slice(0, 3);

    if (!said.length) {
        return parts.length
            ? `${parts.length} ${parts.length === 1 ? 'part' : 'parts'}`
            : '';
    }

    return (
        said.map(([name, part]) => `${name}: ${brief(part)}`).join('  ·  ') +
        (parts.length > said.length ? '  ·  …' : '')
    );
}

// What there is to say of a step without opening it
function gist(step: RunStep): { text: string; tone: string } {
    switch (step.status) {
        case 'failed':
            return {
                text: step.error?.message ?? 'It failed.',
                tone: 'text-destructive',
            };
        case 'running':
            return {
                text: props.writing[step.node_id]
                    ? clip(props.writing[step.node_id].text.slice(-160), 160)
                    : '',
                tone: MUTED,
            };
        case 'waiting':
        case 'retry_wait':
            return {
                text:
                    step.waiting_for === 'approval'
                        ? 'for an answer'
                        : step.waiting_for === 'resend'
                          ? 'it may not have gone out'
                          : step.waiting_for === 'children'
                            ? 'for the runs it started'
                            : step.resume_at
                              ? `until ${new Date(step.resume_at).toLocaleString()}`
                              : 'to try again',
                tone: 'text-amber-700 dark:text-amber-400',
            };
        case 'skipped':
            return { text: 'not on the way the run took', tone: MUTED };
        case 'succeeded': {
            // Which way it went is the news of a step with ways out
            if (step.handle && step.handle !== 'out') {
                return { text: `went "${step.handle}"`, tone: MUTED };
            }

            // And of a Log, the line it wrote
            const wrote =
                step.node_type === 'output.log'
                    ? logsOf(step).at(-1)?.message
                    : undefined;

            return {
                text: wrote ? clip(wrote, 160) : preview(step.output),
                tone: 'text-foreground/80',
            };
        }
        default:
            return { text: '', tone: MUTED };
    }
}

// A step opened larger, over the page
const expandedId = ref<string | null>(null);
const expanded = computed(() =>
    props.run?.steps.find((step) => step.node_id === expandedId.value),
);

// A step chosen on the canvas is brought into sight here
const list = ref<HTMLElement | null>(null);

watch(
    () => props.selectedId,
    async (id) => {
        await nextTick();

        if (id) {
            list.value
                ?.querySelector(`[data-run-row="${id}"]`)
                ?.scrollIntoView({ block: 'nearest' });
        }
    },
);
</script>

<template>
    <section
        class="bg-background flex shrink-0 flex-col border-t"
        :class="compact ? 'h-[55%]' : 'h-80'"
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
                    <template v-if="!compact">
                        <Play v-if="key === 'run'" class="size-3.5" />
                        <History
                            v-else-if="key === 'history'"
                            class="size-3.5"
                        />
                        <ScrollText v-else class="size-3.5" />
                    </template>
                    {{ text }}
                </button>
            </div>

            <template v-if="run && tab === 'run'">
                <span
                    class="flex shrink-0 items-center gap-1.5 text-xs font-medium"
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
                <span
                    v-if="!compact"
                    class="text-muted-foreground truncate text-xs"
                >
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

        <!-- One run, read down the page -->
        <div
            v-if="tab === 'run'"
            ref="list"
            class="min-h-0 flex-1 overflow-y-auto"
        >
            <p
                v-if="!run"
                class="text-muted-foreground p-6 text-center text-sm"
            >
                Press Run to try the workflow as it is saved.
            </p>

            <template v-else>
                <!-- Someone's answer is wanted -->
                <div
                    v-for="step in asking"
                    :key="step.node_id"
                    class="m-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3"
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
                            @click="emit('answer', step.node_id, true, note)"
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
                            @click="emit('answer', step.node_id, false, note)"
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
                    class="m-3"
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

                <div
                    v-if="unplaced"
                    class="border-destructive/30 bg-destructive/5 text-destructive m-3 flex gap-2 rounded-lg border p-2.5 text-xs"
                    data-run-error
                >
                    <CircleAlert class="mt-px size-3.5 shrink-0" />
                    <span>
                        <template v-if="run.reason"
                            >{{ reasons[run.reason] ?? run.reason }}:
                        </template>
                        {{ run.error }}
                    </span>
                </div>

                <ol class="divide-y border-b">
                    <li
                        v-for="step in ordered"
                        :key="step.node_id"
                        :data-run-row="step.node_id"
                    >
                        <button
                            type="button"
                            class="hover:bg-muted/60 flex w-full items-start gap-2.5 px-3 py-2 text-left text-sm"
                            :class="{
                                'bg-muted/60': step.node_id === selectedId,
                            }"
                            :aria-expanded="step.node_id === selectedId"
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
                                class="mt-0.5 size-4 shrink-0"
                                :class="[
                                    statusLook(step.status).tone,
                                    {
                                        'animate-spin': statusLook(step.status)
                                            .spin,
                                    },
                                ]"
                                :aria-label="statusLook(step.status).label"
                            />
                            <span
                                class="min-w-0 flex-1"
                                :class="{
                                    'sm:flex sm:items-baseline sm:gap-3':
                                        !compact,
                                    'opacity-60': [
                                        'skipped',
                                        'cancelled',
                                        'pending',
                                    ].includes(step.status),
                                }"
                            >
                                <span
                                    :class="{ 'sm:w-52': !compact }"
                                    class="block shrink-0 truncate font-medium"
                                    >{{ label(step) }}</span
                                >
                                <!-- What it gave, or why it did not -->
                                <span
                                    v-if="
                                        gist(step).text &&
                                        !(
                                            step.status === 'failed' &&
                                            step.node_id === selectedId
                                        )
                                    "
                                    class="block min-w-0 flex-1 text-xs"
                                    :class="[
                                        gist(step).tone,
                                        step.status === 'failed'
                                            ? 'line-clamp-2'
                                            : 'truncate',
                                    ]"
                                    :data-run-error="
                                        step.status === 'failed'
                                            ? ''
                                            : undefined
                                    "
                                    >{{ gist(step).text }}</span
                                >
                            </span>
                            <span
                                class="text-muted-foreground mt-0.5 shrink-0 text-[11px] tabular-nums"
                                >{{ duration(step.duration_ms) }}</span
                            >
                            <ChevronRight
                                class="text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform"
                                :class="{
                                    'rotate-90': step.node_id === selectedId,
                                }"
                            />
                        </button>

                        <!-- Opened: what it was given, and what it gave -->
                        <div
                            v-if="step.node_id === selectedId"
                            class="bg-muted/20 border-t py-3 pr-3"
                            :class="compact ? 'pl-3' : 'pl-9'"
                        >
                            <RunStepDetail
                                :step="step"
                                :logs="logsOf(step)"
                                @expand="expandedId = step.node_id"
                            />
                        </div>
                    </li>
                </ol>

                <!-- Everything the run wrote, while no one step is looked at -->
                <div v-if="!chosen && logsOf().length" class="p-3">
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
                            v-for="(log, index) in logsOf()"
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

        <!-- One step, with the room to read all of it -->
        <Dialog
            :open="!!expanded"
            @update:open="!$event && (expandedId = null)"
        >
            <DialogContent
                v-if="expanded"
                class="flex max-h-[92svh] flex-col sm:max-w-[min(96vw,110rem)]"
                data-run-modal
            >
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <component
                            :is="statusLook(expanded.status).icon"
                            class="size-4 shrink-0"
                            :class="[
                                statusLook(expanded.status).tone,
                                {
                                    'animate-spin': statusLook(expanded.status)
                                        .spin,
                                },
                            ]"
                        />
                        {{ label(expanded) }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ statusLook(expanded.status).label }}
                        <template v-if="expanded.duration_ms !== null">
                            · {{ duration(expanded.duration_ms) }}</template
                        >
                        <template
                            v-if="expanded.handle && expanded.handle !== 'out'"
                        >
                            · went "{{ expanded.handle }}"</template
                        >
                    </DialogDescription>
                </DialogHeader>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <RunStepDetail
                        :step="expanded"
                        :logs="logsOf(expanded)"
                        large
                    />
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
