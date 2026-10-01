<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Table2,
    BookOpen,
    FolderGit2,
    HardDrive,
    LayoutDashboard,
    LayoutGrid,
    MessageSquare,
    NotebookPen,
    FileSpreadsheet,
    ChartNoAxesCombined,
    Presentation,
    Settings,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import ProjectSwitcher from '@/components/ProjectSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    dashboard,
    template_sample,
    tiptap_demo,
    konva_demo,
    table_demo,
} from '@/routes';
import { owned } from '@/lib/projects';
import { index as boardsIndex } from '@/routes/boards';
import { index as chatsIndex } from '@/routes/chats';
import { index as driveIndex } from '@/routes/drive';
import { index as notesIndex } from '@/routes/notes';
import { edit as projectSettings } from '@/routes/projects';
import { index as projectBoardsIndex } from '@/routes/projects/boards';
import { index as projectDriveIndex } from '@/routes/projects/drive';
import { index as projectNotesIndex } from '@/routes/projects/notes';
import { index as projectTablesIndex } from '@/routes/projects/tables';
import { index as tablesIndex } from '@/routes/tables';
import type { NavItem } from '@/types';

const page = usePage();

// Notes, boards, tables and Drive of the project the page is in, or your own
const mainNavItems = computed<NavItem[]>(() => {
    const project = page.props.project;

    return [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: ChartNoAxesCombined,
        },
        {
            title: 'Notes',
            href: owned(notesIndex, projectNotesIndex)(),
            icon: NotebookPen,
        },
        {
            title: 'Boards',
            href: owned(boardsIndex, projectBoardsIndex)(),
            icon: Presentation,
        },
        {
            title: 'Tables',
            href: owned(tablesIndex, projectTablesIndex)(),
            icon: Table2,
        },
        {
            title: 'Drive',
            href: owned(driveIndex, projectDriveIndex)(),
            icon: HardDrive,
        },
        // Only in your own space: a chat answers through your own key, so it is
        // not something a project shares
        ...(project
            ? []
            : [
                  {
                      title: 'Chat',
                      href: chatsIndex(),
                      icon: MessageSquare,
                  },
              ]),
        ...(project
            ? [
                  {
                      title: 'Project settings',
                      href: projectSettings(project.ref_id),
                      icon: Settings,
                  },
              ]
            : []),
        // {
        //     title: 'Template Sample',
        //     href: template_sample(),
        //     icon: LayoutGrid,
        // },
        // {
        //     title: 'Tiptap Demo',
        //     href: tiptap_demo(),
        //     icon: LayoutGrid,
        // },
        // {
        //     title: 'Konva Demo',
        //     href: konva_demo(),
        //     icon: LayoutGrid,
        // },
        // {
        //     title: 'Table Demo',
        //     href: table_demo(),
        //     icon: FileSpreadsheet,
        // },
    ];
});

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/sahatsawatt-a/zyrenn',
        icon: FolderGit2,
    },
    // {
    //     title: 'Documentation',
    //     href: '',
    //     icon: BookOpen,
    // },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <ProjectSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
