<script setup lang="ts">
import { HardDrive, LoaderCircle, Paperclip, Search } from '@lucide/vue';
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
import { listDriveMedia } from '@/lib/drive';
import type { DriveFile } from '@/lib/drive';
import { files as roomFiles } from '@/routes/chats';

// A file already in the Drive, chosen by name. For a chat, the Drive is the
// chat's own -- one's own for one's own chat, the project's for a project's
// -- so everyone in the chat can open what is chosen. Without a chat, it is
// the Drive of wherever the page is, and only its pictures or its videos.

const open = defineModel<boolean>('open', { required: true });

const props = withDefaults(
    defineProps<{
        room?: string;
        // Without a chat: which of the Drive's files to offer
        kind?: 'image' | 'video' | 'any';
        title?: string;
        description?: string;
    }>(),
    {
        room: undefined,
        kind: 'image',
        title: 'Send a file from the Drive',
        description:
            'The newest first. Everyone in this chat can open what you choose.',
    },
);

const emit = defineEmits<{ (e: 'pick', file: DriveFile): void }>();

const found = ref<DriveFile[]>([]);
const loading = ref(false);
const failed = ref(false);
const query = ref('');

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        if (props.room === undefined) {
            found.value = await listDriveMedia(props.kind, query.value.trim());

            return;
        }

        const response = await fetch(
            roomFiles(props.room, {
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

        found.value = (await response.json()).files;
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

function pick(file: DriveFile) {
    emit('pick', file);
    open.value = false;
}

const sizeOf = (bytes: number) =>
    bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-chat-drive>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <div class="relative">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="query"
                    placeholder="Search by name"
                    class="pl-9"
                />
            </div>

            <div class="h-80 overflow-y-auto">
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
                    Couldn’t load the Drive.
                </p>
                <div
                    v-else-if="!found.length"
                    class="text-muted-foreground flex h-full flex-col items-center justify-center gap-2 text-sm"
                >
                    <HardDrive class="size-8" />
                    {{
                        query ? 'No file matches.' : 'Nothing in the Drive yet.'
                    }}
                </div>
                <ul v-else class="grid gap-1">
                    <li v-for="file in found" :key="file.ref_id">
                        <button
                            type="button"
                            class="hover:bg-muted flex w-full items-center gap-3 rounded-lg p-2 text-left"
                            :data-drive-file="file.name"
                            @click="pick(file)"
                        >
                            <img
                                v-if="file.is_image"
                                :src="file.url"
                                alt=""
                                loading="lazy"
                                class="bg-muted size-10 shrink-0 rounded-md object-cover"
                            />
                            <span
                                v-else
                                class="bg-muted text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-md"
                            >
                                <Paperclip class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm">{{
                                    file.name
                                }}</span>
                                <span class="text-muted-foreground text-xs">{{
                                    sizeOf(file.size)
                                }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
