<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/common/Heading.vue';
import ConnectionsManager from '@/features/connections/components/ConnectionsManager.vue';
import type { Activity } from '@/features/audit/ActivityList.vue';
import type { SavedConnection, Service } from '@/features/connections/services';
import { index } from '@/routes/connections';

defineProps<{
    connections: SavedConnection[];
    services: Record<string, Service>;
    can: { manage: boolean };
    urls: { store: string; check: string };
    activity: Activity[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Connections', href: index() }] },
});
</script>

<template>
    <Head title="Connections" />

    <h1 class="sr-only">Connections</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Connections"
            description="Where your chats and workflows reach outside: models, bots and API keys. They are yours alone; a project keeps its own."
        />

        <ConnectionsManager
            :connections="connections"
            :services="services"
            :can="can"
            :urls="urls"
            :activity="activity"
        />
    </div>
</template>
