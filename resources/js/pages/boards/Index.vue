<script setup lang="ts">
import { Presentation } from '@lucide/vue';
import FolderListPage from '@/components/folders/FolderListPage.vue';
import type { ListItem } from '@/components/folders/FolderListPage.vue';
import type { FolderRef } from '@/composables/useFolderPage';
import * as folderRoutes from '@/routes/board-folders';
import * as routes from '@/routes/boards';

type BoardSummary = ListItem & {
    // How many things are on it, shown in the list
    items: number;
};

defineProps<{
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    folders: (FolderRef & { path?: string })[];
    boards: BoardSummary[];
    filters: { q: string; sort: string; edited: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();
</script>

<template>
    <FolderListPage
        kind="board"
        root-label="Boards"
        :icon="Presentation"
        description="Endless canvases: sticky notes, shapes, connectors and frames to present."
        search-placeholder="Search titles and labels on all boards"
        empty-hint="Make your first board to start drawing."
        :routes="routes"
        :folder-routes="folderRoutes"
        :folder="folder"
        :breadcrumbs="breadcrumbs"
        :folders="folders"
        :items="boards"
        :filters="filters"
        :all-folders="allFolders"
    >
        <template #meta="{ item }">
            {{ item.items }} {{ item.items === 1 ? 'item' : 'items' }}
        </template>
    </FolderListPage>
</template>
