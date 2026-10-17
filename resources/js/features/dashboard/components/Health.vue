<script setup lang="ts">
import { computed } from 'vue';
import type { Running } from './glance';

// Whether a project runs well, each a line with a dot: green when all is
// well, red when something failed today, and new requests to look at.

const props = defineProps<{ running: Running }>();

const lines = computed(() =>
    [
        props.running.workflows_on || props.running.runs_failed
            ? {
                  label: 'Workflows',
                  dot: props.running.runs_failed
                      ? 'bg-rose-500'
                      : 'bg-emerald-500',
                  text: props.running.runs_failed
                      ? `${props.running.runs_failed} failed today`
                      : `${props.running.workflows_on} on · ran fine today`,
              }
            : null,
        props.running.suites
            ? {
                  label: 'Checks',
                  dot: props.running.suites_failing
                      ? 'bg-rose-500'
                      : 'bg-emerald-500',
                  text: props.running.suites_failing
                      ? `${props.running.suites_failing} of ${props.running.suites} failing`
                      : `${props.running.suites} ${props.running.suites === 1 ? 'suite' : 'suites'} · passing`,
              }
            : null,
        props.running.requests_new
            ? {
                  label: 'Requests',
                  dot: 'bg-sky-500',
                  text: `${props.running.requests_new} new`,
              }
            : null,
    ].filter((line) => line !== null),
);
</script>

<template>
    <div
        v-for="line in lines"
        :key="line.label"
        class="flex min-w-0 items-center gap-2"
    >
        <span class="size-2 shrink-0 rounded-full" :class="line.dot" />
        <span class="text-muted-foreground w-16 shrink-0">{{
            line.label
        }}</span>
        <span class="min-w-0 truncate">{{ line.text }}</span>
    </div>
</template>
