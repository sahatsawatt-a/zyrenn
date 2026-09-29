<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronsUpDown,
    FolderKanban,
    Plus,
    Settings,
    User,
} from '@lucide/vue';
import { computed } from 'vue';
import NameDialog from '@/components/folders/NameDialog.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useFolderDialogs } from '@/composables/useFolderDialogs';
import { index as boardsIndex } from '@/routes/boards';
import { index as driveIndex } from '@/routes/drive';
import { index as notesIndex } from '@/routes/notes';
import { edit, store } from '@/routes/projects';
import { index as projectBoardsIndex } from '@/routes/projects/boards';
import { index as projectDriveIndex } from '@/routes/projects/drive';
import { index as projectNotesIndex } from '@/routes/projects/notes';
import { index as projectTablesIndex } from '@/routes/projects/tables';
import { index as tablesIndex } from '@/routes/tables';

const page = usePage();
const project = computed(() => page.props.project);
const projects = computed(() => page.props.projects);
const { isMobile, state } = useSidebar();

// Switching keeps to the same kind of thing: from a project's boards to your own
const sections = {
    notes: [notesIndex, projectNotesIndex],
    boards: [boardsIndex, projectBoardsIndex],
    tables: [tablesIndex, projectTablesIndex],
    drive: [driveIndex, projectDriveIndex],
} as const;

const section = computed(() => {
    const kind = page.component.split('/')[0];

    return kind in sections ? (kind as keyof typeof sections) : 'notes';
});

const personalHref = computed(() => sections[section.value][0]());
const projectHref = (ref_id: string) => sections[section.value][1](ref_id);

// A new project opens on its notes
const {
    busy,
    nameOpen,
    newFolder: newProject,
    visitOptions,
} = useFolderDialogs();

const create = (name: string) =>
    router.post(store.url(), { name }, visitOptions);
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        :tooltip="project?.name ?? 'Personal'"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="project-switcher"
                    >
                        <div
                            class="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-lg"
                        >
                            <FolderKanban v-if="project" class="size-4" />
                            <User v-else class="size-4" />
                        </div>
                        <div
                            class="grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-medium">
                                {{ project?.name ?? 'Personal' }}
                            </span>
                            <span
                                class="text-muted-foreground truncate text-xs capitalize"
                            >
                                {{
                                    project
                                        ? `Project · ${project.role ?? 'member'}`
                                        : 'Only you'
                                }}
                            </span>
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'right'
                              : 'bottom'
                    "
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuItem as-child>
                        <Link :href="personalHref" class="gap-2">
                            <User class="size-4" />
                            <span class="flex-1">Personal</span>
                            <Check v-if="!project" class="size-4" />
                        </Link>
                    </DropdownMenuItem>

                    <template v-if="projects.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-muted-foreground text-xs font-normal"
                        >
                            Projects
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="item in projects"
                            :key="item.ref_id"
                            as-child
                        >
                            <Link
                                :href="projectHref(item.ref_id)"
                                class="gap-2"
                                data-test="project-switcher-item"
                            >
                                <FolderKanban class="size-4" />
                                <span class="flex-1 truncate">{{
                                    item.name
                                }}</span>
                                <Check
                                    v-if="item.ref_id === project?.ref_id"
                                    class="size-4"
                                />
                            </Link>
                        </DropdownMenuItem>
                    </template>

                    <DropdownMenuSeparator />
                    <DropdownMenuItem v-if="project" as-child>
                        <Link :href="edit(project.ref_id)" class="gap-2">
                            <Settings class="size-4" />
                            Project settings
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="gap-2"
                        data-test="new-project"
                        @select="newProject"
                    >
                        <Plus class="size-4" />
                        New project
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>

    <NameDialog
        v-model:open="nameOpen"
        title="New project"
        submit-label="Create"
        :busy="busy"
        @submit="create"
    />
</template>
