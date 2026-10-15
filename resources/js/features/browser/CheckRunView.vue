<script setup lang="ts">
import {
    ChevronDown,
    CircleCheck,
    CircleDashed,
    CircleX,
    FolderOpen,
    Loader2,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PictureDialog from '@/features/chat/components/PictureDialog.vue';
import { SPEEDS, seconds, shotUrl } from './steps';
import type { CheckResult, CheckRun } from './steps';

// One run of a suite, said plainly: how it went in a sentence, then each check
// -- passed or where it stopped and why -- with its film and its pictures. A
// check that failed is open; one that passed opens on a click.

const props = defineProps<{ run: CheckRun; total: number }>();

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
            return `Running: ${done} of ${props.total} checks done`;
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
                    v-if="run.state === 'waiting' || run.state === 'running'"
                    class="size-5 shrink-0 animate-spin text-sky-600"
                />
                <CircleCheck
                    v-else-if="run.state === 'passed'"
                    class="size-5 shrink-0 text-emerald-600"
                />
                <CircleX v-else class="text-destructive size-5 shrink-0" />
                <p class="font-medium" data-test="check-run-headline">
                    {{ headline }}
                </p>
            </div>
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
                                check.ok
                                    ? 'text-muted-foreground'
                                    : 'text-destructive'
                            "
                            data-test="check-verdict"
                            >{{ verdict(check) }}</span
                        >
                    </span>
                    <ChevronDown
                        class="text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform"
                        :class="{ 'rotate-180': isOpen(check) }"
                    />
                </button>

                <div v-if="isOpen(check)" class="space-y-3 px-3 pb-3">
                    <video
                        v-if="check.video"
                        :src="check.video.url"
                        controls
                        preload="metadata"
                        class="w-full rounded-md border bg-black"
                        data-test="check-video"
                    />
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
                            <span
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
