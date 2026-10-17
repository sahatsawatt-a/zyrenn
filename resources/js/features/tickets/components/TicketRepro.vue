<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleCheck,
    CircleX,
    Clapperboard,
    ExternalLink,
    LoaderCircle,
    Play,
    Plus,
    X,
} from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { echoIsConfigured } from '@laravel/echo-vue';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import Tip from '@/components/common/Tip.vue';
import { Label } from '@/components/ui/label';
import { usePresence } from '@/composables/usePresence';
import { useMediaViewer } from '@/composables/useMediaViewer';
import { ask } from '@/features/browser/http';
import { saysRepro } from '@/features/tickets/tickets';
import type {
    Ticket,
    TicketDraft,
    TicketReplayRun,
    TicketSuite,
} from '@/features/tickets/tickets';
import { formatRelativeTime } from '@/lib/utils';
import { replay } from '@/routes/projects/tickets';
import TicketPick from './TicketPick.vue';

// How to make a bug happen again -- folded away until somebody says: the
// steps, what should happen and what does, and one of the project's browser
// checks that does them. Run from here, the check says whether the bug still
// happens: a check passes once it is fixed.
const repro = defineModel<TicketDraft['repro']>('repro', { required: true });
const check = defineModel<TicketDraft['check']>('check', { required: true });

const props = defineProps<{
    // The ticket as filed; none while it is new
    ticket: Ticket | null;
    suites: TicketSuite[];
    canChange: boolean;
    errors: Record<string, string>;
}>();

const unfolded = ref(false);

// Open from the start when there is something in it
watch(
    () => props.ticket?.ref_id,
    () =>
        (unfolded.value =
            saysRepro(props.ticket?.repro) || !!props.ticket?.check),
    { immediate: true },
);

const said = computed(() => saysRepro(repro.value) || !!check.value);

// ------------------------------------------------------------------- Steps
const stepList = ref<HTMLOListElement | null>(null);

const addStep = async (after = repro.value.steps.length - 1) => {
    repro.value.steps.splice(after + 1, 0, '');
    await nextTick();
    stepList.value
        ?.querySelectorAll<HTMLInputElement>('input')
        [after + 1]?.focus();
};

const unfold = () => {
    unfolded.value = true;

    if (!repro.value.steps.length && props.canChange) {
        void addStep();
    }
};

// ------------------------------------------------------------------- Check
// "No check" is a choice like the rest, under a name no check would have
const NONE = '__none__';

const checkOptions = computed(() => [
    { value: NONE, label: 'No check' },
    ...props.suites.flatMap((suite) =>
        suite.checks.map((one) => ({
            value: `${suite.ref_id}/${one.id}`,
            label: `${suite.title} · ${one.title}`,
        })),
    ),
]);

const chosen = computed(() =>
    check.value ? `${check.value.suite}/${check.value.check}` : NONE,
);

const choose = (value: string) => {
    const [suite, one] = value.split('/');
    check.value = value === NONE ? null : { suite, check: one };
};

// The check as filed: only that one runs, so a change is saved first
const filed = computed(() =>
    props.ticket?.check && !props.ticket.check.gone ? props.ticket.check : null,
);

const unsaved = computed(
    () =>
        !!check.value &&
        (filed.value?.suite !== check.value.suite ||
            filed.value?.check !== check.value.check),
);

// --------------------------------------------------------------- Running it
const page = usePage();
const starting = ref(false);
const runError = ref('');

// The run under way, followed here until it ends
const run = computed<TicketReplayRun | null>(() => filed.value?.run ?? null);
const going = computed(
    () => !!run.value && ['waiting', 'running'].includes(run.value.state),
);

const runIt = () => {
    const ticket = props.ticket;

    if (!ticket) {
        return;
    }

    runError.value = '';
    router.post(
        replay([page.props.project!.ref_id, ticket.ref_id]).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['tickets'],
            onStart: () => (starting.value = true),
            onFinish: () => (starting.value = false),
            onError: (problems) =>
                (runError.value = problems.check ?? Object.values(problems)[0]),
        },
    );
};

// Read again once it has ended: how it went comes with the ticket
const settle = () => router.reload({ only: ['tickets'] });

usePresence(
    () => (going.value && filed.value ? `checks.${filed.value.suite}` : ''),
    {
        'run.changed': (news: { run: string; state: string }) => {
            if (
                news.run === run.value?.ref_id &&
                !['waiting', 'running'].includes(news.state)
            ) {
                settle();
            }
        },
    },
);

// Asked now and then all the same, in case a message went astray -- and
// every moment where there is no socket to hear them on
useIntervalFn(
    async () => {
        if (!going.value || !run.value) {
            return;
        }

        const now = await ask<{ state: string }>({
            url: run.value.url,
            method: 'get',
        }).catch(() => null);

        if (now && !['waiting', 'running'].includes(now.state)) {
            settle();
        }
    },
    () => (echoIsConfigured() ? 15_000 : 2000),
);

const viewer = useMediaViewer();

const watchFilm = () => {
    const video = run.value?.video;

    if (video) {
        viewer.open([{ type: 'video', src: video.url, title: video.name }]);
    }
};
</script>

<template>
    <div class="grid gap-2" data-test="ticket-repro">
        <button
            type="button"
            class="text-muted-foreground hover:text-foreground flex w-fit items-center gap-1.5 text-sm font-medium"
            :aria-expanded="unfolded"
            data-test="ticket-repro-toggle"
            @click="unfolded ? (unfolded = false) : unfold()"
        >
            <ChevronDown
                class="size-4 transition-transform"
                :class="{ '-rotate-90': !unfolded }"
            />
            How to make it happen again
            <span
                v-if="!unfolded && said"
                class="bg-muted rounded px-1.5 text-xs"
                >{{ repro.steps.filter((step) => step.trim()).length }} steps{{
                    check ? ' · a check' : ''
                }}</span
            >
            <span v-else-if="!unfolded" class="text-xs font-normal"
                >(optional)</span
            >
        </button>

        <div
            v-if="unfolded"
            class="bg-muted/40 space-y-4 rounded-lg border p-3"
        >
            <!-- The steps, numbered as they are said -->
            <div class="grid gap-2">
                <Label>Steps</Label>
                <ol ref="stepList" class="space-y-1.5">
                    <li
                        v-for="(_, at) in repro.steps"
                        :key="at"
                        class="flex items-center gap-2"
                    >
                        <span
                            class="text-muted-foreground w-5 shrink-0 text-right text-xs tabular-nums"
                            >{{ at + 1 }}.</span
                        >
                        <input
                            v-model="repro.steps[at]"
                            maxlength="500"
                            class="bg-background dark:bg-input/30 border-input h-8 min-w-0 flex-1 rounded-md border px-2.5 text-sm"
                            :placeholder="
                                at === 0 ? 'e.g. Open a board' : 'And then…'
                            "
                            :aria-label="`Step ${at + 1}`"
                            data-test="ticket-repro-step"
                            @keydown.enter.prevent="addStep(at)"
                        />
                        <Tip v-if="canChange" :text="`Take step ${at + 1} out`">
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground"
                                :aria-label="`Take step ${at + 1} out`"
                                @click="repro.steps.splice(at, 1)"
                            >
                                <X class="size-4" />
                            </button>
                        </Tip>
                    </li>
                </ol>
                <Button
                    v-if="canChange && repro.steps.length < 30"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="w-fit"
                    data-test="ticket-repro-add"
                    @click="addStep()"
                >
                    <Plus /> Add a step
                </Button>
                <p
                    v-else-if="!repro.steps.length"
                    class="text-muted-foreground text-sm"
                >
                    None
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="ticket-expected">Should happen</Label>
                    <textarea
                        id="ticket-expected"
                        v-model="repro.expected"
                        rows="2"
                        maxlength="2000"
                        class="bg-background dark:bg-input/30 border-input w-full rounded-md border px-3 py-2 text-sm"
                        placeholder="e.g. The shape stays still"
                        data-test="ticket-repro-expected"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="ticket-happens">Happens instead</Label>
                    <textarea
                        id="ticket-happens"
                        v-model="repro.happens"
                        rows="2"
                        maxlength="2000"
                        class="bg-background dark:bg-input/30 border-input w-full rounded-md border px-3 py-2 text-sm"
                        placeholder="e.g. It shakes while resized"
                        data-test="ticket-repro-happens"
                    />
                </div>
            </div>
            <InputError :message="errors.repro" />

            <!-- A browser check that does it: run, it says if it still happens -->
            <div v-if="suites.length || check" class="grid gap-2">
                <Label for="ticket-check">Browser check</Label>
                <TicketPick
                    id="ticket-check"
                    :model-value="chosen"
                    :options="checkOptions"
                    label="Browser check"
                    :disabled="!canChange"
                    data-test="ticket-check"
                    @update:model-value="choose"
                />
                <p class="text-muted-foreground text-xs">
                    One that does these steps and passes once it is fixed.
                </p>
                <InputError :message="errors.check" />

                <p
                    v-if="ticket?.check?.gone"
                    class="text-muted-foreground text-sm"
                >
                    The check it named has been deleted.
                </p>

                <div
                    v-if="filed && !unsaved"
                    class="bg-background flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border px-3 py-2"
                    data-test="ticket-replay"
                >
                    <span
                        v-if="going"
                        class="text-muted-foreground inline-flex items-center gap-1.5 text-sm"
                        data-test="ticket-replay-state"
                    >
                        <LoaderCircle class="size-4 animate-spin" />
                        {{
                            run?.state === 'waiting'
                                ? 'Waiting to run…'
                                : 'Running…'
                        }}
                    </span>
                    <span
                        v-else-if="run?.ok === false"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-rose-600 dark:text-rose-400"
                        data-test="ticket-replay-state"
                    >
                        <CircleX class="size-4" /> Still happens
                    </span>
                    <span
                        v-else-if="run?.ok"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400"
                        data-test="ticket-replay-state"
                    >
                        <CircleCheck class="size-4" /> No longer happens
                    </span>
                    <span
                        v-else
                        class="text-muted-foreground text-sm"
                        data-test="ticket-replay-state"
                    >
                        {{
                            run ? 'Stopped before it got there' : 'Not run yet'
                        }}
                    </span>
                    <span
                        v-if="run && !going"
                        class="text-muted-foreground text-xs"
                        >{{ formatRelativeTime(run.at) }}</span
                    >

                    <span class="flex-1" />

                    <Tip
                        v-if="run?.video && !going"
                        text="Watch the film of this run"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test="ticket-replay-film"
                            @click="watchFilm"
                        >
                            <Clapperboard /> Film
                        </Button>
                    </Tip>
                    <Tip v-if="run" text="See the run in Browser checks">
                        <Button as-child variant="ghost" size="icon-sm">
                            <Link
                                :href="run.page"
                                aria-label="See the run in Browser checks"
                                data-test="ticket-replay-run"
                            >
                                <ExternalLink />
                            </Link>
                        </Button>
                    </Tip>
                    <Button
                        v-if="canChange"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="going || starting"
                        data-test="ticket-replay-start"
                        @click="runIt"
                    >
                        <Play /> Run it
                    </Button>
                    <p
                        v-if="run?.verdict && run.ok === false"
                        class="text-muted-foreground w-full text-xs break-words"
                        data-test="ticket-replay-verdict"
                    >
                        {{ run.verdict }}
                    </p>
                    <InputError :message="runError" class="w-full" />
                </div>
                <p
                    v-else-if="unsaved && ticket"
                    class="text-muted-foreground text-xs"
                >
                    Save to run it from here.
                </p>
            </div>
        </div>
    </div>
</template>
