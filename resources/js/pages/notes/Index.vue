<script setup lang="ts">
import { NotebookPen } from '@lucide/vue';
import FolderListPage from '@/components/folders/FolderListPage.vue';
import type { ListItem } from '@/components/folders/FolderListPage.vue';
import type { FolderRef } from '@/composables/useFolderPage';
import * as folderRoutes from '@/routes/note-folders';
import * as routes from '@/routes/notes';

defineProps<{
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    folders: (FolderRef & { path?: string })[];
    notes: ListItem[];
    filters: { q: string; sort: string; edited: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();
</script>

<template>
    <FolderListPage
        kind="note"
        root-label="Notes"
        :icon="NotebookPen"
        description="Your documents. Type / inside a note for blocks."
        search-placeholder="Search titles and text in all notes"
        empty-hint="Make your first note to start writing."
        :routes="routes"
        :folder-routes="folderRoutes"
        :folder="folder"
        :breadcrumbs="breadcrumbs"
        :folders="folders"
        :items="notes"
        :filters="filters"
        :all-folders="allFolders"
    />
</template>
