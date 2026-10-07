<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    Copy,
    EyeOff,
    Pencil,
    Trash2,
} from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ColumnMeta } from '@/types';

// The class a header gives this lands on the button, not the menu around it
defineOptions({ inheritAttrs: false });

const props = defineProps<{ column: ColumnMeta }>();

const emit = defineEmits<{
    (e: 'edit', column: ColumnMeta): void;
    (e: 'sort', column: string, direction: 'asc' | 'desc'): void;
    (e: 'hide', column: string): void;
    (e: 'duplicate', column: string): void;
    (e: 'delete', column: string): void;
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="hover:bg-muted hover:text-foreground data-[state=open]:bg-muted hidden shrink-0 rounded p-0.5 group-hover:block focus-visible:block data-[state=open]:block"
                title="Field options"
                data-test="column-menu"
                v-bind="$attrs"
            >
                <ChevronDown class="size-3.5" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-48">
            <DropdownMenuItem @select="emit('edit', props.column)">
                <Pencil /> Edit field
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem @select="emit('sort', props.column.name, 'asc')">
                <ArrowUp /> Sort ascending
            </DropdownMenuItem>
            <DropdownMenuItem @select="emit('sort', props.column.name, 'desc')">
                <ArrowDown /> Sort descending
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                :disabled="props.column.isPrimary"
                @select="emit('hide', props.column.name)"
            >
                <EyeOff /> Hide field
            </DropdownMenuItem>
            <DropdownMenuItem @select="emit('duplicate', props.column.name)">
                <Copy /> Duplicate field
            </DropdownMenuItem>
            <template v-if="!props.column.isPrimary">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    @select="emit('delete', props.column.name)"
                >
                    <Trash2 /> Delete field
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
