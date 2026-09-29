<script setup lang="ts">
import { FolderInput, MoreHorizontal, Pencil, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// The "…" menu on anything in a folder view: rename, move, delete, and
// whatever a kind adds above them (Drive's open and download).
defineProps<{ label: string }>();

// A class given to the menu lands on its button
defineOptions({ inheritAttrs: false });

defineEmits<{ rename: []; move: []; remove: [] }>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                v-bind="$attrs"
                class="size-7 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100 max-lg:opacity-100"
            >
                <MoreHorizontal />
                <span class="sr-only">{{ label }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <slot />
            <DropdownMenuItem @select="$emit('rename')">
                <Pencil /> Rename
            </DropdownMenuItem>
            <DropdownMenuItem @select="$emit('move')">
                <FolderInput /> Move
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem variant="destructive" @select="$emit('remove')">
                <Trash2 /> Delete
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
