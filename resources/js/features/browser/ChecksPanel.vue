<script setup lang="ts">
import {
    ChevronDown,
    CircleCheck,
    CircleDashed,
    CircleStop,
    CircleX,
    Clapperboard,
    Copy,
    EllipsisVertical,
    Film,
    FolderOpen,
    History,
    ListChecks,
    Loader2,
    Monitor,
    MonitorCheck,
    MousePointerClick,
    PencilLine,
    FlaskConical,
    Play,
    Plus,
    Search,
    Settings2,
    SlidersHorizontal,
    Smartphone,
    Tablet,
    Trash2,
} from '@lucide/vue';
import { echoIsConfigured } from '@laravel/echo-vue';
import { Link } from '@inertiajs/vue3';
import { useDebounceFn, useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import { usePresence } from '@/composables/usePresence';
import NameDialog from '@/components/common/NameDialog.vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    closeLive,
    stepLive,
} from '@/actions/App/Http/Controllers/Browser/BrowserCheckController';
import BuildDialog from './BuildDialog.vue';
import CheckRunView from './CheckRunView.vue';
import { ask } from './http';
import RunBars from './RunBars.vue';
import RunOptionsDialog from './RunOptionsDialog.vue';
import StepsEditor from './StepsEditor.vue';
import { SIZES as SCREEN_SIZES, SPEEDS, stepId } from './steps';
import SuiteSettings from './SuiteSettings.vue';
import SuiteVersions from './SuiteVersions.vue';
import { ago, took } from './time';
import type {
    Check,
    CheckRun,
    Limits,
    LivePage,
    LiveStep,
    Look,
    Pace,
    RunChoice,
    RunState,
    Size,
    Speed,
    StartedBy,
    Step,
    Suite,
    SuiteSummary,
} from './steps';

// Browser checks, kept as suites -- a customer's site, say, in that customer's
// project -- each check a few steps done in a real browser on the server. A
// suite is shown as how it is doing (its last runs, how long they take, when
// it next runs by itself), then its checks, its runs and its settings, each a
// tab of its own. Run quick to test the site, or slowed with a pointer,
// captions and a film to show it; pictures and films can go to the Drive.

const props = defineProps<{
    suites: SuiteSummary[];
    spaces: { label: string; url: string; current: boolean }[];
    space: string | null;
    canChange: boolean;
    newSuite: string;
    speeds: Record<Exclude<Speed, 'custom'>, Pace>;
    limits: Limits;
}>();

// ------------------------------------------------------------ Which suite
const summaries = ref(props.suites);
const suite = ref<Suite | null>(null);
const tab = ref<'checks' | 'runs' | 'settings'>('checks');
const find = ref('');
const shownSuites = computed(() =>
    summaries.value.filter((one) =>
        one.title.toLowerCase().includes(find.value.trim().toLowerCase()),
    ),
);

// The last suite chosen wins, and its tab is set when it is chosen: a slow
// load must not take back a tab moved to meanwhile
let choosing = 0;

async function choose(summary: SuiteSummary) {
    const mine = ++choosing;
    tab.value = 'checks';
    await saveNow();
    const chosen = await ask<Suite>({ url: summary.url, method: 'get' });

    if (mine !== choosing) {
        return;
    }

    suite.value = chosen;
    suite.value.latest ??= {};
    openChecks.value = new Set();
    run.value = null;

    if (suite.value.runs[0]) {
        await showRun(suite.value.runs[0].url);
    }
}

const naming = ref(false);

async function make(title: string) {
    naming.value = false;

    try {
        const made = await ask<Suite>(
            { url: props.newSuite, method: 'post' },
            { title },
        );
        made.latest ??= {};
        summaries.value.unshift({
            ref_id: made.ref_id,
            title: made.title,
            checks: made.checks.length,
            last: null,
            recent: [],
            url: made.urls.update,
        });
        suite.value = made;
        openChecks.value = new Set();
        run.value = null;
        tab.value = 'checks';
    } catch (problem) {
        toast.error((problem as Error).message);
    }
}

const deleting = ref(false);

async function remove() {
    if (!suite.value) {
        return;
    }

    deleting.value = false;
    const gone = suite.value.ref_id;
    await ask({ url: suite.value.urls.update, method: 'delete' });
    summaries.value = summaries.value.filter((one) => one.ref_id !== gone);
    suite.value = null;
    run.value = null;

    if (summaries.value[0]) {
        await choose(summaries.value[0]);
    }
}

// ------------------------------------------------------------ Kept as it is written
const status = ref<'saved' | 'saving' | 'unsaved' | 'error'>('saved');
let pending = false;

async function saveNow() {
    if (!pending || !suite.value) {
        return;
    }

    pending = false;
    status.value = 'saving';
    const saving = suite.value;

    // Each step made here since the last save: given its own id now, once,
    // so the next save sends the same one and its history follows it
    for (const check of saving.checks) {
        for (const step of check.steps) {
            step.id ??= stepId();
        }
    }

    try {
        await ask(
            { url: saving.urls.update, method: 'patch' },
            { title: saving.title, checks: saving.checks },
        );
        status.value = pending ? 'unsaved' : 'saved';
        const listed = summaries.value.find(
            (one) => one.ref_id === saving.ref_id,
        );

        if (listed) {
            listed.title = saving.title;
            listed.checks = saving.checks.length;
        }
    } catch (problem) {
        status.value = 'error';
        toast.error((problem as Error).message);
    }
}

const saveSoon = useDebounceFn(saveNow, 700);
const changed = () => {
    pending = true;
    status.value = 'unsaved';
    void saveSoon();
};

const STATUS = {
    saved: 'Saved',
    saving: 'Saving…',
    unsaved: 'Not saved yet',
    error: "Couldn't save",
};

// ------------------------------------------------------------ How it is doing
const recent = computed<RunState[]>(() =>
    [...(suite.value?.runs ?? [])].reverse().map((one) => one.state),
);
const finished = computed(() =>
    (suite.value?.runs ?? []).filter((one) =>
        ['passed', 'failed'].includes(one.state),
    ),
);
// Of its last runs, how many passed
const health = computed(() =>
    finished.value.length
        ? Math.round(
              (finished.value.filter((one) => one.state === 'passed').length /
                  finished.value.length) *
                  100,
          )
        : null,
);
const lastRun = computed(() => suite.value?.runs[0] ?? null);
const standing = computed(() => {
    const last = finished.value[0]?.state;

    if (going.value) {
        return {
            label: 'Running',
            tone: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
        };
    }

    return last === 'passed'
        ? {
              label: 'Passing',
              tone: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
          }
        : last === 'failed'
          ? {
                label: 'Failing',
                tone: 'bg-red-500/10 text-red-700 dark:text-red-300',
            }
          : { label: 'Not run yet', tone: 'bg-muted text-muted-foreground' };
});
const EVERY_SAID = {
    off: 'Only when asked',
    hour: 'Every hour',
    day: 'Every day',
    weekdays: 'On weekdays',
};
const nextRun = computed(() =>
    suite.value?.next_run_at
        ? new Intl.DateTimeFormat(undefined, {
              weekday: 'short',
              hour: 'numeric',
              minute: '2-digit',
          }).format(new Date(suite.value.next_run_at))
        : null,
);

// ------------------------------------------------------------ Its checks
const openChecks = ref<Set<string>>(new Set());
const toggleCheck = (id: string) => {
    const next = new Set(openChecks.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    openChecks.value = next;
};

const newId = () => Math.random().toString(36).slice(2, 10);

/** A check written step by step, by hand: it starts on a page to open. */
function addCheck() {
    const id = newId();
    suite.value!.checks.push({
        id,
        title: 'New check',
        steps: [{ do: 'open', url: 'https://' }],
    });
    openChecks.value = new Set([...openChecks.value, id]);
    changed();
}

function copyCheck(at: number) {
    const check = suite.value!.checks[at];
    const id = newId();
    suite.value!.checks.splice(at + 1, 0, {
        ...check,
        id,
        title: `${check.title} (copy)`,
        steps: check.steps.map((step) => ({ ...step })),
    });
    openChecks.value = new Set([...openChecks.value, id]);
    changed();
}

function removeCheck(at: number) {
    suite.value!.checks.splice(at, 1);
    changed();
}

const SIZE_ICONS = { desktop: Monitor, tablet: Tablet, phone: Smartphone };

// ------------------------------------------------------------ Built by clicking
// The check being built on, or null for a new one
const building = ref<Check | null>(null);
const buildOpen = ref(false);

function build(check: Check | null) {
    building.value = check;
    buildOpen.value = true;
}

function built(steps: Step[], title: string, size: Size) {
    if (building.value) {
        building.value.steps = steps;
        building.value.title ||= title;
        building.value.size = size;
    } else {
        const id = newId();
        suite.value!.checks.push({ id, title, size, steps });
        openChecks.value = new Set([...openChecks.value, id]);
    }

    tab.value = 'checks';
    changed();
}

// The page some steps lead to, and what is on it: for picking a step's
// thing, and for building a check by clicking
const lookSteps = (steps: Step[], title = '', size: Size = 'desktop') =>
    ask<Look>(
        { url: suite.value!.urls.look, method: 'post' },
        { title, steps, size },
    );
const lookAt = (check: Check, upto: number) =>
    lookSteps(check.steps.slice(0, upto), check.title, check.size);

// A page kept open in the browser while a check is built on it
const openPage = (steps: Step[], size: Size) =>
    ask<LivePage>(
        { url: suite.value!.urls.live, method: 'post' },
        { title: building.value?.title ?? '', steps, size },
    );
const stepPage = (id: string, step: Step) =>
    ask<LiveStep>(stepLive(id), { step });
const closePage = (id: string) => ask(closeLive(id));

// --------------------------------------------------------- Signing in
// In a suite that signs in once a run, each check starts as its first
// sign-in, as another, or signed out; a sign-in's own check starts out
const SIGNED_OUT = '-';

/** Whom this check signs in as, when it is one of the suite's sign-ins. */
const signsInAs = (check: Check) =>
    (suite.value?.sign_ins ?? [])
        .filter((one) => one.check === check.id)
        .map((one) => one.name)
        .join(', ');

const startsAs = (check: Check) =>
    check.fresh
        ? SIGNED_OUT
        : (check.as ?? suite.value?.sign_ins[0]?.name ?? SIGNED_OUT);

function startAs(check: Check, whom: string) {
    const first = suite.value?.sign_ins[0]?.name;

    check.fresh = whom === SIGNED_OUT ? true : undefined;
    check.as = whom === SIGNED_OUT || whom === first ? undefined : whom;
    changed();
}

// ------------------------------------------------------------ Runs
const run = ref<CheckRun | null>(null);

// Its earlier versions, and one put back: the suite as it now is
const versionsOpen = ref(false);
const restored = (back: Suite) => {
    suite.value = back;
};
// The first run to keep anything makes the suite a folder in the Drive: the
// way there shows from then on, without the page asked again
watch(
    () => run.value?.suite_folder,
    (folder) => {
        if (folder && suite.value && !suite.value.folder) {
            suite.value.folder = folder;
        }
    },
);
const starting = ref<'test' | 'demo' | 'custom' | null>(null);
const going = computed(
    () => run.value?.state === 'waiting' || run.value?.state === 'running',
);
const optionsOpen = ref(false);

// Only the run asked for last is shown: a suite's last run, still on its way
// when one is started, would otherwise land over the new one
let showing = 0;

async function showRun(url: string) {
    const mine = ++showing;
    const got = await ask<CheckRun>({ url, method: 'get' });

    if (mine === showing) {
        run.value = got;
    }
}

// Stopped part way: one waiting never starts, one going ends after the step
// in hand -- and is followed on until it has
async function stopRun() {
    if (!run.value) {
        return;
    }

    try {
        run.value = await ask<CheckRun>({
            url: run.value.stop,
            method: 'post',
        });
    } catch (problem) {
        toast.error((problem as Error).message);
    }
}

// Most runs are one of two: a quick test, or a film to show someone --
// one for each check, or all of them one after another as one film
function runAs(
    kind: 'test' | 'demo',
    only: string | null = null,
    oneFilm = false,
    // Only a try, while a check is being written: a quick test kept nowhere
    trial = false,
) {
    void start(kind, {
        speed: kind === 'test' ? 'quick' : 'steady',
        pace: props.speeds[kind === 'test' ? 'quick' : 'steady'],
        captions: kind === 'demo',
        record: kind === 'demo',
        keep: kind === 'demo',
        checks: only ? [only] : [],
        one_film: oneFilm,
        trial,
    });
}

async function start(kind: 'test' | 'demo' | 'custom', choice: RunChoice) {
    if (!suite.value) {
        return;
    }

    starting.value = kind;
    showing++;
    await saveNow();

    try {
        run.value = await ask<CheckRun>(
            { url: suite.value.urls.runs, method: 'post' },
            choice,
        );
        const started = run.value;

        // (the channel may have told of it already); a try is never listed
        if (
            !started.trial &&
            !suite.value.runs.some((one) => one.ref_id === started.ref_id)
        ) {
            suite.value.runs.unshift({
                ref_id: started.ref_id,
                state: started.state,
                speed: choice.speed,
                record: choice.record,
                started_by: 'person',
                created_at: started.created_at,
                url: started.url,
            });
        }
        tab.value = 'runs';
    } catch (problem) {
        toast.error((problem as Error).message);
    } finally {
        starting.value = null;
    }
}

// ---------------------------------------------------- Following runs
// How a run went, shown where its checks and the suite are listed too --
// once it has ended, from the whole of it
const settled = new Set<string>();

function settle(now: CheckRun) {
    const current = suite.value;

    // A try says nothing of how the suite stands
    if (!current || settled.has(now.ref_id) || now.trial) {
        return;
    }

    settled.add(now.ref_id);
    const listed = current.runs.find((one) => one.ref_id === now.ref_id);

    if (listed) {
        listed.state = now.state;
        listed.passed = now.results.filter((check) => check.ok).length;
        listed.total = now.results.length;
        listed.took =
            now.started_at && now.ended_at
                ? new Date(now.ended_at).getTime() -
                  new Date(now.started_at).getTime()
                : null;
    }

    for (const check of now.results) {
        // Not run, for a sign-in that failed: it says nothing of the check
        if (!check.not_run) {
            current.latest[check.id] = {
                ok: check.ok,
                took: check.took,
                at: now.created_at,
            };
        }
    }

    const summary = summaries.value.find(
        (one) => one.ref_id === current.ref_id,
    );

    if (summary) {
        summary.last = now.state;
        summary.last_at = now.created_at;
        summary.recent = [...(summary.recent ?? []), now.state].slice(-10);
    }
}

const ended = (state: RunState) => state !== 'waiting' && state !== 'running';

/** The run shown, read whole again: its checks' steps and pictures. */
async function follow() {
    if (!run.value) {
        return;
    }

    await showRun(run.value.url);

    if (run.value && ended(run.value.state)) {
        settle(run.value);
    }
}

// What the suite's channel tells of each of its runs as it moves on --
// started here, in another tab, by its timetable, a workflow or an
// assistant. Each message is the whole of where a run stands, numbered, so
// one that arrives late is older and left be.
type RunHeard = {
    run: string;
    state: RunState;
    // Only a try: followed while it is shown, never listed
    trial: boolean;
    stop_asked: boolean;
    seq: number;
    speed: Speed;
    record: boolean;
    started_by: StartedBy;
    created_at: string;
    url: string;
    checks: { id: string; ok: boolean; done: number }[];
};

const newest = new Map<string, number>();

function heard(news: RunHeard) {
    const current = suite.value;

    if (!current || (newest.get(news.run) ?? 0) > news.seq) {
        return;
    }

    newest.set(news.run, news.seq);

    // A try is followed only while it is the run shown
    if (news.trial && run.value?.ref_id !== news.run) {
        return;
    }

    let listed = current.runs.find((one) => one.ref_id === news.run);

    if (!listed && !news.trial) {
        listed = {
            ref_id: news.run,
            state: news.state,
            speed: news.speed,
            record: news.record,
            started_by: news.started_by,
            created_at: news.created_at,
            url: news.url,
        };
        current.runs.unshift(listed);
    }

    if (listed) {
        listed.state = news.state;
    }

    // The run shown: where it stands at once, and its checks read again
    // only when one has more to show
    const shown = run.value;

    if (shown?.ref_id === news.run) {
        const more =
            news.checks.length !== shown.results.length ||
            news.checks.some(
                (check, at) =>
                    check.done !== shown.results[at]?.steps.length ||
                    check.ok !== shown.results[at]?.ok,
            );

        shown.state = news.state;
        shown.stop_asked = news.stop_asked;

        if (more || ended(news.state)) {
            void follow();
        }

        return;
    }

    // Another of its runs, ended: read once, for how it went
    if (ended(news.state) && !settled.has(news.run)) {
        void ask<CheckRun>({ url: news.url, method: 'get' }).then(settle);
    }
}

usePresence(() => (suite.value ? `checks.${suite.value.ref_id}` : ''), {
    'run.changed': heard,
});

// Asked again now and then all the same, in case a message went astray --
// and every moment where there is no socket to hear them on
useIntervalFn(
    () => {
        if (going.value) {
            void follow();
        }
    },
    () => (echoIsConfigured() ? 30_000 : 1500),
);

const runTotal = computed(() =>
    run.value?.settings.checks.length
        ? run.value.settings.checks.length
        : (suite.value?.checks.length ?? 0),
);

const when = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

const TABS = computed(() => [
    {
        value: 'checks' as const,
        label: 'Checks',
        icon: ListChecks,
        count: suite.value?.checks.length,
        test: 'tab-checks',
    },
    {
        value: 'runs' as const,
        label: 'Runs',
        icon: History,
        count: suite.value?.runs.length,
        test: 'tab-runs',
    },
    {
        value: 'settings' as const,
        label: 'Settings',
        icon: Settings2,
        test: 'tab-settings',
    },
]);

if (summaries.value[0]) {
    void choose(summaries.value[0]);
}
</script>

<template>
    <div class="space-y-4">
        <!-- Where suites are kept -->
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <!-- Whose they are, as the sidebar's switcher chose: changed there -->
            <span class="text-muted-foreground" data-test="checks-space"
                >Kept in
                <span class="text-foreground font-medium">{{
                    spaces.find((one) => one.current)?.label
                }}</span></span
            >
            <span v-if="space" class="text-muted-foreground text-xs"
                >Pictures and films go to this project's Drive.</span
            >
            <span
                v-if="!canChange"
                class="text-muted-foreground text-xs"
                data-test="checks-readonly"
                >You can look, but only the project's owners and editors can
                change or run these.</span
            >
        </div>

        <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <!-- The suites -->
            <aside class="space-y-2">
                <div class="flex gap-2">
                    <div v-if="summaries.length > 5" class="relative flex-1">
                        <Search
                            class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
                        />
                        <Input
                            v-model="find"
                            placeholder="Find a suite"
                            class="h-8 pl-8 text-sm"
                            aria-label="Find a suite"
                        />
                    </div>
                    <Button
                        v-if="canChange"
                        :variant="summaries.length ? 'outline' : 'default'"
                        size="sm"
                        :class="summaries.length > 5 ? '' : 'w-full'"
                        data-test="suite-new"
                        @click="naming = true"
                    >
                        <Plus /> New suite
                    </Button>
                </div>
                <ul
                    v-if="summaries.length"
                    class="bg-card divide-y overflow-hidden rounded-xl border"
                    data-test="suite-list"
                >
                    <li v-for="one in shownSuites" :key="one.ref_id">
                        <button
                            type="button"
                            class="hover:bg-accent/50 flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm transition-colors"
                            :class="{
                                'bg-accent': one.ref_id === suite?.ref_id,
                            }"
                            data-test="suite-row"
                            @click="choose(one)"
                        >
                            <CircleCheck
                                v-if="one.last === 'passed'"
                                class="size-4 shrink-0 text-emerald-600"
                            />
                            <CircleX
                                v-else-if="one.last === 'failed'"
                                class="size-4 shrink-0 text-red-600"
                            />
                            <CircleStop
                                v-else-if="one.last === 'stopped'"
                                class="text-muted-foreground size-4 shrink-0"
                            />
                            <Loader2
                                v-else-if="one.last"
                                class="size-4 shrink-0 animate-spin text-sky-600"
                            />
                            <CircleDashed
                                v-else
                                class="text-muted-foreground size-4 shrink-0"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{
                                    one.title
                                }}</span>
                                <span
                                    class="text-muted-foreground block text-xs"
                                    >{{ one.checks }}
                                    {{ one.checks === 1 ? 'check' : 'checks' }}
                                    <template v-if="one.last_at"
                                        >· {{ ago(one.last_at) }}</template
                                    ></span
                                >
                            </span>
                            <RunBars
                                v-if="one.recent?.length"
                                :states="one.recent"
                                :places="6"
                                class="shrink-0"
                            />
                        </button>
                    </li>
                </ul>
                <p
                    v-else
                    class="text-muted-foreground rounded-xl border border-dashed p-4 text-center text-sm"
                >
                    No suites here yet. A suite holds the checks of one site.
                </p>
            </aside>

            <!-- One suite -->
            <section
                v-if="suite"
                class="bg-card min-w-0 rounded-xl border"
                data-test="suite"
            >
                <!-- What it is, how it is doing, and running it -->
                <header class="space-y-3 border-b p-3 sm:p-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1 basis-60">
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="suite.title"
                                    :readonly="!canChange"
                                    aria-label="The suite's name"
                                    class="hover:bg-muted/60 focus:bg-muted/60 -mx-1.5 min-w-0 flex-1 rounded-md bg-transparent px-1.5 py-0.5 text-lg font-semibold tracking-tight outline-none"
                                    data-test="suite-title"
                                    @input="changed"
                                />
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="standing.tone"
                                    data-test="suite-standing"
                                    >{{ standing.label }}</span
                                >
                            </div>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{ suite.checks.length }}
                                {{
                                    suite.checks.length === 1
                                        ? 'check'
                                        : 'checks'
                                }}
                                · {{ EVERY_SAID[suite.settings.every] }} ·
                                <span
                                    :class="{
                                        'text-destructive': status === 'error',
                                    }"
                                    data-test="suite-status"
                                    >{{ STATUS[status] }}</span
                                >
                            </p>
                        </div>

                        <div
                            v-if="canChange"
                            class="flex w-full items-center gap-2 sm:w-auto"
                        >
                            <Button
                                class="flex-1 sm:flex-none"
                                :disabled="
                                    !!starting || going || !suite.checks.length
                                "
                                title="Every check, as fast as the browser goes. A picture where one fails."
                                data-test="run-test"
                                @click="runAs('test')"
                            >
                                <Loader2
                                    v-if="
                                        starting === 'test' ||
                                        (going &&
                                            run?.settings.speed === 'quick')
                                    "
                                    class="animate-spin"
                                />
                                <Play v-else />
                                Test it
                            </Button>
                            <Button
                                variant="outline"
                                class="flex-1 sm:flex-none"
                                :disabled="
                                    !!starting || going || !suite.checks.length
                                "
                                title="The pointer glides and types, each step captioned. A film of each check goes to the Drive."
                                data-test="run-demo"
                                @click="runAs('demo')"
                            >
                                <Loader2
                                    v-if="
                                        starting === 'demo' ||
                                        (going &&
                                            run?.settings.speed !== 'quick')
                                    "
                                    class="animate-spin"
                                />
                                <Clapperboard v-else />
                                Demo video
                            </Button>
                            <!-- Where its films and pictures are kept -->
                            <Button
                                v-if="suite.folder"
                                variant="ghost"
                                size="icon"
                                as-child
                            >
                                <Link
                                    :href="suite.folder"
                                    aria-label="Open its folder in the Drive"
                                    title="Open its folder in the Drive: every run's films and pictures"
                                    data-test="suite-folder"
                                >
                                    <FolderOpen />
                                </Link>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="More for this suite"
                                        data-test="suite-menu"
                                    >
                                        <EllipsisVertical />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        :disabled="
                                            !!starting ||
                                            going ||
                                            !suite.checks.length
                                        "
                                        data-test="run-more"
                                        @select="optionsOpen = true"
                                        ><SlidersHorizontal /> Run with
                                        options…</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="suite.checks.length > 1"
                                        :disabled="!!starting || going"
                                        title="Every check one after another in one browser -- what one leaves, the next finds -- as one demo video"
                                        data-test="run-one-film"
                                        @select="runAs('demo', null, true)"
                                        ><Film /> Demo video, all in one
                                        film</DropdownMenuItem
                                    >
                                    <DropdownMenuItem @select="tab = 'settings'"
                                        ><Settings2 />
                                        Settings</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        data-test="suite-versions"
                                        @select="versionsOpen = true"
                                        ><History /> Earlier
                                        versions</DropdownMenuItem
                                    >
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        data-test="suite-delete"
                                        @select="deleting = true"
                                        ><Trash2 /> Delete the
                                        suite</DropdownMenuItem
                                    >
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <!-- At a glance -->
                    <div
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3"
                        data-test="suite-glance"
                    >
                        <div class="bg-muted/40 rounded-lg px-3 py-2">
                            <span
                                class="text-muted-foreground block text-[11px]"
                                >Health</span
                            >
                            <div class="flex items-end justify-between gap-2">
                                <span
                                    class="text-base font-semibold tabular-nums sm:text-lg"
                                    >{{
                                        health === null ? '—' : `${health}%`
                                    }}</span
                                >
                                <RunBars :states="recent" :places="10" tall />
                            </div>
                        </div>
                        <div class="bg-muted/40 rounded-lg px-3 py-2">
                            <span
                                class="text-muted-foreground block text-[11px]"
                                >Last run</span
                            >
                            <span
                                class="block text-base font-semibold sm:text-lg"
                                >{{
                                    lastRun ? ago(lastRun.created_at) : '—'
                                }}</span
                            >
                            <span
                                v-if="lastRun?.took"
                                class="text-muted-foreground text-xs tabular-nums"
                                >took {{ took(lastRun.took) }}</span
                            >
                        </div>
                        <div
                            class="bg-muted/40 col-span-2 rounded-lg px-3 py-2 sm:col-span-1"
                        >
                            <span
                                class="text-muted-foreground block text-[11px]"
                                >Next run</span
                            >
                            <span
                                class="block text-base font-semibold sm:text-lg"
                                >{{ nextRun ?? 'When asked' }}</span
                            >
                            <button
                                v-if="canChange"
                                type="button"
                                class="text-muted-foreground hover:text-foreground text-xs underline-offset-2 hover:underline"
                                @click="tab = 'settings'"
                            >
                                {{
                                    suite.settings.every === 'off'
                                        ? 'Set a timetable'
                                        : 'Change'
                                }}
                            </button>
                        </div>
                    </div>
                </header>

                <div class="border-b px-3 py-2 sm:px-4">
                    <SegmentTabs
                        v-model="tab"
                        :options="TABS"
                        label="Parts of the suite"
                    />
                </div>

                <!-- Its checks -->
                <div v-show="tab === 'checks'" class="space-y-3 p-3 sm:p-4">
                    <EmptyState
                        v-if="!suite.checks.length"
                        :icon="MonitorCheck"
                        title="No checks yet"
                        data-test="checks-empty"
                    >
                        <p class="text-muted-foreground max-w-sm text-sm">
                            Open the page and click through it the way a
                            customer would: each click becomes a step.
                        </p>
                        <div
                            v-if="canChange"
                            class="mt-2 flex flex-wrap justify-center gap-2"
                        >
                            <Button
                                data-test="check-build-new"
                                @click="build(null)"
                            >
                                <MousePointerClick /> Build by clicking
                            </Button>
                            <Button
                                variant="outline"
                                data-test="check-add"
                                @click="addCheck"
                            >
                                <PencilLine /> Write the steps
                            </Button>
                        </div>
                    </EmptyState>

                    <template v-else>
                        <ul class="divide-y rounded-lg border">
                            <li
                                v-for="(check, at) in suite.checks"
                                :key="check.id"
                                data-test="check"
                            >
                                <div class="flex items-center gap-2 px-3 py-2">
                                    <span
                                        class="shrink-0"
                                        :title="
                                            suite.latest[check.id]
                                                ? suite.latest[check.id].ok
                                                    ? 'Passed last time'
                                                    : 'Failed last time'
                                                : 'Not run yet'
                                        "
                                    >
                                        <CircleCheck
                                            v-if="suite.latest[check.id]?.ok"
                                            class="size-4 text-emerald-600"
                                        />
                                        <CircleX
                                            v-else-if="suite.latest[check.id]"
                                            class="size-4 text-red-600"
                                        />
                                        <CircleDashed
                                            v-else
                                            class="text-muted-foreground size-4"
                                        />
                                    </span>
                                    <button
                                        type="button"
                                        class="min-w-0 flex-1 text-left"
                                        :aria-expanded="
                                            openChecks.has(check.id)
                                        "
                                        data-test="check-toggle"
                                        @click="toggleCheck(check.id)"
                                    >
                                        <span
                                            class="block truncate text-sm font-medium"
                                            >{{
                                                check.title || 'Untitled'
                                            }}</span
                                        >
                                        <span
                                            class="text-muted-foreground flex items-center gap-1.5 text-xs"
                                        >
                                            <component
                                                :is="
                                                    SIZE_ICONS[
                                                        check.size ?? 'desktop'
                                                    ]
                                                "
                                                class="size-3"
                                            />
                                            {{ check.steps.length }}
                                            {{
                                                check.steps.length === 1
                                                    ? 'step'
                                                    : 'steps'
                                            }}
                                            <template
                                                v-if="suite.latest[check.id]"
                                                >·
                                                {{
                                                    took(
                                                        suite.latest[check.id]
                                                            .took,
                                                    )
                                                }}</template
                                            >
                                        </span>
                                    </button>
                                    <Button
                                        v-if="canChange"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="hidden sm:inline-flex"
                                        aria-label="Add steps by clicking on the page"
                                        title="Add steps by clicking on the page"
                                        data-test="check-build"
                                        @click="build(check)"
                                    >
                                        <MousePointerClick />
                                    </Button>
                                    <Button
                                        v-if="canChange"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Test only this check"
                                        title="Test only this check"
                                        :disabled="!!starting || going"
                                        data-test="check-run-one"
                                        @click="runAs('test', check.id)"
                                    >
                                        <Play />
                                    </Button>
                                    <!-- While it is being written: tried, and kept nowhere -->
                                    <Button
                                        v-if="canChange"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Try this check, keeping nothing"
                                        title="Try this check, keeping nothing: not in the runs or health, no tickets, nothing in the Drive"
                                        :disabled="!!starting || going"
                                        data-test="check-try"
                                        @click="
                                            runAs('test', check.id, false, true)
                                        "
                                    >
                                        <FlaskConical />
                                    </Button>
                                    <DropdownMenu v-if="canChange">
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label="More for this check"
                                                data-test="check-menu"
                                            >
                                                <EllipsisVertical />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                class="sm:hidden"
                                                @select="build(check)"
                                                ><MousePointerClick /> Add steps
                                                by clicking</DropdownMenuItem
                                            >
                                            <DropdownMenuItem
                                                @select="
                                                    runAs('demo', check.id)
                                                "
                                                ><Clapperboard /> Demo video of
                                                this one</DropdownMenuItem
                                            >
                                            <DropdownMenuItem
                                                @select="copyCheck(at)"
                                                ><Copy />
                                                Duplicate</DropdownMenuItem
                                            >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                data-test="check-remove"
                                                @select="removeCheck(at)"
                                                ><Trash2 />
                                                Remove</DropdownMenuItem
                                            >
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                    <ChevronDown
                                        class="text-muted-foreground hidden size-4 shrink-0 cursor-pointer transition-transform sm:block"
                                        :class="{
                                            'rotate-180': openChecks.has(
                                                check.id,
                                            ),
                                        }"
                                        @click="toggleCheck(check.id)"
                                    />
                                </div>

                                <div
                                    v-if="openChecks.has(check.id)"
                                    class="space-y-2 border-t px-3 pt-3 pb-3"
                                >
                                    <div class="flex flex-wrap gap-2">
                                        <Input
                                            v-model="check.title"
                                            :readonly="!canChange"
                                            aria-label="The check's name"
                                            class="h-8 min-w-0 flex-1 basis-48"
                                            data-test="check-title"
                                            @update:model-value="changed"
                                        />
                                        <Select
                                            :model-value="
                                                check.size ?? 'desktop'
                                            "
                                            :disabled="!canChange"
                                            @update:model-value="
                                                check.size = $event as Size;
                                                changed();
                                            "
                                        >
                                            <SelectTrigger
                                                class="w-32 shrink-0"
                                                size="sm"
                                                aria-label="The screen it is done on"
                                                data-test="check-size"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="(
                                                        label, value
                                                    ) in SCREEN_SIZES"
                                                    :key="value"
                                                    :value="value"
                                                    >{{ label }}</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                        <!-- Whom it starts signed in as, in a suite that signs in -->
                                        <p
                                            v-if="signsInAs(check)"
                                            class="text-muted-foreground flex h-8 items-center text-xs"
                                            data-test="check-signs-in"
                                        >
                                            Signs in as
                                            {{ signsInAs(check) }}
                                        </p>
                                        <Select
                                            v-else-if="suite.sign_ins.length"
                                            :model-value="startsAs(check)"
                                            :disabled="!canChange"
                                            @update:model-value="
                                                startAs(check, String($event))
                                            "
                                        >
                                            <SelectTrigger
                                                class="w-44 shrink-0"
                                                size="sm"
                                                aria-label="Whom it starts signed in as"
                                                data-test="check-starts-as"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="one in suite.sign_ins"
                                                    :key="one.name"
                                                    :value="one.name"
                                                    >Signed in as
                                                    {{ one.name }}</SelectItem
                                                >
                                                <SelectItem :value="SIGNED_OUT"
                                                    >Signed out</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <StepsEditor
                                        :steps="check.steps"
                                        :readonly="!canChange"
                                        :look="(upto) => lookAt(check, upto)"
                                        :step-limit="
                                            Math.min(
                                                suite.limits.step ??
                                                    limits.default.step,
                                                limits.most.step,
                                            )
                                        "
                                        :step-most="limits.most.step"
                                        @change="changed"
                                    />
                                </div>
                            </li>
                        </ul>
                        <div
                            v-if="canChange"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                data-test="check-build-new"
                                @click="build(null)"
                            >
                                <MousePointerClick /> Build a check by clicking
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                data-test="check-add"
                                @click="addCheck"
                            >
                                <PencilLine /> Write one
                            </Button>
                        </div>
                    </template>
                </div>

                <!-- Its runs: the one shown, and the ones before -->
                <div
                    v-show="tab === 'runs'"
                    class="grid gap-4 p-3 sm:p-4 xl:grid-cols-[17rem_minmax(0,1fr)]"
                >
                    <!-- A try is shown, though never listed -->
                    <EmptyState
                        v-if="!suite.runs.length && !run"
                        :icon="History"
                        title="No runs yet"
                        class="xl:col-span-2"
                    >
                        <p class="text-muted-foreground text-sm">
                            Test it, or make a demo video, and how it went is
                            kept here.
                        </p>
                    </EmptyState>
                    <template v-else>
                        <ul
                            v-if="suite.runs.length"
                            class="divide-y self-start overflow-hidden rounded-lg border"
                            data-test="run-history"
                        >
                            <li v-for="past in suite.runs" :key="past.ref_id">
                                <button
                                    type="button"
                                    class="hover:bg-accent/50 flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm"
                                    :class="{
                                        'bg-accent':
                                            past.ref_id === run?.ref_id,
                                    }"
                                    data-test="run-row"
                                    :data-run="past.ref_id"
                                    :data-state="past.state"
                                    @click="showRun(past.url)"
                                >
                                    <CircleCheck
                                        v-if="past.state === 'passed'"
                                        class="size-4 shrink-0 text-emerald-600"
                                    />
                                    <CircleX
                                        v-else-if="past.state === 'failed'"
                                        class="size-4 shrink-0 text-red-600"
                                    />
                                    <CircleStop
                                        v-else-if="past.state === 'stopped'"
                                        class="text-muted-foreground size-4 shrink-0"
                                    />
                                    <Loader2
                                        v-else
                                        class="size-4 shrink-0 animate-spin text-sky-600"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate">{{
                                            when.format(
                                                new Date(past.created_at),
                                            )
                                        }}</span>
                                        <span
                                            class="text-muted-foreground block truncate text-xs"
                                            >{{ SPEEDS[past.speed].label
                                            }}{{ past.record ? ' · filmed' : ''
                                            }}{{
                                                past.started_by === 'timetable'
                                                    ? ' · by itself'
                                                    : past.started_by ===
                                                        'workflow'
                                                      ? ' · from a workflow'
                                                      : ''
                                            }}</span
                                        >
                                    </span>
                                    <span
                                        v-if="past.total"
                                        class="text-muted-foreground shrink-0 text-right text-xs tabular-nums"
                                    >
                                        {{ past.passed }}/{{ past.total }}
                                        <span v-if="past.took" class="block">{{
                                            took(past.took)
                                        }}</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <div
                            v-if="run"
                            class="min-w-0"
                            :class="{ 'xl:col-span-2': !suite.runs.length }"
                        >
                            <p
                                v-if="run.trial"
                                class="text-muted-foreground mb-2 flex items-center gap-1.5 text-xs"
                                data-test="run-trial"
                            >
                                <FlaskConical class="size-3.5 shrink-0" />
                                Only a try: kept nowhere, and gone an hour after
                                it ends.
                            </p>
                            <CheckRunView
                                :run="run"
                                :total="runTotal"
                                @stop="stopRun"
                            />
                        </div>
                    </template>
                </div>

                <!-- How it goes on by itself -->
                <div v-show="tab === 'settings'" class="p-3 sm:p-4">
                    <SuiteSettings
                        :suite="suite"
                        :can-change="canChange"
                        :limits="limits"
                    />
                </div>
            </section>
        </div>

        <BuildDialog
            v-if="suite"
            v-model:open="buildOpen"
            :title="building?.title ?? ''"
            :steps="building?.steps ?? []"
            :size="building?.size ?? 'desktop'"
            :open-page="openPage"
            :step-page="stepPage"
            :close-page="closePage"
            @done="built"
        />
        <SuiteVersions
            v-if="suite"
            v-model:open="versionsOpen"
            :suite="suite"
            :can-change="canChange"
            @restored="restored"
        />
        <RunOptionsDialog
            v-if="suite"
            v-model:open="optionsOpen"
            :checks="suite.checks"
            :speeds="speeds"
            :folder="suite.title"
            @start="start('custom', $event)"
        />
        <NameDialog
            v-model:open="naming"
            title="A new suite"
            submit-label="Make it"
            @submit="make"
        />
        <DeleteDialog
            v-if="suite"
            v-model:open="deleting"
            :name="suite.title"
            description="Its checks and runs go with it. Pictures and films already in the Drive stay there."
            @confirm="remove"
        />
    </div>
</template>
