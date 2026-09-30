<script setup lang="ts" generic="T extends ListItem">
import { Form, Head } from '@inertiajs/vue3';
import { FolderPlus, Plus, SearchX } from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import type { Component } from 'vue';
import { computed } from 'vue';
import DeleteDialog from '@/components/folders/DeleteDialog.vue';
import EmptyState from '@/components/folders/EmptyState.vue';
import FolderCard from '@/components/folders/FolderCard.vue';
import ItemCard from '@/components/folders/ItemCard.vue';
import ListToolbar from '@/components/folders/ListToolbar.vue';
import MoveDialog from '@/components/folders/MoveDialog.vue';
import MoveUpTarget from '@/components/folders/MoveUpTarget.vue';
import NameDialog from '@/components/folders/NameDialog.vue';
import PageHeader from '@/components/folders/PageHeader.vue';
import Section from '@/components/folders/Section.vue';
import { Button } from '@/components/ui/button';
import type { FolderItem } from '@/composables/useFolderDialogs';
import { useFolderPage } from '@/composables/useFolderPage';
import type { FolderRef } from '@/composables/useFolderPage';
import { useListFilters } from '@/composables/useListFilters';
import { canChange, owned } from '@/lib/projects';
import type { OwnRoute, ProjectRoute } from '@/lib/projects';

// A note, board or table as its list shows it; each kind adds what it counts
export type ListItem = {
    ref_id: string;
    title: string;
    updated_at: string;
    created_at: string;
    // Only in search results, which span every folder
    path?: string | null;
    snippet?: string | null;
};

type ByRef = { url: (ref_id: string) => string };

/**
 * The page listing one kind of thing kept in folders -- notes, boards,
 * tables. The kind says what it is called and how it is drawn; the rest,
 * from search to dragging onto folders, is the same for all of them.
 */
const props = defineProps<{
    // What one of them is called, e.g. "board"
    kind: string;
    // What they are called together, and the top level: "Boards"
    rootLabel: string;
    icon: Component;
    description: string;
    searchPlaceholder: string;
    // Said when there are none at all yet
    emptyHint: string;
    routes: {
        index: OwnRoute<'get'>;
        show: ByRef;
        store: OwnRoute<'post'>;
        update: ByRef;
        destroy: ByRef;
    };
    folderRoutes: {
        store: OwnRoute<'post'>;
        update: ByRef;
        destroy: ByRef;
    };
    // The same list and make routes in a project, at /p/{project}/...
    projectRoutes: {
        index: ProjectRoute<'get'>;
        store: ProjectRoute<'post'>;
    };
    projectFolderRoutes: { store: ProjectRoute<'post'> };
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    // `path` is only there in search results, which span every folder
    folders: (FolderRef & { path?: string })[];
    items: T[];
    filters: { q: string; sort: string; edited: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();

defineSlots<{
    // What the list says of each one beyond its title, e.g. "3 items"
    meta?: (props: { item: T }) => unknown;
}>();

const isEmpty = computed(() => !props.folders.length && !props.items.length);

// A project's viewers look, but make nothing
const editable = computed(canChange);

// Listing and making go to the project the page is in, or the user's own
const index = owned(props.routes.index, props.projectRoutes.index);
const store = owned(props.routes.store, props.projectRoutes.store);
const folderRoutes = {
    ...props.folderRoutes,
    store: owned(props.folderRoutes.store, props.projectFolderRoutes.store),
};

// Cards or rows, remembered for each kind
const view = useLocalStorage<'grid' | 'list'>(
    `zyrenn:view:${props.kind}`,
    'grid',
);

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
        filter: props.filters.edited,
    }),
    url: () => index.url(),
    filterParam: 'edited',
    defaultSort: 'edited',
    folder: () => props.folder?.ref_id ?? null,
});

const sortOptions = [
    { value: 'edited', label: 'Last edited' },
    { value: 'created', label: 'Date created' },
    { value: 'title', label: 'Title A–Z' },
];

const editedOptions = [
    { value: 'today', label: 'Edited today' },
    { value: 'week', label: 'Past 7 days' },
    { value: 'month', label: 'Past 30 days' },
];

const byCreated = computed(() => props.filters.sort === 'created');

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
    rootLabel: props.rootLabel,
    index,
    state: () => props,
    item: {
        kind: props.kind,
        routes: { update: props.routes.update, destroy: props.routes.destroy },
        nameField: 'title',
    },
    folderRoutes,
});

const asItem = (item: T): FolderItem => ({
    kind: props.kind,
    ref_id: item.ref_id,
    name: item.title || 'Untitled',
});

const plural = computed(() => props.rootLabel.toLowerCase());
</script>

<template>
    <Head :title="folder ? `${folder.name} · ${rootLabel}` : rootLabel" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :icon="icon"
            :title="folder?.name ?? rootLabel"
            :description="description"
        >
            <Button v-if="editable" variant="outline" @click="newFolder">
                <FolderPlus />
                New folder
            </Button>
            <Form v-if="editable" v-bind="store.form()" v-slot="{ processing }">
                <input
                    v-if="folder"
                    type="hidden"
                    name="folder"
                    :value="folder.ref_id"
                />
                <Button type="submit" :disabled="processing">
                    <Plus />
                    New {{ kind }}
                </Button>
            </Form>
        </PageHeader>

        <ListToolbar
            v-model:q="filters.q"
            v-model:sort="filters.sort"
            v-model:filter="filters.filter"
            v-model:view="view"
            :placeholder="searchPlaceholder"
            :sort-options="sortOptions"
            :filter-options="editedOptions"
            filter-all="Any time"
        />

        <p
            v-if="isSearching && !isEmpty"
            class="text-muted-foreground -mt-2 text-sm"
        >
            {{ items.length + folders.length }}
            {{ items.length + folders.length === 1 ? 'result' : 'results' }}
            for “{{ props.filters.q }}” across all folders
        </p>

        <MoveUpTarget
            v-if="folder && dragging && !isSearching"
            v-bind="dropProps(parent?.ref_id ?? null)"
            :label="parent?.name ?? rootLabel"
            :active="isOver(parent?.ref_id ?? null)"
        />

        <Section v-if="folders.length" title="Folders" :count="folders.length">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
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

        <Section
            v-if="items.length"
            :title="folders.length ? rootLabel : undefined"
            :count="items.length"
        >
            <div
                :class="
                    view === 'grid'
                        ? 'grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4'
                        : 'divide-border bg-card divide-y overflow-hidden rounded-xl border'
                "
            >
                <ItemCard
                    v-for="item in items"
                    :key="item.ref_id"
                    v-bind="dragProps(asItem(item))"
                    :layout="view"
                    :href="routes.show.url(item.ref_id)"
                    :icon="icon"
                    :title="item.title"
                    :query="props.filters.q"
                    :path="item.path"
                    :snippet="item.snippet"
                    :searching="isSearching"
                    :root-label="rootLabel"
                    :time="byCreated ? item.created_at : item.updated_at"
                    :time-label="byCreated ? 'Created' : 'Last edited'"
                    :dragging="isDragging(asItem(item))"
                    @rename="rename(asItem(item))"
                    @move="move(asItem(item))"
                    @remove="remove(asItem(item))"
                >
                    <template v-if="$slots.meta" #default>
                        <slot name="meta" :item="item" />
                    </template>
                </ItemCard>
            </div>
        </Section>

        <EmptyState
            v-if="isEmpty && isFiltered"
            :icon="SearchX"
            title="Nothing matches"
        >
            <p class="text-muted-foreground text-sm">
                Try other words, or look further back.
            </p>
            <Button variant="outline" size="sm" class="mt-2" @click="clear">
                Clear search and filter
            </Button>
        </EmptyState>

        <EmptyState
            v-else-if="isEmpty"
            :icon="icon"
            :title="folder ? 'This folder is empty' : `No ${plural} yet`"
        >
            <p v-if="editable" class="text-muted-foreground text-sm">
                {{ folder ? `Make a ${kind} or a folder in it.` : emptyHint }}
            </p>
            <Form
                v-if="editable"
                v-bind="store.form()"
                v-slot="{ processing }"
                class="mt-2"
            >
                <input
                    v-if="folder"
                    type="hidden"
                    name="folder"
                    :value="folder.ref_id"
                />
                <Button size="sm" type="submit" :disabled="processing">
                    <Plus />
                    New {{ kind }}
                </Button>
            </Form>
        </EmptyState>
    </div>

    <NameDialog
        v-model:open="nameOpen"
        :title="target ? `Rename ${target.kind}` : 'New folder'"
        :submit-label="target ? 'Rename' : 'Create'"
        :initial="
            target?.kind === kind && target.name === 'Untitled'
                ? ''
                : target?.name
        "
        :busy="busy"
        @submit="submitName"
    />
    <MoveDialog
        v-model:open="moveOpen"
        :name="target?.name ?? ''"
        :folders="allFolders"
        :current="folder?.ref_id ?? null"
        :root-label="rootLabel"
        :moving-folder="target?.kind === 'folder' ? target.ref_id : undefined"
        :busy="busy"
        @submit="submitMove"
    />
    <DeleteDialog
        v-model:open="deleteOpen"
        :name="target?.name ?? ''"
        :description="
            target?.kind === 'folder'
                ? `The folder and every ${kind} and folder in it will be deleted for good.`
                : `The ${kind} will be deleted for good.`
        "
        :busy="busy"
        @confirm="submitDelete"
    />
</template>
