<script setup lang="ts">
import { Table2 } from '@lucide/vue';
import FolderListPage from '@/components/folders/FolderListPage.vue';
import type { ListItem } from '@/components/folders/FolderListPage.vue';
import type { FolderRef } from '@/composables/useFolderPage';
import * as folderRoutes from '@/routes/table-folders';
import * as routes from '@/routes/tables';

type TableSummary = ListItem & {
    // The columns the user added, and the rows kept
    columns: number;
    rows: number;
};

defineProps<{
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    folders: (FolderRef & { path?: string })[];
    tables: TableSummary[];
    filters: { q: string; sort: string; edited: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();

const count = (n: number, one: string) => `${n} ${n === 1 ? one : `${one}s`}`;
</script>

<template>
    <FolderListPage
        kind="table"
        root-label="Tables"
        :icon="Table2"
        description="Rows and columns of your own, each column a kind: text, dates, choices and more."
        search-placeholder="Search the titles of all tables"
        empty-hint="Make your first table to start filling it in."
        :routes="routes"
        :folder-routes="folderRoutes"
        :folder="folder"
        :breadcrumbs="breadcrumbs"
        :folders="folders"
        :items="tables"
        :filters="filters"
        :all-folders="allFolders"
    >
        <template #meta="{ item }">
            {{
                [count(item.columns, 'column'), count(item.rows, 'row')].join(
                    ' · ',
                )
            }}
        </template>
    </FolderListPage>
</template>
