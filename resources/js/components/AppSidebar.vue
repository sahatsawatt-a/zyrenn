<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    FolderGit2,
    HardDrive,
    LayoutDashboard,
    LayoutGrid,
    NotebookPen,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, template_sample, tiptap_demo, konva_demo } from '@/routes';
import { index as boardsIndex } from '@/routes/boards';
import { index as driveIndex } from '@/routes/drive';
import { index as notesIndex } from '@/routes/notes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Notes',
        href: notesIndex(),
        icon: NotebookPen,
    },
    {
        title: 'Boards',
        href: boardsIndex(),
        icon: LayoutDashboard,
    },
    {
        title: 'Drive',
        href: driveIndex(),
        icon: HardDrive,
    },
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
];

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
