<script setup lang="ts">
import { HardDrive, MapPin, Paperclip, Upload } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { DriveFile } from '@/lib/drive';
import DriveFileDialog from './DriveFileDialog.vue';
import type { ChatPlace } from './MessagePlace.vue';
import PlaceDialog from './PlaceDialog.vue';

// What can go with the next message: a file from this device or from the
// Drive of whoever's chat this is, or a place -- chosen from the saved
// ones, a search, or where one is

defineProps<{ room: string; disabled: boolean }>();

const emit = defineEmits<{
    file: [file: File | DriveFile];
    place: [place: ChatPlace];
}>();

const picker = ref<HTMLInputElement | null>(null);
const choosing = ref(false);
const placing = ref(false);

function chosen(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    (event.target as HTMLInputElement).value = '';

    if (file) {
        emit('file', file);
    }
}
</script>

<template>
    <input
        ref="picker"
        type="file"
        class="hidden"
        data-chat-file-input
        @change="chosen"
    />
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                aria-label="Attach a file or a place"
                title="Attach a file or a place"
                :disabled="disabled"
                data-chat-attach
            >
                <Paperclip />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" side="top">
            <DropdownMenuItem @select="picker?.click()">
                <Upload />
                From this device
            </DropdownMenuItem>
            <DropdownMenuItem @select="choosing = true">
                <HardDrive />
                From the Drive
            </DropdownMenuItem>
            <DropdownMenuItem @select="placing = true">
                <MapPin />
                A place
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <DriveFileDialog
        v-model:open="choosing"
        :room="room"
        @pick="emit('file', $event)"
    />
    <PlaceDialog v-model:open="placing" @pick="emit('place', $event)" />
</template>
