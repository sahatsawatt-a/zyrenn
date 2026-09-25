<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    FileText,
    Folder,
    FolderInput,
    FolderPlus,
    MoreHorizontal,
    SearchX,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import DeleteDialog from '@/components/folders/DeleteDialog.vue';
import EmptyState from '@/components/folders/EmptyState.vue';
import FolderCard from '@/components/folders/FolderCard.vue';
import Highlight from '@/components/folders/Highlight.vue';
import ListToolbar from '@/components/folders/ListToolbar.vue';
import MoveDialog from '@/components/folders/MoveDialog.vue';
import MoveUpTarget from '@/components/folders/MoveUpTarget.vue';
import NameDialog from '@/components/folders/NameDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { FolderItem } from '@/composables/useFolderDialogs';
import { useFolderPage } from '@/composables/useFolderPage';
import type { FolderRef } from '@/composables/useFolderPage';
import { useListFilters } from '@/composables/useListFilters';
import { formatRelativeTime } from '@/lib/utils';
import * as folderRoutes from '@/routes/note-folders';
import { destroy, index, show, store, update } from '@/routes/notes';

// `path` is only there in search results, which span every folder
type FolderRow = FolderRef & { path?: string };

type NoteSummary = {
    ref_id: string;
    title: string;
    updated_at: string;
    created_at: string;
    path?: string | null;
    snippet?: string | null;
};

const props = defineProps<{
    folder: FolderRef | null;
    breadcrumbs: FolderRef[];
    folders: FolderRow[];
    notes: NoteSummary[];
    filters: { q: string; sort: string; edited: string | null };
    allFolders: { ref_id: string; path: string }[];
}>();

const isEmpty = computed(() => !props.folders.length && !props.notes.length);

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

const shownTime = (note: NoteSummary) =>
    props.filters.sort === 'created' ? note.created_at : note.updated_at;

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
    rootLabel: 'Notes',
    index,
    state: () => props,
    item: { kind: 'note', routes: { update, destroy }, nameField: 'title' },
    folderRoutes,
});

const noteItem = (note: NoteSummary): FolderItem<'note' | 'folder'> => ({
    kind: 'note',
    ref_id: note.ref_id,
    name: note.title || 'Untitled',
});
</script>

<template>
    <Head :title="folder ? `${folder.name} · Notes` : 'Notes'" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="folder?.name ?? 'Notes'"
                description="Your documents. Type / inside a note for blocks."
            />

            <div class="flex gap-2">
                <Button variant="outline" @click="newFolder">
                    <FolderPlus />
                    New folder
                </Button>
                <Form v-bind="store.form()" v-slot="{ processing }">
                    <input
                        v-if="folder"
                        type="hidden"
                        name="folder"
                        :value="folder.ref_id"
                    />
                    <Button type="submit" :disabled="processing">
                        <Plus />
                        New note
                    </Button>
                </Form>
            </div>
        </div>

        <ListToolbar
            v-model:q="filters.q"
            v-model:sort="filters.sort"
            v-model:filter="filters.filter"
            placeholder="Search titles and text in all notes"
            :sort-options="sortOptions"
            :filter-options="editedOptions"
            filter-all="Any time"
        />

        <p
            v-if="isSearching && !isEmpty"
            class="text-muted-foreground -mt-2 text-sm"
        >
            {{ notes.length + folders.length }}
            {{ notes.length + folders.length === 1 ? 'result' : 'results' }} for
            “{{ props.filters.q }}” across all folders
        </p>

        <MoveUpTarget
            v-if="folder && dragging && !isSearching"
            v-bind="dropProps(parent?.ref_id ?? null)"
            :label="parent?.name ?? 'Notes'"
            :active="isOver(parent?.ref_id ?? null)"
        />

        <!-- Folders -->
        <section v-if="folders.length" class="flex flex-col gap-3">
            <h3
                class="text-muted-foreground text-xs font-medium tracking-wide uppercase"
            >
                Folders
            </h3>
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
        </section>

        <!-- Notes -->
        <section v-if="notes.length" class="flex flex-col gap-3">
            <h3
                v-if="folders.length"
                class="text-muted-foreground text-xs font-medium tracking-wide uppercase"
            >
                Notes
            </h3>
            <ul
                class="divide-border border-sidebar-border/70 dark:border-sidebar-border divide-y overflow-hidden rounded-xl border"
            >
                <li
                    v-for="note in notes"
                    :key="note.ref_id"
                    v-bind="dragProps(noteItem(note))"
                    class="group hover:bg-muted/60 flex items-center transition-colors"
                    :class="{ 'opacity-50': isDragging(note) }"
                >
                    <Link
                        :href="show(note.ref_id)"
                        class="flex min-w-0 flex-1 items-start gap-3 py-3 pl-4"
                    >
                        <FileText
                            class="text-muted-foreground mt-0.5 size-4 shrink-0"
                        />
                        <span class="min-w-0 flex-1">
                            <Highlight
                                :text="note.title || 'Untitled'"
                                :query="props.filters.q"
                                class="block truncate font-medium"
                                :class="{
                                    'text-muted-foreground': !note.title,
                                }"
                            />
                            <span
                                v-if="isSearching"
                                class="text-muted-foreground block truncate text-xs"
                            >
                                <Folder
                                    class="mr-1 inline size-3 align-[-2px]"
                                />{{ note.path ?? 'Notes' }}
                            </span>
                            <Highlight
                                v-if="note.snippet"
                                :text="note.snippet"
                                :query="props.filters.q"
                                class="text-muted-foreground mt-1 line-clamp-2 block text-sm"
                            />
                        </span>
                        <time
                            :datetime="shownTime(note)"
                            :title="
                                filters.sort === 'created'
                                    ? 'Created'
                                    : 'Last edited'
                            "
                            class="text-muted-foreground mt-0.5 shrink-0 text-xs"
                        >
                            {{ formatRelativeTime(shownTime(note)) }}
                        </time>
                    </Link>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="mx-2 size-7 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100"
                            >
                                <MoreHorizontal />
                                <span class="sr-only">Note actions</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="rename(noteItem(note))">
                                <Pencil /> Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="move(noteItem(note))">
                                <FolderInput /> Move
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                @select="remove(noteItem(note))"
                            >
                                <Trash2 /> Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </li>
            </ul>
        </section>

        <EmptyState
            v-if="isEmpty && isFiltered"
            :icon="SearchX"
            title="Nothing matches"
        >
            <Button variant="outline" size="sm" @click="clear"
                >Clear search and filter</Button
            >
        </EmptyState>

        <EmptyState
            v-else-if="isEmpty"
            :icon="FileText"
            :title="folder ? 'This folder is empty' : 'No notes yet'"
        >
            <p class="text-muted-foreground text-sm">
                {{
                    folder
                        ? 'Create a note or a folder in it.'
                        : 'Create your first note to start writing.'
                }}
            </p>
        </EmptyState>
    </div>

    <NameDialog
        v-model:open="nameOpen"
        :title="target ? `Rename ${target.kind}` : 'New folder'"
        :submit-label="target ? 'Rename' : 'Create'"
        :initial="
            target?.kind === 'note' && target.name === 'Untitled'
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
        root-label="Notes"
        :moving-folder="target?.kind === 'folder' ? target.ref_id : undefined"
        :busy="busy"
        @submit="submitMove"
    />
    <DeleteDialog
        v-model:open="deleteOpen"
        :name="target?.name ?? ''"
        :description="
            target?.kind === 'folder'
                ? 'The folder and every note and folder in it will be deleted for good.'
                : 'The note will be deleted for good.'
        "
        :busy="busy"
        @confirm="submitDelete"
    />
</template>
