<script setup lang="ts">
import {
    Download,
    ExternalLink,
    File as FileIcon,
    FileArchive,
    FileAudio,
    FileText,
    FileVideo,
    Folder,
    FolderInput,
    MoreHorizontal,
    Pencil,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import Highlight from '@/components/folders/Highlight.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { DriveFile } from '@/lib/drive';
import { formatBytes, formatRelativeTime } from '@/lib/utils';
import { show } from '@/routes/drive/files';

// A file in the Drive grid. Drag handlers bound on it fall through to the card.
const props = defineProps<{
    // `path` is only there in search results, which span every folder
    file: DriveFile & { path?: string | null };
    query: string;
    searching: boolean;
    dragging: boolean;
}>();

defineEmits<{ open: []; rename: []; move: []; remove: [] }>();

const downloadUrl = computed(() =>
    show.url(props.file.ref_id, { query: { download: 1 } }),
);

const icon = computed(
    () =>
        ({
            pdf: FileText,
            doc: FileText,
            audio: FileAudio,
            video: FileVideo,
            archive: FileArchive,
        })[props.file.kind as string] ?? FileIcon,
);
</script>

<template>
    <div
        class="group border-sidebar-border/70 dark:border-sidebar-border relative flex flex-col overflow-hidden rounded-lg border"
        :class="{ 'opacity-50': dragging }"
    >
        <button
            type="button"
            class="bg-muted flex aspect-[4/3] items-center justify-center overflow-hidden"
            :title="file.is_image ? 'View' : 'Open'"
            @click="$emit('open')"
        >
            <img
                v-if="file.is_image"
                :src="file.url"
                :alt="file.name"
                loading="lazy"
                class="size-full object-cover transition-transform group-hover:scale-[1.02]"
            />
            <component
                :is="icon"
                v-else
                class="text-muted-foreground size-10"
            />
        </button>

        <div class="flex items-center gap-1 py-2 pr-1 pl-3">
            <div class="min-w-0 flex-1">
                <Highlight
                    :text="file.name"
                    :query="query"
                    class="block truncate text-sm font-medium"
                    :title="file.name"
                />
                <p
                    v-if="searching"
                    class="text-muted-foreground truncate text-xs"
                >
                    <Folder class="mr-1 inline size-3 align-[-2px]" />{{
                        file.path ?? 'Drive'
                    }}
                </p>
                <p class="text-muted-foreground text-xs">
                    {{ formatBytes(file.size) }} ·
                    {{ formatRelativeTime(file.created_at) }}
                </p>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="ghost" size="icon" class="size-7 shrink-0">
                        <MoreHorizontal />
                        <span class="sr-only">File actions</span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem @select="$emit('open')">
                        <ExternalLink /> {{ file.is_image ? 'View' : 'Open' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <a :href="downloadUrl"><Download /> Download</a>
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="$emit('rename')">
                        <Pencil /> Rename
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="$emit('move')">
                        <FolderInput /> Move
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        @select="$emit('remove')"
                    >
                        <Trash2 /> Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
