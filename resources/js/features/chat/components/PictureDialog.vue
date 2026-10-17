<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import Tip from '@/components/common/Tip.vue';

// A picture from a chat, opened larger over it. One of several sent
// together says which it is, and steps to the one before or after -- by
// its arrows, or the keyboard's.

const props = defineProps<{
    file: { name: string; url: string } | null;
    // "2 of 5", when it is one of several
    place?: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{ step: [by: -1 | 1] }>();

function onKey(event: KeyboardEvent) {
    if (!props.place) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        emit('step', -1);
    } else if (event.key === 'ArrowRight') {
        emit('step', 1);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="file"
            class="flex max-h-[92vh] w-auto max-w-[92vw] flex-col gap-3 p-3 sm:max-w-[92vw]"
            data-chat-picture
            @keydown="onKey"
        >
            <DialogTitle class="truncate pr-8 text-sm font-medium">
                {{ file.name
                }}<span
                    v-if="place"
                    class="text-muted-foreground ml-2 font-normal tabular-nums"
                    data-chat-picture-place
                    >{{ place }}</span
                >
            </DialogTitle>
            <DialogDescription class="sr-only"
                >The picture, as large as it fits.</DialogDescription
            >
            <div class="relative self-center">
                <img
                    :src="file.url"
                    :alt="file.name"
                    class="max-h-[78vh] max-w-full rounded-lg object-contain"
                />
                <template v-if="place">
                    <Tip text="The picture before">
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="secondary"
                            class="absolute top-1/2 left-2 -translate-y-1/2 opacity-80 hover:opacity-100"
                            aria-label="The picture before"
                            data-chat-picture-before
                            @click="emit('step', -1)"
                        >
                            <ChevronLeft />
                        </Button>
                    </Tip>
                    <Tip text="The picture after">
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="secondary"
                            class="absolute top-1/2 right-2 -translate-y-1/2 opacity-80 hover:opacity-100"
                            aria-label="The picture after"
                            data-chat-picture-after
                            @click="emit('step', 1)"
                        >
                            <ChevronRight />
                        </Button>
                    </Tip>
                </template>
            </div>
            <!-- A picture held in the page (a data: URL) is saved as it is -->
            <a
                :href="
                    file.url.startsWith('data:')
                        ? file.url
                        : `${file.url}?download=1`
                "
                :download="file.url.startsWith('data:') ? file.name : undefined"
                class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 self-start text-xs underline-offset-2 hover:underline"
            >
                <Download class="size-3.5" />
                Download
            </a>
        </DialogContent>
    </Dialog>
</template>
