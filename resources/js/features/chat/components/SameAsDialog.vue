<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, MessageCircle, Search, UserRound } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { SERVICES } from '@/features/chat/desk';
import { candidates, join } from '@/routes/chats/contact';

// Another of the project's chats with customers that is the same person as
// this one, chosen by its name or theirs: their chats then show each other,
// and share what is noted about them.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{ room: string; name: string }>();

type Candidate = {
    ref_id: string;
    title: string;
    service: string | null;
    contact: string | null;
};

const found = ref<Candidate[]>([]);
const loading = ref(false);
const failed = ref(false);
const query = ref('');

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            candidates(props.room, {
                query: query.value.trim() ? { q: query.value.trim() } : {},
            }).url,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (!response.ok) {
            throw new Error('not loaded');
        }

        found.value = (await response.json()).rooms;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

const later = useDebounceFn(load, 250);
watch(query, () => void later());

// Fresh each time it opens
watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        found.value = [];
        void load();
    }
});

function pick(other: Candidate) {
    router.post(
        join(props.room).url,
        { room: other.ref_id },
        {
            preserveScroll: true,
            only: ['room'],
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-chat-same>
            <DialogHeader>
                <DialogTitle>The same person as…</DialogTitle>
                <DialogDescription>
                    {{ name }}'s chats then show each other, and share what is
                    noted about them.
                </DialogDescription>
            </DialogHeader>

            <div class="relative">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="query"
                    placeholder="Search by name"
                    class="pl-9"
                    data-test="same-search"
                />
            </div>

            <div class="h-72 overflow-y-auto">
                <div
                    v-if="loading && !found.length"
                    class="flex h-full items-center justify-center"
                >
                    <LoaderCircle
                        class="text-muted-foreground size-6 animate-spin"
                    />
                </div>
                <p
                    v-else-if="failed"
                    class="text-destructive flex h-full items-center justify-center text-sm"
                    role="alert"
                >
                    Couldn’t load the project's chats.
                </p>
                <div
                    v-else-if="!found.length"
                    class="text-muted-foreground flex h-full flex-col items-center justify-center gap-2 text-sm"
                >
                    <UserRound class="size-8" />
                    {{
                        query
                            ? 'No chat matches.'
                            : 'No other chat with a customer yet.'
                    }}
                </div>
                <ul v-else class="grid gap-1">
                    <li v-for="other in found" :key="other.ref_id">
                        <button
                            type="button"
                            class="hover:bg-muted flex w-full items-center gap-3 rounded-lg p-2 text-left"
                            data-test="same-candidate"
                            @click="pick(other)"
                        >
                            <span
                                class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-full"
                            >
                                <MessageCircle class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm">{{
                                    other.title
                                }}</span>
                                <span
                                    class="text-muted-foreground block truncate text-xs"
                                    >{{
                                        [
                                            other.service
                                                ? (SERVICES[other.service] ??
                                                  other.service)
                                                : null,
                                            other.contact &&
                                            other.contact !== other.title
                                                ? other.contact
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}</span
                                >
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
