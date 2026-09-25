<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import {
    Folder,
    FolderInput,
    MoreHorizontal,
    Pencil,
    Trash2,
} from '@lucide/vue';
import Highlight from '@/components/folders/Highlight.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// A folder in a folder view. Drag and drop handlers bound on it fall through to the card.
defineProps<{
    // `path` is only there in search results, which span every folder
    folder: { name: string; path?: string };
    href: InertiaLinkProps['href'];
    query: string;
    over: boolean;
    dragging: boolean;
}>();

defineEmits<{ rename: []; move: []; remove: [] }>();
</script>

<template>
    <div
        class="group border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/60 relative flex items-center rounded-lg border transition-colors"
        :class="{
            'border-primary! bg-primary/5 ring-primary/30 ring-2': over,
            'opacity-50': dragging,
        }"
    >
        <Link
            :href="href"
            class="flex min-w-0 flex-1 items-center gap-3 px-3 py-3"
        >
            <Folder class="text-muted-foreground size-5 shrink-0" />
            <span class="min-w-0">
                <Highlight
                    :text="folder.name"
                    :query="query"
                    class="block truncate text-sm font-medium"
                />
                <span
                    v-if="folder.path?.includes(' / ')"
                    class="text-muted-foreground block truncate text-xs"
                >
                    {{ folder.path.slice(0, folder.path.lastIndexOf(' / ')) }}
                </span>
            </span>
        </Link>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="mr-1 size-7 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100"
                >
                    <MoreHorizontal />
                    <span class="sr-only">Folder actions</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
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
</template>
