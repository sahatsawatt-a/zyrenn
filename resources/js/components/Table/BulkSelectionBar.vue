<script setup lang="ts">
import { Copy, Trash2, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{ selectedIds: number[] }>();

const emit = defineEmits<{
    (e: 'clear'): void;
    (e: 'delete'): void;
    (e: 'duplicate'): void;
}>();
</script>

<template>
    <Transition
        enter-from-class="translate-y-2 opacity-0"
        leave-to-class="translate-y-2 opacity-0"
        enter-active-class="transition duration-150"
        leave-active-class="transition duration-100"
    >
        <div
            v-if="selectedIds.length"
            class="bg-popover text-popover-foreground absolute bottom-4 left-1/2 z-30 flex -translate-x-1/2 items-center gap-1 rounded-full border py-1 pr-1 pl-4 text-sm shadow-lg"
            data-test="bulk-bar"
        >
            <span class="mr-2 font-medium tabular-nums">
                {{ selectedIds.length }}
                {{ selectedIds.length === 1 ? 'row' : 'rows' }} selected
            </span>
            <Button
                variant="ghost"
                size="sm"
                class="rounded-full"
                @click="emit('duplicate')"
            >
                <Copy /> Duplicate
            </Button>
            <Button
                variant="ghost"
                size="sm"
                class="text-destructive hover:bg-destructive/10 hover:text-destructive rounded-full"
                data-test="bulk-delete"
                @click="emit('delete')"
            >
                <Trash2 /> Delete
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="rounded-full"
                title="Clear the selection"
                @click="emit('clear')"
            >
                <X />
            </Button>
        </div>
    </Transition>
</template>
