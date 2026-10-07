<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Map as MapIcon,
    Plane,
    Table2,
    BookOpen,
    FolderGit2,
    HardDrive,
    LayoutDashboard,
    LayoutGrid,
    Bot,
    MessagesSquare,
    NotebookPen,
    FileSpreadsheet,
    ChartNoAxesCombined,
    Presentation,
    Settings,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';
import NavFooter from './NavFooter.vue';
import NavMain from './NavMain.vue';
import NavUser from './NavUser.vue';
import ProjectSwitcher from './ProjectSwitcher.vue';
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
import { useUnreadChats } from '@/composables/useUnreadChats';
import { owned } from '@/lib/projects';
import { index as boardsIndex } from '@/routes/boards';
import { index as chatsIndex } from '@/routes/chats';
import { index as driveIndex } from '@/routes/drive';
import { index as notesIndex } from '@/routes/notes';
import { index as messagesIndex } from '@/routes/messages';
import {
    chat as projectChat,
    dashboard as projectDashboard,
    edit as projectSettings,
} from '@/routes/projects';
import { index as projectBoardsIndex } from '@/routes/projects/boards';
import { index as projectDriveIndex } from '@/routes/projects/drive';
import { index as projectNotesIndex } from '@/routes/projects/notes';
import { index as mapsIndex } from '@/routes/maps';
import { index as projectMapsIndex } from '@/routes/projects/maps';
import { index as projectTablesIndex } from '@/routes/projects/tables';
import { index as projectTripsIndex } from '@/routes/projects/trips';
import { index as tablesIndex } from '@/routes/tables';
import { index as tripsIndex } from '@/routes/trips';
import type { NavItem } from '@/types';

const page = usePage();
const unread = useUnreadChats();

// Notes, boards, tables and Drive of the project the page is in, or your own
const mainNavItems = computed<NavItem[]>(() => {
    const project = page.props.project;

    return [
        {
            title: 'Dashboard',
            href: owned(dashboard, projectDashboard)(),
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
            title: 'Maps',
            href: owned(mapsIndex, projectMapsIndex)(),
            icon: MapIcon,
        },
        {
            title: 'Trips',
            href: owned(tripsIndex, projectTripsIndex)(),
            icon: Plane,
        },
        {
            title: 'Drive',
            href: owned(driveIndex, projectDriveIndex)(),
            icon: HardDrive,
        },
        // A project's groups; in your own space, your AI chats and your
        // messages, kept apart: an AI chat answers through your own key
        ...(project
            ? [
                  {
                      title: 'Chat',
                      href: projectChat(project.ref_id),
                      icon: MessagesSquare,
                      badge: unread.value,
                  },
              ]
            : [
                  {
                      title: 'AI chat',
                      href: chatsIndex(),
                      icon: Bot,
                  },
                  {
                      title: 'Messages',
                      href: messagesIndex(),
                      icon: MessagesSquare,
                      badge: unread.value,
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
