<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    CircleSlash,
    CircleX,
    Copy,
    Loader2,
    Play,
    Square,
} from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    index as testsRoute,
    shot as testShot,
    show as testRun,
    stop as stopTests,
    store as startTests,
} from '@/actions/App/Http/Controllers/Browser/TestRunController';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import PictureDialog from '@/features/chat/components/PictureDialog.vue';
import { ask } from './http';
import type { Listener, Run, RunSummary, Suite, SuiteState } from './runs';

// The app's own browser suites (tests/e2e), run from here and followed as
// they go. The runner is on this machine (node tests/e2e/run.mjs --listen):
// this page only asks it for a run and reads what it writes down.

const props = defineProps<{
    initial: { listener: Listener | null; runs: RunSummary[] };
}>();

const listener = ref(props.initial.listener);
const runs = ref(props.initial.runs);
const shownId = ref<string | null>(runs.value[0]?.id ?? null);
const shown = ref<Run | null>(null);

async function refresh() {
    const state = await ask<typeof props.initial>(testsRoute()).catch(
        () => null,
    );

    if (state) {
        listener.value = state.listener;
        runs.value = state.runs;
    }
}

async function load() {
    if (shownId.value) {
        shown.value = await ask<Run>(testRun(shownId.value)).catch(
            () => shown.value,
        );
    }
}

// Asked again and again only while there is something to follow
useIntervalFn(refresh, 2000);
useIntervalFn(() => {
    if (shown.value && !shown.value.ended) {
        void load();
    }
}, 1000);
watch(shownId, load, { immediate: true });
// A run asked for here shows once the runner has begun writing it down
watch(runs, () => {
    if (shownId.value && !shown.value) {
        void load();
    }
});

// ------------------------------------------------------------ Asking for one
const which = ref<'all' | 'some'>('all');
const picked = ref<string[]>([]);
const filter = ref('');
const lanes = ref('3');
const watching = ref(false);
const asking = ref(false);

const everyday = computed(() =>
    (listener.value?.suites ?? []).filter(
        (name) => !listener.value!.alone.includes(name),
    ),
);
const listed = computed(() =>
    (listener.value?.suites ?? []).filter((name) =>
        name.includes(filter.value.trim().toLowerCase()),
    ),
);
const togglePick = (name: string) =>
    (picked.value = picked.value.includes(name)
        ? picked.value.filter((one) => one !== name)
        : [...picked.value, name]);

async function start() {
    asking.value = true;

    try {
        const { id } = await ask<{ id: string }>(startTests(), {
            suites: which.value === 'all' ? [] : picked.value,
            lanes: Number(lanes.value),
            watch: watching.value,
        });
        shown.value = null;
        shownId.value = id;
        await refresh();
    } catch (problem) {
        toast.error((problem as Error).message);
    } finally {
        asking.value = false;
    }
}

async function stop() {
    if (shown.value) {
        await ask(stopTests(shown.value.id)).catch(() => null);
        toast('Stopping the run…');
    }
}

const command = 'node tests/e2e/run.mjs --listen';
const copy = () =>
    navigator.clipboard
        .writeText(command)
        .then(() => toast('Copied'))
        .catch(() => {});

// ------------------------------------------------------------ The run shown
const ORDER: SuiteState[] = [
    'running',
    'failed',
    'waiting',
    'stopped',
    'passed',
];
const suites = computed(() =>
    [...(shown.value?.suites ?? [])].sort(
        (a, b) => ORDER.indexOf(a.state) - ORDER.indexOf(b.state),
    ),
);
const count = (state: SuiteState) =>
    shown.value?.suites.filter((suite) => suite.state === state).length ?? 0;
const ended = computed(
    () =>
        (shown.value?.suites.length ?? 0) - count('waiting') - count('running'),
);
const open = ref<string | null>(null);
const ICONS = {
    waiting: CircleDashed,
    running: Loader2,
    passed: CircleCheck,
    failed: CircleX,
    stopped: CircleSlash,
};
const TONES = {
    waiting: 'text-muted-foreground',
    running: 'animate-spin text-sky-600',
    passed: 'text-emerald-600',
    failed: 'text-destructive',
    stopped: 'text-muted-foreground',
};

// Running for a while now, ticking on its own between reads
const now = ref(Date.now());
useIntervalFn(() => (now.value = Date.now()), 500);
const took = (suite: Suite) =>
    suite.took !== null
        ? `${(suite.took / 1000).toFixed(1)}s`
        : suite.began
          ? `${((now.value - suite.began) / 1000).toFixed(0)}s`
          : suite.expected
            ? `~${suite.expected.toFixed(0)}s`
            : '';
const when = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

// ------------------------------------------------------------ Pictures
const picture = ref<{ suite: string; at: number } | null>(null);
const pictureFile = computed(() => {
    const suite = shown.value?.suites.find(
        (one) => one.name === picture.value?.suite,
    );
    const file = suite?.shots[picture.value?.at ?? 0];

    return suite && file && shown.value
        ? {
              name: file,
              url: testShot([shown.value.id, suite.name, file]).url,
          }
        : null;
});
const pictureCount = computed(
    () =>
        shown.value?.suites.find((one) => one.name === picture.value?.suite)
            ?.shots.length ?? 0,
);
</script>

<template>
    <div class="space-y-4">
        <!-- The runner -->
        <div
            v-if="!listener"
            class="space-y-2 rounded-xl border border-dashed p-4 text-sm"
            data-test="tests-no-runner"
        >
            <p class="font-medium">The test runner isn't waiting.</p>
            <p class="text-muted-foreground">
                It runs on this machine, beside the browser it drives. Start it
                in a terminal, and this page can ask it for runs:
            </p>
            <div class="flex items-center gap-2">
                <code
                    class="bg-muted min-w-0 flex-1 truncate rounded-md px-2 py-1.5 font-mono text-xs"
                    >{{ command }}</code
                >
                <Button variant="outline" size="sm" @click="copy">
                    <Copy /> Copy
                </Button>
            </div>
        </div>

        <div
            v-else
            class="space-y-3 rounded-xl border p-3 sm:p-4"
            data-test="tests-ask"
        >
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="size-2 rounded-full bg-emerald-500" />
                <span class="min-w-0 flex-1"
                    >The runner is waiting ·
                    {{ listener.suites.length }} suites</span
                >
                <SegmentTabs
                    v-model="which"
                    :options="[
                        { value: 'all', label: 'All', test: 'tests-all' },
                        { value: 'some', label: 'Choose', test: 'tests-some' },
                    ]"
                    label="Which suites"
                />
            </div>

            <div v-if="which === 'some'" class="space-y-2">
                <Input
                    v-model="filter"
                    placeholder="Find a suite"
                    aria-label="Find a suite"
                    class="h-8"
                    data-test="tests-filter"
                />
                <ul
                    class="grid max-h-56 gap-x-4 overflow-y-auto rounded-lg border p-2 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <li v-for="name in listed" :key="name">
                        <label
                            class="hover:bg-accent/50 flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm"
                        >
                            <Checkbox
                                :model-value="picked.includes(name)"
                                :data-test="`tests-pick-${name}`"
                                @update:model-value="togglePick(name)"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                name
                            }}</span>
                            <span
                                v-if="listener.timings[name]"
                                class="text-muted-foreground text-xs tabular-nums"
                                >{{ listener.timings[name].toFixed(0) }}s</span
                            >
                        </label>
                    </li>
                </ul>
            </div>
            <p v-else class="text-muted-foreground text-xs">
                Every suite but {{ listener.alone.join(' and ') }}, which run
                only when chosen: {{ everyday.length }} in all.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm">
                    <Switch v-model="watching" data-test="tests-watch" />
                    Watch it run
                </label>
                <Select v-if="!watching" v-model="lanes">
                    <SelectTrigger
                        class="w-32"
                        size="sm"
                        aria-label="Lanes"
                        data-test="tests-lanes"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="1">1 lane</SelectItem>
                        <SelectItem value="2">2 lanes</SelectItem>
                        <SelectItem value="3">3 lanes</SelectItem>
                    </SelectContent>
                </Select>
                <span v-else class="text-muted-foreground text-xs"
                    >One at a time, full screen and slowed, on this machine's
                    screen</span
                >
                <Button
                    class="ml-auto"
                    :disabled="
                        asking ||
                        !!listener.busy ||
                        (which === 'some' && !picked.length)
                    "
                    data-test="tests-run"
                    @click="start"
                >
                    <Play />
                    {{ listener.busy ? 'A run is going' : 'Run' }}
                </Button>
            </div>
        </div>

        <!-- The run shown -->
        <div
            v-if="shown"
            class="space-y-3 rounded-xl border p-3 sm:p-4"
            data-test="tests-run-shown"
        >
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium">{{ when.format(shown.began) }}</span>
                <span class="text-muted-foreground"
                    >{{
                        shown.from === 'app' ? 'from here' : 'from a terminal'
                    }}
                    · {{ shown.lanes }}
                    {{ shown.lanes === 1 ? 'lane' : 'lanes' }}</span
                >
                <span class="ml-auto tabular-nums" data-test="tests-progress"
                    >{{ ended }} of {{ shown.suites.length }}
                    <template v-if="count('failed')">
                        ·
                        <span class="text-destructive"
                            >{{ count('failed') }} failed</span
                        ></template
                    ></span
                >
                <Button
                    v-if="!shown.ended && shown.from === 'app'"
                    variant="outline"
                    size="sm"
                    data-test="tests-stop"
                    @click="stop"
                >
                    <Square /> Stop
                </Button>
            </div>
            <div class="bg-muted h-1.5 overflow-hidden rounded-full">
                <div
                    class="h-full transition-[width]"
                    :class="count('failed') ? 'bg-red-500' : 'bg-emerald-500'"
                    :style="{
                        width: `${(ended / Math.max(1, shown.suites.length)) * 100}%`,
                    }"
                />
            </div>

            <ul class="divide-y overflow-hidden rounded-lg border">
                <li
                    v-for="suite in suites"
                    :key="suite.name"
                    data-test="tests-suite"
                    :data-state="suite.state"
                >
                    <button
                        type="button"
                        class="hover:bg-accent/50 flex w-full items-center gap-2 px-3 py-2 text-left text-sm"
                        @click="open = open === suite.name ? null : suite.name"
                    >
                        <component
                            :is="ICONS[suite.state]"
                            class="size-4 shrink-0"
                            :class="TONES[suite.state]"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            suite.name
                        }}</span>
                        <span
                            v-if="
                                suite.state === 'running' && suite.lines.length
                            "
                            class="text-muted-foreground hidden max-w-60 truncate text-xs sm:inline"
                            >{{
                                suite.lines.at(-1)!.replace(/^PASS {2}/, '')
                            }}</span
                        >
                        <span
                            class="text-muted-foreground text-xs tabular-nums"
                            >{{ took(suite) }}</span
                        >
                    </button>
                    <div v-if="open === suite.name" class="space-y-2 px-3 pb-3">
                        <p
                            v-if="suite.problems"
                            class="text-destructive text-xs"
                        >
                            {{ suite.problems }}
                        </p>
                        <pre
                            class="bg-muted/50 max-h-72 overflow-auto rounded-md p-2 font-mono text-[11px] leading-relaxed whitespace-pre-wrap"
                            data-test="tests-lines"
                        ><span
                            v-for="(line, at) in suite.lines"
                            :key="at"
                            :class="{ 'text-destructive': line.startsWith('FAIL') }"
                        >{{ line }}
</span></pre>
                        <div
                            v-if="suite.shots.length"
                            class="flex flex-wrap gap-2"
                        >
                            <button
                                v-for="(file, at) in suite.shots"
                                :key="file"
                                type="button"
                                class="overflow-hidden rounded border"
                                :aria-label="file"
                                @click="picture = { suite: suite.name, at }"
                            >
                                <img
                                    :src="
                                        testShot([shown.id, suite.name, file])
                                            .url
                                    "
                                    alt=""
                                    loading="lazy"
                                    class="h-14 w-24 object-cover object-top"
                                />
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Runs before -->
        <div v-if="runs.length" class="space-y-2">
            <h3 class="text-muted-foreground text-xs font-medium">Runs kept</h3>
            <ul
                class="divide-y overflow-hidden rounded-xl border"
                data-test="tests-history"
            >
                <li v-for="run in runs" :key="run.id">
                    <button
                        type="button"
                        class="hover:bg-accent/50 flex w-full items-center gap-2 px-3 py-2 text-left text-sm"
                        :class="{ 'bg-accent': run.id === shownId }"
                        @click="shownId = run.id"
                    >
                        <Loader2
                            v-if="!run.ended"
                            class="size-4 animate-spin text-sky-600"
                        />
                        <CircleX
                            v-else-if="run.failed"
                            class="text-destructive size-4"
                        />
                        <CircleSlash
                            v-else-if="run.stopped"
                            class="text-muted-foreground size-4"
                        />
                        <CircleCheck v-else class="size-4 text-emerald-600" />
                        <span class="min-w-0 flex-1 truncate">{{
                            when.format(run.began)
                        }}</span>
                        <span class="text-muted-foreground text-xs tabular-nums"
                            >{{ run.passed }}/{{ run.suites }} passed</span
                        >
                    </button>
                </li>
            </ul>
        </div>

        <PictureDialog
            :open="picture !== null"
            :file="pictureFile"
            :place="
                pictureCount > 1 && picture
                    ? `${picture.at + 1} of ${pictureCount}`
                    : undefined
            "
            @update:open="!$event && (picture = null)"
            @step="
                picture &&
                (picture = {
                    suite: picture.suite,
                    at: (picture.at + $event + pictureCount) % pictureCount,
                })
            "
        />
    </div>
</template>
