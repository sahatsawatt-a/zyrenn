<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bot, Plus } from '@lucide/vue';
import RoomList from '@/components/chat/RoomList.vue';
import EmptyState from '@/components/folders/EmptyState.vue';
import PageHeader from '@/components/folders/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { index, store } from '@/routes/chats';
import { index as connectionsIndex } from '@/routes/ai-connections';

type Room = {
    ref_id: string;
    title: string;
    updated_at: string;
    messages: number;
    // Who answers, or null for a room that is only a log
    agent: string | null;
};

defineProps<{ rooms: Room[]; hasConnections: boolean }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'AI chat', href: index() }] },
});

const start = () => router.post(store().url);
</script>

<template>
    <Head title="AI chat" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
        <PageHeader
            :icon="Bot"
            title="AI chat"
            description="Rooms where you talk with a model of your own, or just keep notes to yourself. Talking with people is under Messages."
        >
            <Button @click="start"><Plus /> New chat</Button>
        </PageHeader>

        <p
            v-if="!hasConnections"
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

        <EmptyState v-if="!rooms.length" :icon="Bot" title="No chats yet">
            <p class="text-muted-foreground text-sm">
                Start one to ask your model something.
            </p>
        </EmptyState>

        <RoomList
            v-else
            :icon="Bot"
            :rooms="
                rooms.map((room) => ({
                    ...room,
                    detail: `${room.agent ?? 'No agent'} · ${room.messages} ${room.messages === 1 ? 'message' : 'messages'}`,
                }))
            "
        />
    </div>
</template>
