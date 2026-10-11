<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

// A face, larger: a room's picture, or that of whoever wrote. Put inside
// whatever it is opened from -- the chat, or the panel about it -- so it
// comes up over that, not under it.

const open = defineModel<boolean>('open', { required: true });

defineProps<{ src: string | null; name: string }>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[92vh] w-auto max-w-[92vw] flex-col gap-3 p-3 sm:max-w-[92vw]"
            data-chat-face-larger
        >
            <DialogTitle class="truncate pr-8 text-sm font-medium">{{
                name
            }}</DialogTitle>
            <DialogDescription class="sr-only"
                >Their picture, as large as it comes.</DialogDescription
            >
            <img
                v-if="src"
                :src="src"
                :alt="name"
                class="max-h-[78vh] min-h-48 max-w-full min-w-48 self-center rounded-lg object-contain"
                referrerpolicy="no-referrer"
            />
        </DialogContent>
    </Dialog>
</template>
