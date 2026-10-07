<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { MessageCircle, MessagesSquare, Plus, Users } from '@lucide/vue';
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
import { direct as startDirect } from '@/routes/chats';
import { index } from '@/routes/messages';
import { chat as projectChat } from '@/routes/projects';

type Room = {
    ref_id: string;
    title: string;
    project: string | null;
    updated_at: string;
    messages: number;
    unread: number;
};

defineProps<{
    direct: Room[];
    groups: Room[];
    // Who can be messaged: everyone shared a project with
    people: Person[];
    projects: { ref_id: string; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Messages', href: index() }] },
});

const choosing = ref(false);

const message = (person: Person) => {
    choosing.value = false;
    router.post(startDirect().url, { user: person.id });
};

const count = (n: number) => `${n} ${n === 1 ? 'message' : 'messages'}`;
</script>

<template>
    <Head title="Messages" />

    <div class="mx-auto w-full max-w-4xl space-y-8 p-4 md:p-6">
        <PageHeader
            :icon="MessagesSquare"
            title="Messages"
            description="Talk with the people you share a project with, one to one or in a project's groups."
        >
            <Button @click="choosing = true"><Plus /> New message</Button>
        </PageHeader>

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
            <EmptyState
                v-else
                :icon="MessageCircle"
                title="No conversations yet"
            >
                <p class="text-muted-foreground text-sm">
                    Start one with anyone you share a project with.
                </p>
            </EmptyState>
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
