<script setup lang="ts">
import { MapPin, Paperclip, X } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { DriveFile } from '@/lib/drive';
import type { ChatPlace } from './MessagePlace.vue';
import Tip from '@/components/common/Tip.vue';

// What goes with the next message, to look at or take back: a place, or a
// file -- a picture shown small before it is sent

const props = defineProps<{
    placed: ChatPlace | null;
    attached: File | DriveFile | null;
}>();

const emit = defineEmits<{ unplace: []; detach: [] }>();

const preview = ref<string | null>(null);

watch(
    () => props.attached,
    (file, before) => {
        if (before instanceof File && preview.value) {
            URL.revokeObjectURL(preview.value);
        }

        preview.value = !file
            ? null
            : file instanceof File
              ? file.type.startsWith('image/')
                  ? URL.createObjectURL(file)
                  : null
              : file.is_image
                ? file.url
                : null;
    },
);

onBeforeUnmount(() => {
    if (props.attached instanceof File && preview.value) {
        URL.revokeObjectURL(preview.value);
    }
});
</script>

<template>
    <div
        v-if="placed"
        class="bg-muted/50 mb-2 flex w-fit max-w-full items-center gap-2.5 rounded-xl border p-1.5 pr-3"
        data-chat-placed
    >
        <span
            class="bg-background flex size-10 shrink-0 items-center justify-center rounded-lg border text-red-500"
        >
            <MapPin class="size-4" />
        </span>
        <span class="min-w-0">
            <span class="block truncate text-sm">{{
                placed.title || 'A place'
            }}</span>
            <span
                v-if="placed.address"
                class="text-muted-foreground block truncate text-xs"
                >{{ placed.address }}</span
            >
        </span>
        <Tip text="Remove the place">
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground shrink-0 rounded-full p-0.5"
                aria-label="Remove the place"
                @click="emit('unplace')"
            >
                <X class="size-4" />
            </button>
        </Tip>
    </div>
    <div
        v-if="attached"
        class="bg-muted/50 mb-2 flex w-fit max-w-full items-center gap-2.5 rounded-xl border p-1.5 pr-3"
        data-chat-attached
    >
        <img
            v-if="preview"
            :src="preview"
            alt=""
            class="size-10 shrink-0 rounded-lg object-cover"
        />
        <span
            v-else
            class="bg-background text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-lg border"
        >
            <Paperclip class="size-4" />
        </span>
        <span class="min-w-0 truncate text-sm">{{ attached.name }}</span>
        <Tip text="Remove the file">
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground shrink-0 rounded-full p-0.5"
                aria-label="Remove the file"
                @click="emit('detach')"
            >
                <X class="size-4" />
            </button>
        </Tip>
    </div>
</template>
