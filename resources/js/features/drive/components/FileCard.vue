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
    Play,
    SlidersHorizontal,
} from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Highlight from '@/components/common/Highlight.vue';
import ItemActions from '@/components/folders/ItemActions.vue';
import PhotoEditor from '@/components/photo/PhotoEditor.vue';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import type { DriveFile } from '@/lib/drive';
import { canChange } from '@/lib/projects';
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

// Pictures are viewed and videos played, in place; anything else is opened
const action = computed(() =>
    props.file.is_image ? 'View' : props.file.is_video ? 'Play' : 'Open',
);

const downloadUrl = computed(() =>
    show.url(props.file.ref_id, { query: { download: 1 } }),
);

// A picture is edited into a new one beside it, which the list then shows
const editable = computed(() => props.file.is_image && canChange());
const editing = ref(false);
// After the menu has closed, so the dialog takes the focus it hands back
const startEditing = () => setTimeout(() => (editing.value = true));

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
            :title="action"
            @click="$emit('open')"
        >
            <!-- A frame from just in stands in for a thumbnail -->
            <div v-if="file.is_video" class="relative size-full">
                <video
                    :src="`${file.url}#t=0.5`"
                    preload="metadata"
                    muted
                    class="size-full bg-black object-cover"
                />
                <span
                    class="absolute top-1/2 left-1/2 flex size-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-black/55 text-white transition-transform group-hover:scale-110"
                >
                    <Play class="size-5 fill-current" />
                </span>
            </div>
            <img
                v-else-if="file.is_image"
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
                <p
                    v-if="file.edited_from"
                    class="text-muted-foreground truncate text-xs"
                    :title="`Edited from ${file.edited_from.name}`"
                    data-test="edited-from"
                >
                    <SlidersHorizontal
                        class="mr-1 inline size-3 align-[-2px]"
                    />Edited from {{ file.edited_from.name }}
                </p>
                <p
                    v-else-if="file.edits_count"
                    class="text-muted-foreground text-xs"
                    data-test="edit-count"
                >
                    <SlidersHorizontal
                        class="mr-1 inline size-3 align-[-2px]"
                    />{{ file.edits_count }} edited
                    {{ file.edits_count === 1 ? 'copy' : 'copies' }}
                </p>
            </div>

            <ItemActions
                label="File actions"
                @rename="$emit('rename')"
                @move="$emit('move')"
                @remove="$emit('remove')"
            >
                <DropdownMenuItem @select="$emit('open')">
                    <ExternalLink /> {{ action }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="editable"
                    data-test="edit-photo"
                    @select="startEditing"
                >
                    <SlidersHorizontal /> Edit photo
                </DropdownMenuItem>
                <DropdownMenuItem as-child>
                    <a :href="downloadUrl"><Download /> Download</a>
                </DropdownMenuItem>
            </ItemActions>
        </div>

        <PhotoEditor
            v-if="editing"
            v-model:open="editing"
            :src="file.url"
            @saved="router.reload({ only: ['files'] })"
        />
    </div>
</template>
