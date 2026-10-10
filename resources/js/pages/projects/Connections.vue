<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { Plug } from '@lucide/vue';
import { watchEffect } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import ConnectionsManager from '@/features/connections/components/ConnectionsManager.vue';
import type { Activity } from '@/features/audit/ActivityList.vue';
import type { SavedConnection, Service } from '@/features/connections/services';
import { edit } from '@/routes/projects';
import { index } from '@/routes/projects/connections';

const props = defineProps<{
    connections: SavedConnection[];
    services: Record<string, Service>;
    can: { manage: boolean };
    urls: { store: string; check: string };
    activity: Activity[];
    project: { ref_id: string; name: string };
}>();

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: props.project.name, href: edit(props.project.ref_id) },
            { title: 'Settings', href: edit(props.project.ref_id) },
            { title: 'Connections', href: index(props.project.ref_id) },
        ],
    });
});
</script>

<template>
    <Head :title="`Connections · ${project.name}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-6">
        <PageHeader
            :icon="Plug"
            title="Connections"
            :description="`Models, bots and API keys for ${project.name}’s workflows.`"
        />

        <ConnectionsManager
            :connections="connections"
            :services="services"
            :can="can"
            :urls="urls"
            :activity="activity"
            shared
        />
    </div>
</template>
