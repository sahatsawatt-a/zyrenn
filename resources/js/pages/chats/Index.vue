<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { MessageSquare, Plus } from '@lucide/vue';
import EmptyState from '@/components/folders/EmptyState.vue';
import PageHeader from '@/components/folders/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { formatRelativeTime } from '@/lib/utils';
import { index, show, store } from '@/routes/chats';
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
    layout: { breadcrumbs: [{ title: 'Chat', href: index() }] },
});

const start = () => router.post(store().url);
</script>

<template>
    <Head title="Chat" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
        <PageHeader
            :icon="MessageSquare"
            title="Chat"
            description="Rooms where you talk with a model of your own, or just keep notes to yourself."
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

        <EmptyState
            v-if="!rooms.length"
            :icon="MessageSquare"
            title="No chats yet"
        >
            <p class="text-muted-foreground text-sm">
                Start one to ask your model something.
            </p>
        </EmptyState>

        <ul v-else class="divide-y rounded-xl border">
            <li v-for="room in rooms" :key="room.ref_id">
                <Link
                    :href="show(room.ref_id)"
                    class="hover:bg-accent/50 flex items-center gap-3 px-4 py-3 transition-colors"
                >
                    <MessageSquare
                        class="text-muted-foreground size-4 shrink-0"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">
                            {{ room.title || 'New chat' }}
                        </p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ room.agent ?? 'No agent' }} · {{ room.messages }}
                            {{ room.messages === 1 ? 'message' : 'messages' }}
                        </p>
                    </div>
                    <span class="text-muted-foreground shrink-0 text-xs">
                        {{ formatRelativeTime(room.updated_at) }}
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
