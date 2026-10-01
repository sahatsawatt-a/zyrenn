<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FolderPlus, HardDrive, SearchX, Upload } from '@lucide/vue';
import { useFileDialog } from '@vueuse/core';
import { computed, ref } from 'vue';
import FileCard from '@/components/drive/FileCard.vue';
import DeleteDialog from '@/components/folders/DeleteDialog.vue';
import EmptyState from '@/components/folders/EmptyState.vue';
import FolderCard from '@/components/folders/FolderCard.vue';
import ListToolbar from '@/components/folders/ListToolbar.vue';
import MoveDialog from '@/components/folders/MoveDialog.vue';
import MoveUpTarget from '@/components/folders/MoveUpTarget.vue';
import NameDialog from '@/components/folders/NameDialog.vue';
import PageHeader from '@/components/folders/PageHeader.vue';
import Section from '@/components/folders/Section.vue';
import MediaViewer from '@/components/MediaViewer.vue';
import { Button } from '@/components/ui/button';
import { useFileDrop } from '@/composables/useFileDrop';
import type { FolderItem } from '@/composables/useFolderDialogs';
import { useFolderPage } from '@/composables/useFolderPage';
import type { FolderRef } from '@/composables/useFolderPage';
import { useListFilters } from '@/composables/useListFilters';
import { useMediaViewer } from '@/composables/useMediaViewer';
import type { ViewerItem } from '@/composables/useMediaViewer';
import type { DriveFile } from '@/lib/drive';
import { canChange, currentProject, owned } from '@/lib/projects';
import * as driveRoutes from '@/routes/drive';
import * as fileRoutes from '@/routes/drive/files';
import * as folderRoutes from '@/routes/drive/folders';
import * as projectDriveRoutes from '@/routes/projects/drive';
import * as projectFileRoutes from '@/routes/projects/drive/files';
import * as projectFolderRoutes from '@/routes/projects/drive/folders';

// `path` is only there in search results, which span every folder
type FolderRow = FolderRef & { path?: string };
type FileRow = DriveFile & { path?: string | null };

const props = defineProps<{
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    folders: FolderRow[];
    files: FileRow[];
    filters: { q: string; sort: string; type: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();

const isEmpty = computed(() => !props.folders.length && !props.files.length);

// A project's viewers look and download, but add nothing
const editable = computed(canChange);

// Whose Drive it is, in what the page says
const inProject = computed(() => currentProject() !== null);
const description = computed(() =>
    inProject.value
        ? 'Files shared with everyone in the project. Images added to its notes are saved here.'
        : 'Your private files. Images you add to notes are saved here.',
);

// Listing, uploading and new folders go to the project the page is in, or the user's own
const index = owned(driveRoutes.index, projectDriveRoutes.index);
const storeFile = owned(fileRoutes.store, projectFileRoutes.store);

// ------------------------------------------------- Search, sort and filter
const {
    state: filters,
    isFiltered,
    isSearching,
    clear,
} = useListFilters({
    current: () => ({
        q: props.filters.q,
        sort: props.filters.sort,
        filter: props.filters.type,
    }),
    url: () => index.url(),
    filterParam: 'type',
    defaultSort: 'newest',
    folder: () => props.folder?.ref_id ?? null,
});

const sortOptions = [
    { value: 'newest', label: 'Newest first' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'name', label: 'Name A–Z' },
    { value: 'size', label: 'Largest first' },
];

const typeOptions = [
    { value: 'image', label: 'Images' },
    { value: 'pdf', label: 'PDFs' },
    { value: 'doc', label: 'Documents' },
    { value: 'audio', label: 'Audio' },
    { value: 'video', label: 'Video' },
    { value: 'archive', label: 'Archives' },
    { value: 'other', label: 'Other' },
];

// ------------------------------------------------ Folders, dialogs and drag
const {
    target,
    busy,
    nameOpen,
    moveOpen,
    deleteOpen,
    newFolder,
    rename,
    move,
    remove,
    firstError,
    folderHref,
    folderItem,
    parent,
    submitName,
    submitMove,
    submitDelete,
    dragging,
    dragProps,
    dropProps,
    isOver,
    isDragging,
} = useFolderPage({
    rootLabel: 'Drive',
    index,
    state: () => props,
    item: { kind: 'file', routes: fileRoutes, nameField: 'name' },
    folderRoutes: {
        ...folderRoutes,
        store: owned(folderRoutes.store, projectFolderRoutes.store),
    },
});

const fileItem = (file: DriveFile): FolderItem<'file' | 'folder'> => ({
    kind: 'file',
    ref_id: file.ref_id,
    name: file.name,
});

// ------------------------------------------------------------------ Upload
const progress = ref<number | null>(null);

const upload = (list: File[]) => {
    if (!list.length) {
        return;
    }

    router.post(
        storeFile.url(),
        { files: list, folder: props.folder?.ref_id ?? null },
        {
            forceFormData: true,
            preserveScroll: true,
            onProgress: (event) => (progress.value = event?.percentage ?? null),
            onError: firstError,
            onFinish: () => (progress.value = null),
        },
    );
};

const fileDialog = useFileDialog({ multiple: true, reset: true });
fileDialog.onChange((list) => upload(Array.from(list ?? [])));

const { droppingFiles, dropZoneProps } = useFileDrop(upload);

// ----------------------------------------------------------------- Opening
const viewer = useMediaViewer();

// What the viewer can show, in the order the folder lists them: a swipe goes
// from a picture to the next video and on
const media = computed(() =>
    props.files.filter((file) => file.is_image || file.is_video),
);

const openFile = (file: DriveFile) => {
    if (file.is_image || file.is_video) {
        viewer.open(
            media.value.map((item): ViewerItem =>
                item.is_video
                    ? { type: 'video', src: item.url, title: item.name }
                    : { type: 'image', src: item.url, alt: item.name },
            ),
            media.value.indexOf(file),
        );

        return;
    }

    window.open(
        file.kind === 'pdf' || file.mime === 'text/plain'
            ? file.url
            : fileRoutes.show.url(file.ref_id, { query: { download: 1 } }),
        '_blank',
        'noopener',
    );
};
</script>

<template>
    <Head :title="folder ? `${folder.name} · Drive` : 'Drive'" />

    <div
        class="relative mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6"
        v-bind="editable ? dropZoneProps : {}"
    >
        <PageHeader
            :icon="HardDrive"
            :title="folder?.name ?? 'Drive'"
            :description="description"
        >
            <Button v-if="editable" variant="outline" @click="newFolder">
                <FolderPlus />
                New folder
            </Button>
            <Button
                v-if="editable"
                :disabled="progress !== null"
                @click="fileDialog.open()"
            >
                <Upload />
                Upload
            </Button>
        </PageHeader>

        <ListToolbar
            v-model:q="filters.q"
            v-model:sort="filters.sort"
            v-model:filter="filters.filter"
            :placeholder="`Search file and folder names in ${inProject ? 'this' : 'your'} Drive`"
            :sort-options="sortOptions"
            :filter-options="typeOptions"
            filter-all="All types"
        />

        <p
            v-if="isSearching && !isEmpty"
            class="text-muted-foreground -mt-2 text-sm"
        >
            {{ files.length + folders.length }}
            {{ files.length + folders.length === 1 ? 'result' : 'results' }} for
            “{{ props.filters.q }}” across all folders
        </p>

        <!-- Upload progress -->
        <div
            v-if="progress !== null"
            class="bg-muted h-1.5 overflow-hidden rounded-full"
        >
            <div
                class="bg-primary h-full transition-[width]"
                :style="{ width: `${progress}%` }"
            ></div>
        </div>

        <MoveUpTarget
            v-if="folder && dragging && !isSearching"
            v-bind="dropProps(parent?.ref_id ?? null)"
            :label="parent?.name ?? 'Drive'"
            :active="isOver(parent?.ref_id ?? null)"
        />

        <!-- Folders -->
        <Section v-if="folders.length" title="Folders" :count="folders.length">
            <div
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            >
                <FolderCard
                    v-for="item in folders"
                    :key="item.ref_id"
                    v-bind="{
                        ...dragProps(folderItem(item)),
                        ...dropProps(item.ref_id),
                    }"
                    :folder="item"
                    :href="folderHref(item.ref_id)"
                    :query="props.filters.q"
                    :over="isOver(item.ref_id)"
                    :dragging="isDragging(item)"
                    @rename="rename(folderItem(item))"
                    @move="move(folderItem(item))"
                    @remove="remove(folderItem(item))"
                />
            </div>
        </Section>

        <Section v-if="files.length" title="Files" :count="files.length">
            <div
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            >
                <FileCard
                    v-for="file in files"
                    :key="file.ref_id"
                    v-bind="dragProps(fileItem(file))"
                    :file="file"
                    :query="props.filters.q"
                    :searching="isSearching"
                    :dragging="isDragging(file)"
                    @open="openFile(file)"
                    @rename="rename(fileItem(file))"
                    @move="move(fileItem(file))"
                    @remove="remove(fileItem(file))"
                />
            </div>
        </Section>

        <EmptyState
            v-if="isEmpty && isFiltered"
            :icon="SearchX"
            title="Nothing matches"
        >
            <Button variant="outline" size="sm" class="mt-2" @click="clear">
                Clear search and filter
            </Button>
        </EmptyState>

        <EmptyState
            v-else-if="isEmpty"
            :icon="HardDrive"
            :title="
                folder
                    ? 'This folder is empty'
                    : inProject
                      ? 'Nothing in this Drive yet'
                      : 'Your Drive is empty'
            "
        >
            <p v-if="editable" class="text-muted-foreground text-sm">
                Drop files here, or use Upload.
            </p>
        </EmptyState>

        <!-- Drop overlay -->
        <div
            v-if="droppingFiles"
            class="border-primary bg-primary/5 pointer-events-none absolute inset-2 z-10 flex items-center justify-center rounded-xl border-2 border-dashed"
        >
            <p
                class="bg-background rounded-md px-4 py-2 text-sm font-medium shadow"
            >
                Drop to upload to {{ folder?.name ?? 'Drive' }}
            </p>
        </div>
    </div>

    <NameDialog
        v-model:open="nameOpen"
        :title="target ? `Rename ${target.kind}` : 'New folder'"
        :submit-label="target ? 'Rename' : 'Create'"
        :initial="target?.name"
        :busy="busy"
        @submit="submitName"
    />
    <MoveDialog
        v-model:open="moveOpen"
        :name="target?.name ?? ''"
        :folders="allFolders"
        :current="folder?.ref_id ?? null"
        root-label="Drive"
        :moving-folder="target?.kind === 'folder' ? target.ref_id : undefined"
        :busy="busy"
        @submit="submitMove"
    />
    <DeleteDialog
        v-model:open="deleteOpen"
        :name="target?.name ?? ''"
        :description="
            (target?.kind === 'folder'
                ? 'The folder and everything in it will be deleted for good.'
                : 'The file will be deleted for good.') +
            ' Notes that show it will display a broken image.'
        "
        :busy="busy"
        @confirm="submitDelete"
    />

    <MediaViewer />
</template>
