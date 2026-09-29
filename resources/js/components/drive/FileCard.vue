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
} from '@lucide/vue';
import { computed } from 'vue';
import Highlight from '@/components/folders/Highlight.vue';
import ItemActions from '@/components/folders/ItemActions.vue';
import {
    DropdownMenuItem,
    DropdownMenuSeparator,
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
        class="group bg-card hover:border-primary/40 relative flex flex-col overflow-hidden rounded-xl border transition-all hover:shadow-sm"
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

            <ItemActions
                label="File actions"
                @rename="$emit('rename')"
                @move="$emit('move')"
                @remove="$emit('remove')"
            >
                <DropdownMenuItem @select="$emit('open')">
                    <ExternalLink /> {{ file.is_image ? 'View' : 'Open' }}
                </DropdownMenuItem>
                <DropdownMenuItem as-child>
                    <a :href="downloadUrl"><Download /> Download</a>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
            </ItemActions>
        </div>
    </div>
</template>
