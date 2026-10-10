<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleAlert, ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { duration } from '@/features/workflow/steps';
import { show as showWorkflow } from '@/routes/workflows';

// How these workflows have been doing lately (App\Support\Workflow\Health):
// a line of numbers, and what failed, a click away.

export type Health = {
    hours: number;
    runs: {
        total: number;
        succeeded: number;
        failed: number;
        cancelled: number;
        going: number;
        waiting: number;
    };
    duration_ms: { p50: number | null; p95: number | null };
    queue_ms: { p50: number | null; p95: number | null };
    retried: number;
    unsure: number;
    stuck: number;
    failures: {
        workflow: string;
        title: string;
        run: string;
        error: string | null;
        at: string | null;
    }[];
};

const props = defineProps<{ health: Health }>();

const open = ref(false);

const trouble = computed(
    () =>
        props.health.runs.failed > 0 ||
        props.health.stuck > 0 ||
        props.health.unsure > 0,
);

const ms = (value: number | null) => (value === null ? '–' : duration(value));
</script>

<template>
    <div
        v-if="health.runs.total || health.stuck || health.unsure"
        class="rounded-lg border text-sm"
        :class="trouble ? 'border-amber-500/40 bg-amber-500/5' : 'bg-muted/20'"
        data-health
    >
        <button
            type="button"
            class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-left"
            :disabled="!health.failures.length"
            @click="open = !open"
        >
            <span class="text-muted-foreground">Last {{ health.hours }} h</span>
            <span data-health-runs
                ><b>{{ health.runs.total }}</b>
                {{ health.runs.total === 1 ? 'run' : 'runs' }}</span
            >
            <span
                :class="{ 'text-destructive': health.runs.failed }"
                data-health-failed
                ><b>{{ health.runs.failed }}</b> failed</span
            >
            <span v-if="health.runs.going || health.runs.waiting"
                >{{ health.runs.going }} going ·
                {{ health.runs.waiting }} waiting</span
            >
            <span
                title="Half of runs took less, and 95 in 100 less than the second"
                >Run time {{ ms(health.duration_ms.p50) }} /
                {{ ms(health.duration_ms.p95) }}</span
            >
            <span
                title="How long a step waited for a worker: half, and 95 in 100, waited less"
                >Wait {{ ms(health.queue_ms.p50) }} /
                {{ ms(health.queue_ms.p95) }}</span
            >
            <span v-if="health.retried">{{ health.retried }} tried again</span>
            <span
                v-if="health.unsure"
                class="text-amber-700 dark:text-amber-400"
                >{{ health.unsure }} may not have gone out</span
            >
            <span
                v-if="health.stuck"
                class="text-destructive font-medium"
                data-health-stuck
                >{{ health.stuck }} stuck</span
            >
            <ChevronDown
                v-if="health.failures.length"
                class="text-muted-foreground ml-auto size-4 transition-transform"
                :class="{ 'rotate-180': open }"
            />
        </button>
        <ul v-if="open" class="space-y-1 border-t px-3 py-2">
            <li
                v-for="failure in health.failures"
                :key="failure.run"
                class="flex items-start gap-2 text-xs"
            >
                <CircleAlert
                    class="text-destructive mt-0.5 size-3.5 shrink-0"
                />
                <Link
                    :href="showWorkflow(failure.workflow)"
                    class="font-medium hover:underline"
                    >{{ failure.title }}</Link
                >
                <span class="text-muted-foreground min-w-0 flex-1 truncate">{{
                    failure.error
                }}</span>
                <span
                    v-if="failure.at"
                    class="text-muted-foreground shrink-0 tabular-nums"
                    >{{ new Date(failure.at).toLocaleString() }}</span
                >
            </li>
        </ul>
    </div>
</template>
