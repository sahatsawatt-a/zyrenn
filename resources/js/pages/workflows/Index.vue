<script setup lang="ts">
import { Workflow } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FolderListPage from '@/components/folders/FolderListPage.vue';
import type { ListItem } from '@/components/folders/FolderListPage.vue';
import type { FolderRef } from '@/composables/useFolderPage';
import * as projectFolderRoutes from '@/routes/projects/workflow-folders';
import * as projectRoutes from '@/routes/projects/workflows';
import * as folderRoutes from '@/routes/workflow-folders';
import * as routes from '@/routes/workflows';
import HealthStrip from '@/features/workflow/components/HealthStrip.vue';
import type { Health } from '@/features/workflow/components/HealthStrip.vue';
import TemplatePicker from '@/features/workflow/components/TemplatePicker.vue';

type WorkflowSummary = ListItem & {
    steps: number;
    // The version that runs on its own, if one is published
    published: number | null;
    unpublished_changes: boolean;
};

const props = defineProps<{
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    folders: (FolderRef & { path?: string })[];
    workflows: WorkflowSummary[];
    filters: { q: string; sort: string; edited: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();

// Shared by the server for this page: what a workflow can start from
const page = usePage<{
    templates: InstanceType<typeof TemplatePicker>['$props']['templates'];
    ownConnections: InstanceType<
        typeof TemplatePicker
    >['$props']['connections'];
    health?: Health;
}>();
const templates = computed(() => page.props.templates ?? []);
const connections = computed(() => page.props.ownConnections ?? []);
</script>

<template>
    <FolderListPage
        kind="workflow"
        root-label="Workflows"
        :icon="Workflow"
        description="Steps that run on their own: started by hand, on a schedule, or by a message, one after another."
        search-placeholder="Search workflows"
        empty-hint="Make your first workflow."
        :routes="routes"
        :folder-routes="folderRoutes"
        :project-routes="projectRoutes"
        :project-folder-routes="projectFolderRoutes"
        :folder="folder"
        :breadcrumbs="breadcrumbs"
        :folders="folders"
        :items="workflows"
        :filters="filters"
        :all-folders="allFolders"
    >
        <template #top>
            <HealthStrip v-if="page.props.health" :health="page.props.health" />
        </template>
        <template #actions="{ editable }">
            <TemplatePicker
                v-if="editable"
                :templates="templates"
                :connections="connections"
                :folder="props.folder?.ref_id ?? null"
            />
        </template>
        <template #meta="{ item }">
            {{ item.steps }} {{ item.steps === 1 ? 'step' : 'steps' }} ·
            {{
                item.published === null
                    ? 'not published'
                    : `v${item.published}${item.unpublished_changes ? ', changed since' : ''}`
            }}
        </template>
    </FolderListPage>
</template>
