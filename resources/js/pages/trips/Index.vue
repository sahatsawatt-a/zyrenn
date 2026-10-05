<script setup lang="ts">
import { Plane } from '@lucide/vue';
import FolderListPage from '@/components/folders/FolderListPage.vue';
import type { ListItem } from '@/components/folders/FolderListPage.vue';
import type { FolderRef } from '@/composables/useFolderPage';
import * as projectFolderRoutes from '@/routes/projects/trip-folders';
import * as projectRoutes from '@/routes/projects/trips';
import * as folderRoutes from '@/routes/trip-folders';
import * as routes from '@/routes/trips';

type TripSummary = ListItem & {
    days: number;
    start_date: string | null;
};

defineProps<{
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    folders: (FolderRef & { path?: string })[];
    trips: TripSummary[];
    filters: { q: string; sort: string; edited: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();

/** "Fri 13 Nov 2026" for a trip's first day. */
const startsOn = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
</script>

<template>
    <FolderListPage
        kind="trip"
        root-label="Trips"
        :icon="Plane"
        description="Trips planned day by day: flights, hotels, stops and the way between them, on a map."
        search-placeholder="Search trips and the places in them"
        empty-hint="Plan your first trip."
        :routes="routes"
        :folder-routes="folderRoutes"
        :project-routes="projectRoutes"
        :project-folder-routes="projectFolderRoutes"
        :folder="folder"
        :breadcrumbs="breadcrumbs"
        :folders="folders"
        :items="trips"
        :filters="filters"
        :all-folders="allFolders"
    >
        <template #meta="{ item }">
            {{ item.days }} {{ item.days === 1 ? 'day' : 'days'
            }}{{
                item.start_date ? ` · from ${startsOn(item.start_date)}` : ''
            }}
        </template>
    </FolderListPage>
</template>
