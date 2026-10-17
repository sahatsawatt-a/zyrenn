<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    MessageCircle,
    NotebookPen,
    Plus,
    Presentation,
    Table2,
    Users,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed } from 'vue';
import type { Component } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { useSpaceChanges } from '@/composables/useSpaceChanges';
import { chatChanges } from '@/composables/useUnreadChats';
import ActivityFeed from '@/features/dashboard/components/ActivityFeed.vue';
import type {
    Glance,
    ProjectGlance as ProjectGlanceData,
} from '@/features/dashboard/components/glance';
import ProjectCard from '@/features/dashboard/components/ProjectCard.vue';
import ProjectGlance from '@/features/dashboard/components/ProjectGlance.vue';
import RecentCard from '@/features/dashboard/components/RecentCard.vue';
import WaitingList from '@/features/dashboard/components/WaitingList.vue';
import type { WaitingItem } from '@/features/dashboard/components/WaitingList.vue';
import type { Preview, RecentItem } from '@/features/dashboard/preview';
import { canChange, owned } from '@/lib/projects';
import { formatBytes, formatRelativeTime } from '@/lib/utils';
import { dashboard } from '@/routes';
import * as boards from '@/routes/boards';
import { index as chatsIndex, show as chatRoom } from '@/routes/chats';
import * as notes from '@/routes/notes';
import {
    chat as projectChat,
    dashboard as projectDashboard,
} from '@/routes/projects';
import * as projectBoards from '@/routes/projects/boards';
import * as projectNotes from '@/routes/projects/notes';
import * as projectTables from '@/routes/projects/tables';
import * as tables from '@/routes/tables';

// Where things stand, most pressing first: what waits on you; then each of
// your projects at a glance (on a project's own dashboard, that project in
// full, with what happened in it); then where you left off. The counts of
// what is here are one quiet line at the end.

type Kind = RecentItem['kind'];

const props = defineProps<{
    waiting: WaitingItem[];
    glance: Glance[] | ProjectGlanceData;
    previews: Record<string, Preview>;
    counts: {
        notes: number;
        boards: number;
        tables: number;
        files: number;
        bytes: number;
    };
    recent: RecentItem[];
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
}>();

// What is in the space, made, changed or taken away elsewhere, and its chats
// as they are written in, as they happen
useSpaceChanges(
    ['notes', 'boards', 'tables', 'drive', 'tickets', 'workflows'],
    ['counts', 'recent', 'previews', 'todos', 'waiting', 'glance'],
);
watchDebounced(
    chatChanges,
    () => router.reload({ only: ['chats', 'waiting'] }),
    { debounce: 400, maxWait: 2000 },
);

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

const today = new Date().toLocaleDateString('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

const waitingCount = computed(() => props.waiting.length + props.todos.total);

const subtitle = computed(() =>
    project.value
        ? `${(project.value.role ?? 'member').replace(/^./, (c) => c.toUpperCase())} · ${props.members?.length ?? 0} ${props.members?.length === 1 ? 'member' : 'members'}`
        : `${today} · ${waitingCount.value ? `${waitingCount.value} ${waitingCount.value === 1 ? 'thing waits' : 'things wait'} on you` : 'nothing waits on you'}`,
);

// Your projects, or this project in full
const projectCards = computed(() =>
    Array.isArray(props.glance) ? props.glance : [],
);
const shownCards = computed(() => projectCards.value.slice(0, 6));
const moreProjects = computed(() => projectCards.value.slice(6));
const projectGlance = computed(() =>
    Array.isArray(props.glance) ? null : props.glance,
);

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
        label: 'Note',
        icon: NotebookPen,
        show: notes.show,
        store: owned(notes.store, projectNotes.store),
    },
    board: {
        label: 'Board',
        icon: Presentation,
        show: boards.show,
        store: owned(boards.store, projectBoards.store),
    },
    table: {
        label: 'Table',
        icon: Table2,
        show: tables.show,
        store: owned(tables.store, projectTables.store),
    },
};

const make = (kind: Kind) => router.post(kinds[kind].store.url());

// What is here, as one quiet line
const quiet = computed(() => [
    { n: props.counts.notes, word: 'note' },
    { n: props.counts.boards, word: 'board' },
    { n: props.counts.tables, word: 'table' },
    {
        n: props.counts.files,
        word: 'file',
        after: `${formatBytes(props.counts.bytes)} in the Drive`,
    },
]);

const roleLabel = (role: string) =>
    role.charAt(0).toUpperCase() + role.slice(1);
</script>

<template>
    <Head :title="project ? `${project.name} · Dashboard` : 'Dashboard'" />

    <div
        class="mx-auto grid w-full max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 p-4 md:p-6"
    >
        <!-- Who and when, and making something -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="grid min-w-0 flex-1 gap-0.5">
                <h1 class="text-2xl font-bold tracking-tight text-balance">
                    {{ project ? project.name : `${greeting}, ${firstName}` }}
                </h1>
                <p class="text-muted-foreground text-sm">{{ subtitle }}</p>
            </div>
            <div v-if="project && members?.length" class="flex -space-x-2">
                <Avatar
                    v-for="member in members.slice(0, 5)"
                    :key="member.name"
                    class="ring-background size-8 ring-2"
                    :title="member.name"
                >
                    <AvatarFallback class="text-[11px]">{{
                        getInitials(member.name)
                    }}</AvatarFallback>
                </Avatar>
            </div>
            <DropdownMenu v-if="canChange()">
                <DropdownMenuTrigger as-child>
                    <Button data-test="new-menu"
                        ><Plus /> New <ChevronDown class="opacity-60"
                    /></Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="(kind, key) in kinds"
                        :key="key"
                        :data-test="`new-${key}`"
                        @select="make(key)"
                    >
                        <component :is="kind.icon" /> {{ kind.label }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <WaitingList :items="waiting" :todos="todos" />

        <!-- Your projects at a glance -->
        <section v-if="!project" data-test="projects">
            <h2 class="mb-2.5 text-[15px] font-semibold">Your projects</h2>
            <!-- Side by side where there is room; on a phone, a row to slide -->
            <div
                v-if="projectCards.length"
                class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 lg:grid-cols-3"
            >
                <ProjectCard
                    v-for="card in shownCards"
                    :key="card.ref_id"
                    class="w-[82%] shrink-0 snap-start sm:w-auto"
                    :project="card"
                />
            </div>
            <div
                v-if="moreProjects.length"
                class="mt-2.5 flex flex-wrap items-center gap-1.5 text-xs"
            >
                <span class="text-muted-foreground">Also:</span>
                <Link
                    v-for="other in moreProjects"
                    :key="other.ref_id"
                    :href="projectDashboard(other.ref_id)"
                    class="bg-muted hover:bg-accent rounded-full px-2.5 py-1"
                    >{{ other.name }}</Link
                >
            </div>
            <p
                v-if="!projectCards.length"
                class="bg-card text-muted-foreground rounded-xl border px-4 py-5 text-sm"
            >
                No projects yet. A project is a space to share notes, boards,
                tables and a Drive with others.
            </p>
        </section>

        <!-- This project at a glance, and what happened in it -->
        <template v-if="projectGlance">
            <ProjectGlance :glance="projectGlance" />

            <div class="grid items-start gap-6 lg:grid-cols-[1.5fr_1fr]">
                <ActivityFeed :lines="projectGlance.activity" />

                <div class="grid gap-6">
                    <section data-test="chats">
                        <h2
                            class="mb-2.5 flex items-center text-[15px] font-semibold"
                        >
                            Chat
                            <Link
                                :href="
                                    project
                                        ? projectChat(project.ref_id)
                                        : chatsIndex()
                                "
                                class="text-primary ml-auto text-xs font-medium"
                                >Open</Link
                            >
                        </h2>
                        <div class="bg-card divide-y rounded-xl border">
                            <Link
                                v-for="room in chats.slice(0, 3)"
                                :key="room.ref_id"
                                :href="chatRoom(room.ref_id)"
                                class="hover:bg-muted/50 flex min-w-0 items-center gap-2.5 px-3.5 py-2.5"
                            >
                                <component
                                    :is="
                                        room.kind === 'direct'
                                            ? MessageCircle
                                            : Users
                                    "
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                                <span
                                    class="min-w-0 flex-1 truncate text-sm"
                                    :class="{ 'font-medium': room.unread }"
                                    >{{ room.title }}</span
                                >
                                <span
                                    v-if="room.unread"
                                    class="bg-primary text-primary-foreground rounded-full px-1.5 text-xs tabular-nums"
                                    >{{
                                        room.unread > 99 ? '99+' : room.unread
                                    }}</span
                                >
                                <span
                                    v-else
                                    class="text-muted-foreground shrink-0 text-xs"
                                    >{{
                                        formatRelativeTime(room.updated_at)
                                    }}</span
                                >
                            </Link>
                            <p
                                v-if="!chats.length"
                                class="text-muted-foreground px-3.5 py-4 text-sm"
                            >
                                No groups of yours here yet.
                            </p>
                        </div>
                    </section>

                    <section v-if="members" data-test="members">
                        <h2 class="mb-2.5 text-[15px] font-semibold">
                            Members
                        </h2>
                        <div class="bg-card divide-y rounded-xl border">
                            <div
                                v-for="member in members"
                                :key="member.name"
                                class="flex items-center gap-2.5 px-3.5 py-2"
                            >
                                <Avatar class="size-7">
                                    <AvatarFallback class="text-[10px]">{{
                                        getInitials(member.name)
                                    }}</AvatarFallback>
                                </Avatar>
                                <span class="min-w-0 flex-1 truncate text-sm"
                                    >{{ member.name
                                    }}<span
                                        v-if="member.is_me"
                                        class="text-muted-foreground"
                                    >
                                        (you)</span
                                    ></span
                                >
                                <span class="text-muted-foreground text-xs">{{
                                    roleLabel(member.role)
                                }}</span>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </template>

        <!-- Where you left off -->
        <section v-if="recent.length" data-test="recent">
            <h2 class="mb-2.5 text-[15px] font-semibold">
                Pick up where you left off
            </h2>
            <div
                class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1 md:mx-0 md:grid md:grid-cols-4 md:overflow-visible md:px-0"
            >
                <RecentCard
                    v-for="item in recent"
                    :key="`${item.kind}:${item.ref_id}`"
                    class="w-[72%] shrink-0 snap-start md:w-auto"
                    :item="item"
                    :preview="previews[`${item.kind}:${item.ref_id}`] ?? null"
                    :href="kinds[item.kind].show(item.ref_id)"
                />
            </div>
        </section>

        <!-- What is here, quietly -->
        <p
            class="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs"
            data-test="counts"
        >
            <span v-for="line in quiet" :key="line.word"
                ><b class="text-foreground font-semibold tabular-nums">{{
                    line.n.toLocaleString()
                }}</b>
                {{ line.n === 1 ? line.word : `${line.word}s`
                }}<template v-if="line.after">
                    · {{ line.after }}</template
                ></span
            >
        </p>
    </div>
</template>
