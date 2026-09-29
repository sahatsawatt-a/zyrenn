<script setup lang="ts">
import { Maximize2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

// Long text shows its first line in the cell, and opens to be written in full.
defineProps<{
    column: { label: string };
    modelValue?: string | number | null;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const open = ref(false);
</script>

<template>
    <button
        type="button"
        class="flex h-full w-full min-w-0 items-center gap-1 px-2 text-left text-sm"
        :title="modelValue ? String(modelValue) : 'Open to write'"
        @click="open = true"
    >
        <span class="min-w-0 flex-1 truncate">{{ modelValue }}</span>
        <Maximize2
            class="text-muted-foreground size-3.5 shrink-0 opacity-0 group-hover/cell:opacity-100"
        />
    </button>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ column.label }}</DialogTitle>
            </DialogHeader>
            <textarea
                :value="modelValue ?? ''"
                rows="12"
                autofocus
                class="border-input focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 min-h-64 w-full resize-y rounded-md border bg-transparent p-3 text-sm leading-relaxed outline-none focus-visible:ring-2"
                @input="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLTextAreaElement).value,
                    )
                "
            />
            <DialogFooter class="items-center sm:justify-between">
                <span class="text-muted-foreground text-xs tabular-nums">
                    {{ String(modelValue ?? '').length }} characters
                </span>
                <Button @click="open = false">Done</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
