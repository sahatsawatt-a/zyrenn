<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CheckSquare,
    FolderKanban,
    HardDrive,
    History,
    LayoutDashboard,
    MessageCircle,
    MessagesSquare,
    NotebookPen,
    Plus,
    Presentation,
    Square,
    Table2,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import Panel from '@/components/dashboard/Panel.vue';
import PageHeader from '@/components/folders/PageHeader.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { getInitials } from '@/composables/useInitials';
import { canChange, owned } from '@/lib/projects';
import { formatBytes, formatRelativeTime } from '@/lib/utils';
import { dashboard } from '@/routes';
import * as boards from '@/routes/boards';
import { show as chatRoom } from '@/routes/chats';
import { index as driveIndex } from '@/routes/drive';
import { index as messagesIndex } from '@/routes/messages';
import * as notes from '@/routes/notes';
import {
    chat as projectChat,
    dashboard as projectDashboard,
    edit as projectSettings,
} from '@/routes/projects';
import * as projectBoards from '@/routes/projects/boards';
import { index as projectDriveIndex } from '@/routes/projects/drive';
import * as projectNotes from '@/routes/projects/notes';
import * as projectTables from '@/routes/projects/tables';
import * as tables from '@/routes/tables';

type Kind = 'note' | 'board' | 'table';

type Recent = {
    kind: Kind;
    ref_id: string;
    title: string | null;
    updated_at: string;
    edited_by?: string | null;
    project?: { ref_id: string; name: string } | null;
};

const props = defineProps<{
    counts: {
        notes: number;
        boards: number;
        tables: number;
        files: number;
        bytes: number;
    };
    recent: Recent[];
    todos: {
        items: { text: string; note: { ref_id: string; title: string } }[];
        total: number;
    };
    chats: {
        ref_id: string;
        kind: 'direct' | 'group';
        title: string;
        project: string | null;
        unread: number;
        updated_at: string;
    }[];
    // A project's
    members?: { name: string; role: string; is_me: boolean }[];
    // One's own (not "projects": every page shares that, for the switcher)
    yourProjects?: {
        ref_id: string;
        name: string;
        role: string;
        members: number;
        changed_at: string | null;
    }[];
    fromProjects?: Recent[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const page = usePage();
const project = computed(() => page.props.project);
const firstName = computed(
    () => page.props.auth.user.name.trim().split(/\s+/)[0],
);

const greeting = computed(() => {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Good morning'
        : hour < 18
          ? 'Good afternoon'
          : 'Good evening';
});

// What each kind is called, looks like, opens with and is made with
const kinds: Record<
    Kind,
    {
        label: string;
        icon: Component;
        show: (ref: string) => ReturnType<typeof notes.show>;
        store: { url: () => string };
    }
> = {
    note: {
        label: 'note',
        icon: NotebookPen,
        show: notes.show,
        store: owned(notes.store, projectNotes.store),
    },
    board: {
        label: 'board',
        icon: Presentation,
        show: boards.show,
        store: owned(boards.store, projectBoards.store),
    },
    table: {
        label: 'table',
        icon: Table2,
        show: tables.show,
        store: owned(tables.store, projectTables.store),
    },
};

const make = (kind: Kind) => router.post(kinds[kind].store.url());

const plural = (n: number, word: string) =>
    `${n.toLocaleString()} ${word}${n === 1 ? '' : 's'}`;

const tiles = computed(() => [
    {
        label: 'Notes',
        icon: NotebookPen,
        value: props.counts.notes,
        href: owned(notes.index, projectNotes.index)(),
    },
    {
        label: 'Boards',
        icon: Presentation,
        value: props.counts.boards,
        href: owned(boards.index, projectBoards.index)(),
    },
    {
        label: 'Tables',
        icon: Table2,
        value: props.counts.tables,
        href: owned(tables.index, projectTables.index)(),
    },
    {
        label: 'Drive',
        icon: HardDrive,
        value: props.counts.files,
        detail: formatBytes(props.counts.bytes),
        href: owned(driveIndex, projectDriveIndex)(),
    },
]);

const roleLabel = (role: string) =>
    role.charAt(0).toUpperCase() + role.slice(1);

const description = computed(() =>
    project.value
        ? `${roleLabel(project.value.role ?? 'member')} · ${plural(props.members?.length ?? 0, 'member')}`
        : "Here's what's happening across your space.",
);
</script>

<template>
    <Head :title="project ? `${project.name} · Dashboard` : 'Dashboard'" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <PageHeader
            :icon="project ? FolderKanban : LayoutDashboard"
            :title="project ? project.name : `${greeting}, ${firstName}`"
            :description="description"
        >
            <template v-if="canChange()">
                <Button
                    v-for="(kind, key) in kinds"
                    :key="key"
                    variant="outline"
                    size="sm"
                    :data-test="`new-${key}`"
                    @click="make(key)"
                >
                    <Plus /> New {{ kind.label }}
                </Button>
            </template>
        </PageHeader>

        <!-- What is here -->
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-test="tiles">
            <Link
                v-for="tile in tiles"
                :key="tile.label"
                :href="tile.href"
                class="group bg-card hover:border-primary/40 flex items-center gap-3 rounded-xl border p-4 transition-all hover:shadow-sm"
            >
                <div
                    class="bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary flex size-10 shrink-0 items-center justify-center rounded-lg transition-colors"
                >
                    <component :is="tile.icon" class="size-5" />
                </div>
                <div class="min-w-0">
                    <p class="text-2xl leading-none font-semibold tabular-nums">
                        {{ tile.value.toLocaleString() }}
                    </p>
                    <p class="text-muted-foreground mt-1 truncate text-xs">
                        {{ tile.label
                        }}<template v-if="tile.detail">
                            · {{ tile.detail }}</template
                        >
                    </p>
                </div>
            </Link>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Panel
                    :icon="History"
                    :title="
                        project
                            ? 'Recent activity'
                            : 'Pick up where you left off'
                    "
                    data-test="recent"
                >
                    <Link
                        v-for="item in recent"
                        :key="`${item.kind}-${item.ref_id}`"
                        :href="kinds[item.kind].show(item.ref_id)"
                        class="hover:bg-muted/60 flex items-center gap-3 rounded-lg px-2.5 py-2"
                    >
                        <component
                            :is="kinds[item.kind].icon"
                            class="text-muted-foreground size-4 shrink-0"
                        />
                        <span
                            class="min-w-0 flex-1 truncate text-sm"
                            :class="{ 'text-muted-foreground': !item.title }"
                        >
                            {{ item.title || 'Untitled' }}
                        </span>
                        <span
                            class="text-muted-foreground flex shrink-0 items-center gap-1.5 text-xs"
                        >
                            <template v-if="item.edited_by">
                                <span class="max-w-32 truncate">{{
                                    item.edited_by
                                }}</span>
                                <span aria-hidden="true">·</span>
                            </template>
                            <time :datetime="item.updated_at">
                                {{ formatRelativeTime(item.updated_at) }}
                            </time>
                        </span>
                    </Link>
                    <p
                        v-if="!recent.length"
                        class="text-muted-foreground px-2.5 py-6 text-center text-sm"
                    >
                        Nothing here yet. Make a note, board or table to get
                        started.
                    </p>
                </Panel>

                <Panel
                    v-if="fromProjects"
                    :icon="FolderKanban"
                    title="Changed in your projects"
                    data-test="from-projects"
                >
                    <Link
                        v-for="item in fromProjects"
                        :key="`${item.kind}-${item.ref_id}`"
                        :href="kinds[item.kind].show(item.ref_id)"
                        class="hover:bg-muted/60 flex items-center gap-3 rounded-lg px-2.5 py-2"
                    >
                        <component
                            :is="kinds[item.kind].icon"
                            class="text-muted-foreground size-4 shrink-0"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-sm"
                                :class="{
                                    'text-muted-foreground': !item.title,
                                }"
                            >
                                {{ item.title || 'Untitled' }}
                            </span>
                            <span
                                class="text-muted-foreground truncate text-xs"
                            >
                                {{ item.edited_by }} in
                                {{ item.project?.name }}
                            </span>
                        </span>
                        <time
                            :datetime="item.updated_at"
                            class="text-muted-foreground shrink-0 text-xs"
                        >
                            {{ formatRelativeTime(item.updated_at) }}
                        </time>
                    </Link>
                    <p
                        v-if="!fromProjects.length"
                        class="text-muted-foreground px-2.5 py-6 text-center text-sm"
                    >
                        When people change things in your projects, they show up
                        here.
                    </p>
                </Panel>
            </div>

            <div class="flex flex-col gap-6">
                <Panel
                    :icon="CheckSquare"
                    title="Open to-dos"
                    :count="todos.total"
                    data-test="todos"
                >
                    <Link
                        v-for="(todo, index) in todos.items"
                        :key="index"
                        :href="notes.show(todo.note.ref_id)"
                        class="hover:bg-muted/60 flex gap-2.5 rounded-lg px-2.5 py-2"
                    >
                        <Square
                            class="text-muted-foreground mt-0.5 size-4 shrink-0"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="line-clamp-2 text-sm">{{
                                todo.text
                            }}</span>
                            <span
                                class="text-muted-foreground block truncate text-xs"
                            >
                                {{ todo.note.title || 'Untitled' }}
                            </span>
                        </span>
                    </Link>
                    <p
                        v-if="todos.total > todos.items.length"
                        class="text-muted-foreground px-2.5 py-1.5 text-xs"
                    >
                        and {{ todos.total - todos.items.length }} more
                    </p>
                    <p
                        v-if="!todos.items.length"
                        class="text-muted-foreground px-2.5 py-6 text-center text-sm"
                    >
                        No open to-dos. Add a to-do list to a note with
                        <kbd class="bg-muted rounded px-1">/to-do</kbd>.
                    </p>
                </Panel>

                <Panel
                    :icon="MessagesSquare"
                    :title="project ? 'Chat' : 'Messages'"
                    :href="
                        project ? projectChat(project.ref_id) : messagesIndex()
                    "
                    :href-label="project ? 'Open chat' : 'All messages'"
                    data-test="chats"
                >
                    <Link
                        v-for="room in chats"
                        :key="room.ref_id"
                        :href="chatRoom(room.ref_id)"
                        class="hover:bg-muted/60 flex items-center gap-2.5 rounded-lg px-2.5 py-2"
                    >
                        <component
                            :is="room.kind === 'direct' ? MessageCircle : Users"
                            class="text-muted-foreground size-4 shrink-0"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-sm"
                                :class="{ 'font-medium': room.unread }"
                                >{{ room.title }}</span
                            >
                            <span
                                v-if="room.project"
                                class="text-muted-foreground truncate text-xs"
                                >{{ room.project }}</span
                            >
                        </span>
                        <span
                            v-if="room.unread"
                            class="bg-primary text-primary-foreground rounded-full px-1.5 text-xs tabular-nums"
                        >
                            {{ room.unread > 99 ? '99+' : room.unread }}
                        </span>
                        <time
                            v-else
                            :datetime="room.updated_at"
                            class="text-muted-foreground shrink-0 text-xs"
                        >
                            {{ formatRelativeTime(room.updated_at) }}
                        </time>
                    </Link>
                    <p
                        v-if="!chats.length"
                        class="text-muted-foreground px-2.5 py-6 text-center text-sm"
                    >
                        {{
                            project
                                ? 'No groups of yours here yet.'
                                : 'No conversations yet.'
                        }}
                    </p>
                </Panel>

                <Panel
                    v-if="yourProjects"
                    :icon="FolderKanban"
                    title="Projects"
                    :count="yourProjects.length"
                    data-test="projects"
                >
                    <Link
                        v-for="item in yourProjects"
                        :key="item.ref_id"
                        :href="projectDashboard(item.ref_id)"
                        class="hover:bg-muted/60 flex items-center gap-2.5 rounded-lg px-2.5 py-2"
                    >
                        <div
                            class="bg-primary/10 text-primary flex size-7 shrink-0 items-center justify-center rounded-md text-xs font-semibold"
                        >
                            {{ getInitials(item.name) }}
                        </div>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-sm">{{
                                item.name
                            }}</span>
                            <span
                                class="text-muted-foreground truncate text-xs"
                            >
                                {{ roleLabel(item.role) }} ·
                                {{ plural(item.members, 'member') }}
                            </span>
                        </span>
                        <time
                            v-if="item.changed_at"
                            :datetime="item.changed_at"
                            class="text-muted-foreground shrink-0 text-xs"
                        >
                            {{ formatRelativeTime(item.changed_at) }}
                        </time>
                    </Link>
                    <p
                        v-if="!yourProjects.length"
                        class="text-muted-foreground px-2.5 py-6 text-center text-sm"
                    >
                        Make a project from the switcher at the top of the
                        sidebar to work with others.
                    </p>
                </Panel>

                <Panel
                    v-if="members && project"
                    :icon="Users"
                    title="Members"
                    :count="members.length"
                    :href="projectSettings(project.ref_id)"
                    href-label="Manage"
                    data-test="members"
                >
                    <div
                        v-for="(member, index) in members"
                        :key="index"
                        class="flex items-center gap-2.5 px-2.5 py-1.5"
                    >
                        <Avatar class="size-7">
                            <AvatarFallback class="text-xs">
                                {{ getInitials(member.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="min-w-0 flex-1 truncate text-sm">
                            {{ member.name }}
                            <span
                                v-if="member.is_me"
                                class="text-muted-foreground"
                                >(you)</span
                            >
                        </span>
                        <Badge variant="secondary" class="font-normal">
                            {{ roleLabel(member.role) }}
                        </Badge>
                    </div>
                </Panel>
            </div>
        </div>
    </div>
</template>
