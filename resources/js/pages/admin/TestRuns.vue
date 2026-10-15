<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { TestTubes } from '@lucide/vue';
import PageHeader from '@/components/common/PageHeader.vue';
import TestRunsPanel from '@/features/browser/TestRunsPanel.vue';
import type { Listener, RunSummary } from '@/features/browser/runs';

// The app's own browser suites (tests/e2e), run on this machine and followed
// as they go: about the app itself, so for its admins, and apart from
// anyone's browser checks.

defineProps<{
    initial: { listener: Listener | null; runs: RunSummary[] };
}>();
</script>

<template>
    <Head title="Test runs" />

    <div class="mx-auto w-full max-w-6xl space-y-4 p-4 md:p-6">
        <PageHeader
            :icon="TestTubes"
            title="Test runs"
            description="The app's own browser suites, run on this machine and followed as they go. Only admins see this."
        />
        <TestRunsPanel :initial="initial" />
    </div>
</template>
