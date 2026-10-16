<script setup lang="ts">
import {
    ChevronDown,
    CircleCheck,
    CircleDashed,
    CircleStop,
    CircleX,
    FolderOpen,
    Loader2,
    Play,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import VideoPlayer from '@/components/media/VideoPlayer.vue';
import type { Chapter } from '@/components/media/VideoPlayer.vue';
import PictureDialog from '@/features/chat/components/PictureDialog.vue';
import { SPEEDS, seconds, shotUrl } from './steps';
import type { CheckResult, CheckRun } from './steps';

// One run of a suite, said plainly: how it went in a sentence, then each check
// -- passed or where it stopped and why -- with its film and its pictures. A
// check that failed is open; one that passed opens on a click. A run still
// waiting or going can be stopped. A run filmed as one has one player, above
// its checks, each check's part of it marked and gone to from the check.

const props = defineProps<{ run: CheckRun; total: number }>();
const emit = defineEmits<{ stop: [] }>();

const going = computed(
    () => props.run.state === 'waiting' || props.run.state === 'running',
);

const time = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

const failed = computed(() => props.run.results.filter((check) => !check.ok));
const headline = computed(() => {
    const done = props.run.results.length;

    switch (props.run.state) {
        case 'waiting':
            return 'Waiting for the browser…';
        case 'running':
            return props.run.stop_asked
                ? `Stopping after the step in hand: ${done} of ${props.total} checks done`
                : `Running: ${done} of ${props.total} checks done`;
        case 'stopped':
            return props.run.stopped ?? 'Stopped';
        case 'passed':
            return done === 1
                ? 'Passed: the check did what it should'
                : `Passed: all ${done} checks did what they should`;
        default:
            return done === 1
                ? 'Failed: the check did not do what it should'
                : `Failed: ${failed.value.length} of ${done} checks did not`;
    }
});
const how = computed(() =>
    [
        SPEEDS[props.run.settings.speed].label,
        props.run.settings.captions ? 'captions' : null,
        props.run.settings.record ? 'filmed' : null,
        props.run.settings.keep ? 'kept in the Drive' : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

/** Where a check stopped, in a sentence. */
function verdict(check: CheckResult) {
    if (check.problem) {
        return check.problem;
    }

    if (check.ok) {
        return `Passed: ${check.planned} steps in ${seconds(check.took)}`;
    }

    if (check.stopped) {
        return `Stopped after step ${check.steps.length} of ${check.planned}`;
    }

    const at = check.steps.findIndex((step) => !step.ok);

    return at === -1
        ? `Stopped after step ${check.steps.length}`
        : `Failed at step ${at + 1}, ${check.steps[at].say}: ${check.steps[at].error}`;
}

// Open: the ones that failed, and any opened by hand
const opened = ref<Set<string>>(new Set());
// Each run starts as it is: the failed open, the passed shut
watch(
    () => props.run.ref_id,
    () => (opened.value = new Set()),
);
const isOpen = (check: CheckResult) => opened.value.has(check.id) !== !check.ok;
const toggle = (check: CheckResult) => {
    const next = new Set(opened.value);

    if (next.has(check.id)) {
        next.delete(check.id);
    } else {
        next.add(check.id);
    }

    opened.value = next;
};

// ------------------------------------------------------------ One film
// The checks done one after another and filmed as one: the film once, at the
// top, rather than under every check
const film = computed(() => {
    const videos = props.run.results.flatMap((check) =>
        check.video ? [check.video] : [],
    );

    return props.run.settings.one_film &&
        videos.length > 0 &&
        videos.every((video) => video.url === videos[0].url)
        ? videos[0]
        : null;
});
const player = ref<InstanceType<typeof VideoPlayer> | null>(null);
const now = ref(0);
// Each check's part of the film, a chapter on its timeline
const parts = computed(() =>
    props.run.results
        .filter((check) => check.film_at !== undefined)
        .map((check, at) => ({
            check,
            number: at + 1,
            from: check.film_at! / 1000,
        })),
);
const chapters = computed<Chapter[]>(() =>
    parts.value.map((part) => ({
        at: part.from,
        title: `${part.number}. ${part.check.title}`,
    })),
);
const playing = computed(
    () => parts.value.findLast((part) => part.from <= now.value + 0.05) ?? null,
);

/** The film from `ms` in, played, in view. */
function playFrom(ms: number) {
    if (!player.value) {
        return;
    }

    player.value.seek(ms / 1000);
    now.value = ms / 1000;
    void player.value.play();
    player.value.video?.scrollIntoView({
        block: 'nearest',
        behavior: 'smooth',
    });
}

// ------------------------------------------------------------ Pictures
const pictures = computed(() =>
    props.run.results.flatMap((check) =>
        check.steps.flatMap((step, at) => {
            const url = shotUrl(step.shot);

            return url
                ? [
                      {
                          check: check.id,
                          at,
                          url,
                          name: `${check.title}, step ${at + 1}`,
                      },
                  ]
                : [];
        }),
    ),
);
const showing = ref<number | null>(null);
const show = (check: string, at: number) =>
    (showing.value = pictures.value.findIndex(
        (one) => one.check === check && one.at === at,
    ));
</script>

<template>
    <div class="space-y-3" data-test="check-run" :data-run="run.ref_id">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex min-w-0 items-start gap-2">
                <Loader2
                    v-if="going"
                    class="size-5 shrink-0 animate-spin text-sky-600"
                />
                <CircleCheck
                    v-else-if="run.state === 'passed'"
                    class="size-5 shrink-0 text-emerald-600"
                />
                <CircleStop
                    v-else-if="run.state === 'stopped'"
                    class="text-muted-foreground size-5 shrink-0"
                />
                <CircleX v-else class="text-destructive size-5 shrink-0" />
                <div class="min-w-0">
                    <p class="font-medium" data-test="check-run-headline">
                        {{ headline }}
                    </p>
                    <!-- Why it has not started: what it is waiting behind -->
                    <p
                        v-if="run.state === 'waiting' && run.waiting_for"
                        class="text-muted-foreground text-xs"
                        data-test="check-run-waiting"
                    >
                        {{ run.waiting_for }}
                    </p>
                </div>
            </div>
            <Button
                v-if="going && !run.stop_asked"
                variant="outline"
                size="sm"
                title="Stop it: one waiting never starts, one running stops after the step it is on, keeping what it has done"
                data-test="check-run-stop"
                @click="emit('stop')"
            >
                <CircleStop /> Stop run
            </Button>
            <Link
                v-if="run.folder"
                :href="run.folder"
                class="text-muted-foreground hover:text-foreground ml-auto inline-flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                data-test="check-run-folder"
            >
                <FolderOpen class="size-3.5" /> Open in the Drive
            </Link>
        </div>
        <p class="text-muted-foreground text-xs">
            {{ time.format(new Date(run.created_at)) }} ·
            {{
                run.started_by === 'timetable'
                    ? 'by itself, on its timetable'
                    : run.started_by === 'workflow'
                      ? 'from a workflow'
                      : run.by
                        ? `by ${run.by}`
                        : 'by hand'
            }}
            · {{ how }}
        </p>
        <ul
            v-if="run.tickets.length"
            class="space-y-1 text-xs"
            data-test="check-run-tickets"
        >
            <li v-for="ticket in run.tickets" :key="ticket.number">
                <Link
                    :href="ticket.url"
                    class="text-foreground underline-offset-2 hover:underline"
                    >Bug ticket #{{ ticket.number }}</Link
                >
                {{ ticket.new ? 'filed' : 'already open' }}: {{ ticket.title }}
            </li>
        </ul>

        <div v-if="film" class="space-y-1.5" data-test="run-film">
            <VideoPlayer
                ref="player"
                :src="film.url"
                :chapters="chapters"
                class="w-full rounded-md border"
                data-test="check-video"
                @timeupdate="now = $event"
            />
            <p
                v-if="playing"
                class="text-muted-foreground truncate text-xs"
                data-test="run-film-now"
            >
                {{ playing.number }} of {{ parts.length }} ·
                {{ playing.check.title }}
            </p>
            <p
                v-if="film.cut"
                class="text-muted-foreground text-xs"
                data-test="film-cut"
            >
                {{ Math.round(film.cut / 1000) }} s of waiting for the site was
                cut from the film.
            </p>
            <p
                v-if="film.partial"
                class="text-muted-foreground text-xs"
                data-test="check-video-partial"
            >
                Partial: the run was stopped before the film was done.
            </p>
        </div>

        <ul class="space-y-2">
            <li
                v-for="check in run.results"
                :key="check.id"
                class="rounded-lg border"
                data-test="check-result"
                :data-ok="check.ok ? '1' : '0'"
            >
                <button
                    type="button"
                    class="hover:bg-accent/50 flex w-full items-start gap-2 rounded-lg px-3 py-2.5 text-left"
                    @click="toggle(check)"
                >
                    <CircleCheck
                        v-if="check.ok"
                        class="mt-0.5 size-4 shrink-0 text-emerald-600"
                    />
                    <CircleStop
                        v-else-if="check.stopped"
                        class="text-muted-foreground mt-0.5 size-4 shrink-0"
                    />
                    <CircleX
                        v-else
                        class="text-destructive mt-0.5 size-4 shrink-0"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{
                            check.title
                        }}</span>
                        <span
                            class="block text-xs"
                            :class="
                                check.ok || check.stopped
                                    ? 'text-muted-foreground'
                                    : 'text-destructive'
                            "
                            data-test="check-verdict"
                            >{{ verdict(check) }}</span
                        >
                    </span>
                    <span
                        v-if="film && check.film_at !== undefined"
                        role="button"
                        tabindex="0"
                        class="text-muted-foreground hover:text-foreground hover:bg-accent -my-0.5 rounded p-0.5"
                        :aria-label="`Play ${check.title} in the film`"
                        title="Play it in the film"
                        data-test="check-play"
                        @click.stop="playFrom(check.film_at)"
                        @keydown.enter.stop.prevent="playFrom(check.film_at)"
                    >
                        <Play class="size-4" />
                    </span>
                    <ChevronDown
                        class="text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform"
                        :class="{ 'rotate-180': isOpen(check) }"
                    />
                </button>

                <div v-if="isOpen(check)" class="space-y-3 px-3 pb-3">
                    <VideoPlayer
                        v-if="check.video && !film"
                        :src="check.video.url"
                        class="w-full rounded-md border"
                        data-test="check-video"
                    />
                    <p
                        v-if="check.video?.cut && !film"
                        class="text-muted-foreground text-xs"
                        data-test="film-cut"
                    >
                        {{ Math.round(check.video.cut / 1000) }} s of waiting
                        for the site was cut from the film.
                    </p>
                    <p
                        v-if="check.video?.partial && !film"
                        class="text-muted-foreground text-xs"
                        data-test="check-video-partial"
                    >
                        Partial: the run was stopped before the film was done.
                    </p>
                    <ol class="space-y-1">
                        <li
                            v-for="(step, at) in check.steps"
                            :key="at"
                            class="flex items-center gap-2 text-sm"
                            data-test="step-result"
                        >
                            <span
                                class="text-muted-foreground w-5 shrink-0 text-center text-xs tabular-nums"
                                >{{ at + 1 }}</span
                            >
                            <CircleCheck
                                v-if="step.ok"
                                class="size-4 shrink-0 text-emerald-600"
                            />
                            <CircleX
                                v-else
                                class="text-destructive size-4 shrink-0"
                            />
                            <span class="min-w-0 flex-1">
                                {{ step.say }}
                                <span
                                    v-if="!step.ok"
                                    class="text-destructive block text-xs"
                                    >{{ step.error }}</span
                                >
                            </span>
                            <button
                                v-if="film && step.at !== undefined"
                                type="button"
                                class="text-muted-foreground hover:text-foreground text-xs tabular-nums underline-offset-2 hover:underline"
                                title="Play from this step in the film"
                                data-test="step-play"
                                @click="playFrom(step.at)"
                            >
                                {{ seconds(step.took) }}
                            </button>
                            <span
                                v-else
                                class="text-muted-foreground text-xs tabular-nums"
                                >{{ seconds(step.took) }}</span
                            >
                            <button
                                v-if="shotUrl(step.shot)"
                                type="button"
                                class="overflow-hidden rounded border"
                                aria-label="The picture, larger"
                                data-test="step-shot"
                                @click="show(check.id, at)"
                            >
                                <img
                                    :src="shotUrl(step.shot)!"
                                    alt=""
                                    class="h-9 w-14 object-cover object-top"
                                />
                            </button>
                        </li>
                        <li
                            v-for="left in check.planned - check.steps.length"
                            :key="`left-${left}`"
                            class="text-muted-foreground flex items-center gap-2 text-sm"
                        >
                            <span
                                class="w-5 shrink-0 text-center text-xs tabular-nums"
                                >{{ check.steps.length + left }}</span
                            >
                            <CircleDashed class="size-4 shrink-0" />
                            Not reached
                        </li>
                    </ol>
                    <p
                        v-if="check.refused.length"
                        class="text-muted-foreground text-xs"
                    >
                        Kept from {{ check.refused.join(', ') }}: not on the
                        public internet.
                    </p>
                </div>
            </li>
        </ul>

        <!-- What each step met in the browser: for the app's admins, folded -->
        <details
            v-if="run.log?.length"
            class="rounded-lg border px-3 py-2 text-xs"
            data-test="check-run-log"
        >
            <summary class="text-muted-foreground cursor-pointer font-medium">
                Log
                <span class="font-normal"
                    >· {{ run.log.length }}
                    {{ run.log.length === 1 ? 'step' : 'steps' }}</span
                >
            </summary>
            <ol class="mt-2 space-y-2">
                <li
                    v-for="(entry, at) in run.log"
                    :key="at"
                    class="min-w-0"
                    data-test="check-run-log-step"
                >
                    <p class="text-muted-foreground flex flex-wrap gap-x-2">
                        <span class="text-foreground font-medium"
                            >{{ entry.check }}, step {{ entry.step + 1 }}</span
                        >
                        <span class="tabular-nums"
                            >{{ seconds(entry.from) }}–{{
                                seconds(entry.to)
                            }}</span
                        >
                        <span
                            v-if="(entry.waited ?? 0) >= 100"
                            class="tabular-nums"
                            >waited {{ seconds(entry.waited ?? 0) }}</span
                        >
                        <span class="min-w-0 truncate">{{ entry.url }}</span>
                    </p>
                    <pre
                        v-if="entry.lines.length"
                        class="bg-muted mt-1 overflow-x-auto rounded px-2 py-1 font-mono text-[11px] leading-snug break-all whitespace-pre-wrap"
                        >{{ entry.lines.join('\n') }}</pre>
                </li>
            </ol>
        </details>

        <PictureDialog
            :open="showing !== null"
            :file="showing === null ? null : pictures[showing]"
            :place="
                pictures.length > 1 && showing !== null
                    ? `${showing + 1} of ${pictures.length}`
                    : undefined
            "
            @update:open="!$event && (showing = null)"
            @step="
                showing =
                    (showing! + $event + pictures.length) % pictures.length
            "
        />
    </div>
</template>
