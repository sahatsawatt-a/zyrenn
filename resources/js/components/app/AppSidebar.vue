<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Map as MapIcon,
    Plane,
    Table2,
    BookOpen,
    BookOpenText,
    FolderGit2,
    HardDrive,
    Inbox,
    ListTree,
    LayoutDashboard,
    LayoutGrid,
    Bot,
    MessagesSquare,
    MonitorCheck,
    NotebookPen,
    FileSpreadsheet,
    ChartNoAxesCombined,
    Presentation,
    Settings,
    SquareCode,
    TestTubes,
    Ticket,
    Workflow,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onBeforeUnmount } from 'vue';
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
    useSidebar,
} from '@/components/ui/sidebar';
import {
    dashboard,
    guide,
    template_sample,
    tiptap_demo,
    konva_demo,
    table_demo,
} from '@/routes';
import { inboxChanges, useUnreadChats } from '@/composables/useUnreadChats';
import { owned } from '@/lib/projects';
import { page as codeAdminPage } from '@/routes/admin/code';
import { page as testRunsPage } from '@/routes/admin/test-runs';
import { index as boardsIndex } from '@/routes/boards';
import { index as checksIndex } from '@/routes/checks';
import { index as chatsIndex } from '@/routes/chats';
import { index as driveIndex } from '@/routes/drive';
import { index as notesIndex } from '@/routes/notes';
import {
    chat as projectChat,
    inbox as projectInbox,
    dashboard as projectDashboard,
    edit as projectSettings,
} from '@/routes/projects';
import { index as projectBoardsIndex } from '@/routes/projects/boards';
import { index as projectChecksIndex } from '@/routes/projects/checks';
import { index as projectDriveIndex } from '@/routes/projects/drive';
import { index as projectNotesIndex } from '@/routes/projects/notes';
import { index as mapsIndex } from '@/routes/maps';
import { index as projectMapsIndex } from '@/routes/projects/maps';
import { index as projectTablesIndex } from '@/routes/projects/tables';
import { index as projectTicketsIndex } from '@/routes/projects/tickets';
import { index as projectTripsIndex } from '@/routes/projects/trips';
import { index as projectWorkflowsIndex } from '@/routes/projects/workflows';
import { index as tablesIndex } from '@/routes/tables';
import { index as tripsIndex } from '@/routes/trips';
import { index as workflowsIndex } from '@/routes/workflows';
import { index as knowledgeIndex } from '@/routes/knowledge';
import { index as agentsIndex } from '@/routes/agents';
import { index as projectAgentsIndex } from '@/routes/projects/agents';
import { index as projectProduct } from '@/routes/projects/product';
import { index as projectKnowledgeIndex } from '@/routes/projects/knowledge';
import type { NavItem } from '@/types';

const page = usePage();
const unread = useUnreadChats();

// On a phone the menu is a sheet over the page, and it stays across visits
// with the layout: a page chosen in it -- or reached any other way -- closes it
const { isMobile, setOpenMobile } = useSidebar();
const stopClosing = router.on('navigate', () => {
    if (isMobile.value) {
        setOpenMobile(false);
    }
});
onBeforeUnmount(stopClosing);

// How many of the project's chats with customers are to be answered: asked for again, a moment
// after one is written in or changed, so a run of them asks once
watchDebounced(
    inboxChanges,
    () => page.props.project && router.reload({ only: ['project'] }),
    { debounce: 400 },
);

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
        {
            title: 'Workflows',
            href: owned(workflowsIndex, projectWorkflowsIndex)(),
            icon: Workflow,
        },
        // Checks of a site, done in a real browser: tested, or filmed for a demo
        {
            title: 'Browser checks',
            href: owned(checksIndex, projectChecksIndex)(),
            icon: MonitorCheck,
        },
        // Who answers: a model with its instructions, every version kept
        {
            title: 'Agents',
            href: owned(agentsIndex, projectAgentsIndex)(),
            icon: Bot,
        },
        // What AI answers from
        {
            title: 'Knowledge',
            href: owned(knowledgeIndex, projectKnowledgeIndex)(),
            icon: BookOpenText,
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
                      title: 'Chat',
                      href: chatsIndex(),
                      icon: MessagesSquare,
                      badge: unread.value,
                  },
              ]),
        // Its chats with customers, from every service they write on: a project's alone
        ...(project
            ? [
                  {
                      title: 'Inbox',
                      href: projectInbox(project.ref_id),
                      icon: Inbox,
                      badge: project.inbox,
                  },
              ]
            : []),
        // What the product is, what was asked of it, what it must do: a project's alone
        ...(project
            ? [
                  {
                      title: 'Product',
                      href: projectProduct(project.ref_id),
                      icon: ListTree,
                  },
              ]
            : []),
        // What the project has been asked to fix or add: a project's alone
        ...(project
            ? [
                  {
                      title: 'Tickets',
                      href: projectTicketsIndex(project.ref_id),
                      icon: Ticket,
                  },
              ]
            : []),
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

const footerNavItems = computed<NavItem[]>(() => [
    {
        title: 'Repository',
        href: 'https://github.com/sahatsawatt-a/zyrenn',
        icon: FolderGit2,
    },
    {
        title: 'Guide',
        href: guide(),
        icon: BookOpen,
    },
    // About the app itself, for whoever runs it: apart from anyone's own pages
    ...(page.props.auth.user?.is_admin
        ? [
              { title: 'Test runs', href: testRunsPage(), icon: TestTubes },
              { title: 'Code', href: codeAdminPage(), icon: SquareCode },
          ]
        : []),
]);
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
