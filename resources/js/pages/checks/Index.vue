<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MonitorCheck } from '@lucide/vue';
import PageHeader from '@/components/common/PageHeader.vue';
import ChecksPanel from '@/features/browser/ChecksPanel.vue';
import type {
    Limits,
    Pace,
    Speed,
    SuiteSummary,
} from '@/features/browser/steps';

// Browser checks: suites of checks of any public site, kept in one's own
// space or a project's, built by clicking through its pages and run in a
// real browser on the server. (The app's own test runs are an admin page of
// their own, admin/TestRuns; the same browser's tours are in the Guide.)

defineProps<{
    suites: SuiteSummary[];
    spaces: { label: string; url: string; current: boolean }[];
    space: string | null;
    canChange: boolean;
    newSuite: string;
    speeds: Record<Exclude<Speed, 'custom'>, Pace>;
    limits: Limits;
}>();
</script>

<template>
    <Head title="Browser checks" />

    <div class="mx-auto w-full max-w-6xl space-y-4 p-4 md:p-6">
        <PageHeader
            :icon="MonitorCheck"
            title="Browser checks"
            description="A real browser going through a site as a customer would: quick to test it, or filmed to show it."
        />

        <ChecksPanel
            :suites="suites"
            :spaces="spaces"
            :space="space"
            :can-change="canChange"
            :new-suite="newSuite"
            :speeds="speeds"
            :limits="limits"
        />
    </div>
</template>
