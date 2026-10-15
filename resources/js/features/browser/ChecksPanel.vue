<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleCheck,
    CircleDashed,
    CircleX,
    Clapperboard,
    Copy,
    EllipsisVertical,
    Film,
    History,
    ListChecks,
    Loader2,
    Monitor,
    MonitorCheck,
    MousePointerClick,
    PencilLine,
    Play,
    Plus,
    Search,
    Settings2,
    SlidersHorizontal,
    Smartphone,
    Tablet,
    Trash2,
} from '@lucide/vue';
import { useDebounceFn, useIntervalFn } from '@vueuse/core';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
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
import { SIZES as SCREEN_SIZES, SPEEDS } from './steps';
import SuiteSettings from './SuiteSettings.vue';
import { ago, took } from './time';
import type {
    Check,
    CheckRun,
    LivePage,
    LiveStep,
    Look,
    Pace,
    RunChoice,
    RunState,
    Size,
    Speed,
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

async function choose(summary: SuiteSummary) {
    await saveNow();
    suite.value = await ask<Suite>({ url: summary.url, method: 'get' });
    suite.value.latest ??= {};
    openChecks.value = new Set();
    run.value = null;
    tab.value = 'checks';

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

// ------------------------------------------------------------ Runs
const run = ref<CheckRun | null>(null);
const starting = ref<'test' | 'demo' | 'custom' | null>(null);
const going = computed(
    () => run.value?.state === 'waiting' || run.value?.state === 'running',
);
const optionsOpen = ref(false);

async function showRun(url: string) {
    run.value = await ask<CheckRun>({ url, method: 'get' });
}

// Most runs are one of two: a quick test, or a film to show someone --
// one for each check, or all of them one after another as one film
function runAs(
    kind: 'test' | 'demo',
    only: string | null = null,
    oneFilm = false,
) {
    void start(kind, {
        speed: kind === 'test' ? 'quick' : 'steady',
        pace: props.speeds[kind === 'test' ? 'quick' : 'steady'],
        captions: kind === 'demo',
        record: kind === 'demo',
        keep: kind === 'demo',
        checks: only ? [only] : [],
        one_film: oneFilm,
    });
}

async function start(kind: 'test' | 'demo' | 'custom', choice: RunChoice) {
    if (!suite.value) {
        return;
    }

    starting.value = kind;
    await saveNow();

    try {
        run.value = await ask<CheckRun>(
            { url: suite.value.urls.runs, method: 'post' },
            choice,
        );
        suite.value.runs.unshift({
            ref_id: run.value.ref_id,
            state: run.value.state,
            speed: choice.speed,
            record: choice.record,
            started_by: 'person',
            created_at: run.value.created_at,
            url: run.value.url,
        });
        tab.value = 'runs';
    } catch (problem) {
        toast.error((problem as Error).message);
    } finally {
        starting.value = null;
    }
}

// Followed until it ends
useIntervalFn(async () => {
    if (!run.value || !going.value) {
        return;
    }

    await showRun(run.value.url);
    const now = run.value;
    const listed = suite.value?.runs.find((one) => one.ref_id === now.ref_id);

    if (listed) {
        listed.state = now.state;
    }

    if (!going.value && suite.value) {
        // How it went, shown where its checks are listed too
        if (listed) {
            listed.passed = now.results.filter((check) => check.ok).length;
            listed.total = now.results.length;
            listed.took =
                now.started_at && now.ended_at
                    ? new Date(now.ended_at).getTime() -
                      new Date(now.started_at).getTime()
                    : null;
        }

        for (const check of now.results) {
            suite.value.latest[check.id] = {
                ok: check.ok,
                took: check.took,
                at: now.created_at,
            };
        }

        const summary = summaries.value.find(
            (one) => one.ref_id === suite.value?.ref_id,
        );

        if (summary) {
            summary.last = now.state;
            summary.last_at = now.created_at;
            summary.recent = [...(summary.recent ?? []), now.state].slice(-10);
        }
    }
}, 1500);

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
            <span class="text-muted-foreground">Kept in</span>
            <Select
                :model-value="spaces.find((one) => one.current)?.url"
                @update:model-value="router.visit($event as string)"
            >
                <SelectTrigger
                    class="w-56"
                    size="sm"
                    aria-label="Kept in"
                    data-test="checks-space"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="one in spaces"
                        :key="one.url"
                        :value="one.url"
                        >{{ one.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
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
                                    </div>
                                    <StepsEditor
                                        :steps="check.steps"
                                        :readonly="!canChange"
                                        :look="(upto) => lookAt(check, upto)"
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
                    <EmptyState
                        v-if="!suite.runs.length"
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
                        <CheckRunView
                            v-if="run"
                            :run="run"
                            :total="runTotal"
                            class="min-w-0"
                        />
                    </template>
                </div>

                <!-- How it goes on by itself -->
                <div v-show="tab === 'settings'" class="p-3 sm:p-4">
                    <SuiteSettings :suite="suite" :can-change="canChange" />
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
