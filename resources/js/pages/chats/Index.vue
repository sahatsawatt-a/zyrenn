<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bot,
    Hash,
    MessageCircle,
    MessagesSquare,
    Plus,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import PeoplePicker from '@/features/chat/components/PeoplePicker.vue';
import type { Person } from '@/features/chat/components/PeoplePicker.vue';
import RoomList from '@/features/chat/components/RoomList.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    index,
    store as startRoom,
    direct as startDirect,
} from '@/routes/chats';
import { chat as projectChat } from '@/routes/projects';
import { index as connectionsIndex } from '@/routes/connections';

type Room = {
    ref_id: string;
    title: string;
    updated_at: string;
    messages: number;
    project?: string | null;
    agent?: string | null;
    unread?: number;
    last?: string | null;
    linked?: string | null;
    picture?: string | null;
};

const props = defineProps<{
    personal: Room[];
    direct: Room[];
    groups: Room[];
    people: Person[];
    projects: { ref_id: string; name: string }[];
    hasConnections: boolean;
    // Whether one's own room may have an AI agent (CHAT_AGENTS); off, AI comes from workflows
    agents: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Chat', href: index() }] },
});

const choosing = ref(false);

const newRoom = () => router.post(startRoom().url);

const message = (person: Person) => {
    choosing.value = false;
    router.post(startDirect().url, { user: person.id });
};

const count = (n: number) => `${n} ${n === 1 ? 'message' : 'messages'}`;
</script>

<template>
    <Head title="Chat" />

    <div class="mx-auto w-full max-w-4xl space-y-8 p-4 md:p-6">
        <PageHeader
            :icon="MessagesSquare"
            title="Chat"
            :description="
                agents
                    ? 'Rooms where you talk with a model of your own, one to one with people, or in a project\'s groups.'
                    : 'Rooms of your own that you and your workflows write in, one to one with people, or in a project\'s groups.'
            "
        >
            <div class="flex gap-2">
                <Button variant="outline" data-new-room @click="newRoom"
                    ><Plus class="mr-2 h-4 w-4" /> New Chat Room</Button
                >
                <Button @click="choosing = true"
                    ><Plus class="mr-2 h-4 w-4" /> New message</Button
                >
            </div>
        </PageHeader>

        <section class="space-y-3">
            <h2 class="text-sm font-medium">
                {{ agents ? 'Personal AI Chats' : 'Chat rooms' }}
            </h2>

            <p
                v-if="agents && !hasConnections && !personal.length"
                class="bg-muted/40 rounded-lg border px-4 py-3 text-sm"
            >
                Nothing answers yet.
                <Link
                    :href="connectionsIndex()"
                    class="text-primary underline underline-offset-4"
                >
                    Add a connection
                </Link>
                to your Ollama or an OpenRouter key first.
            </p>

            <RoomList
                v-if="personal.length"
                :icon="agents ? Bot : Hash"
                :rooms="
                    personal.map((room) => ({
                        ...room,
                        detail: agents
                            ? `${room.agent ?? 'No agent'} · ${count(room.messages)}`
                            : count(room.messages),
                    }))
                "
            />
            <p v-else class="text-muted-foreground text-sm">
                {{
                    agents
                        ? 'No AI chats yet. Start one to ask your model something.'
                        : 'No rooms yet. Make one to write in, or let a workflow make one -- a Telegram or LINE chat, say.'
                }}
            </p>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium">Direct messages</h2>
            <RoomList
                v-if="direct.length"
                :icon="MessageCircle"
                :rooms="
                    direct.map((room) => ({
                        ...room,
                        detail: count(room.messages),
                    }))
                "
            />
            <p v-else class="text-muted-foreground text-sm">
                No direct conversations yet. Start one with anyone you share a
                project with.
            </p>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium">Groups</h2>
            <RoomList
                v-if="groups.length"
                :icon="Users"
                :rooms="
                    groups.map((room) => ({
                        ...room,
                        detail: `${room.project} · ${count(room.messages)}`,
                    }))
                "
            />
            <p v-else class="text-muted-foreground text-sm">
                You are in no groups yet.
            </p>

            <div v-if="projects.length" class="flex flex-wrap gap-2 pt-1">
                <span class="text-muted-foreground self-center text-xs">
                    Start or join one in
                </span>
                <Button
                    v-for="project in projects"
                    :key="project.ref_id"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="projectChat(project.ref_id)">
                        {{ project.name }}
                    </Link>
                </Button>
            </div>
            <p v-else class="text-muted-foreground text-xs">
                Groups live in projects: make or join a project to have one.
            </p>
        </section>

        <Dialog v-model:open="choosing">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New message</DialogTitle>
                    <DialogDescription>
                        Anyone you share a project with.
                    </DialogDescription>
                </DialogHeader>
                <PeoplePicker :people="people" @pick="message" />
            </DialogContent>
        </Dialog>
    </div>
</template>
