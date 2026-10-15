<script setup lang="ts">
import { ImageIcon, X } from '@lucide/vue';
import { ref } from 'vue';
import DriveFileDialog from '@/features/chat/components/DriveFileDialog.vue';
import type { Item } from '@/features/boards/composables/items';
import type { DriveFile } from '@/lib/drive';

// A slide's own: a picture behind everything on it, the part of the deck it
// begins (what a footer's {section} says), and the presenter's notes.
const props = defineProps<{ frame: Item }>();
const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const choosing = ref(false);

const picked = (file: DriveFile) =>
    emit('update', { src: file.url, focusX: 0.5, focusY: 0.5 });

const text = (event: Event) =>
    (event.target as HTMLInputElement | HTMLTextAreaElement).value;
</script>

<template>
    <div class="space-y-2" data-test="slide-settings">
        <div class="inspector-align">
            <p class="inspector-label">Background picture</p>
            <div class="inspector-segments">
                <button
                    type="button"
                    class="gap-1.5"
                    data-test="slide-background"
                    @click="choosing = true"
                >
                    <ImageIcon :size="13" />
                    {{ props.frame.src ? 'Change' : 'Choose from the Drive' }}
                </button>
                <button
                    v-if="props.frame.src"
                    type="button"
                    title="Take the picture away"
                    data-test="slide-background-remove"
                    @click="emit('update', { src: '' })"
                >
                    <X :size="13" />
                </button>
            </div>
        </div>

        <label class="block space-y-1">
            <span class="inspector-label">Section</span>
            <input
                class="w-full rounded-md border bg-transparent px-2 py-1 text-xs"
                placeholder="Begins a part of the deck, e.g. Results"
                maxlength="120"
                :value="props.frame.section ?? ''"
                data-test="slide-section"
                @change="emit('update', { section: text($event) })"
            />
        </label>

        <label class="block space-y-1">
            <span class="inspector-label">Speaker notes</span>
            <textarea
                class="w-full rounded-md border bg-transparent px-2 py-1 text-xs"
                rows="4"
                maxlength="10000"
                placeholder="What you say over this slide: only you see it when presenting"
                :value="props.frame.notes ?? ''"
                data-test="slide-notes"
                @change="emit('update', { notes: text($event) })"
            />
        </label>

        <DriveFileDialog
            v-model:open="choosing"
            kind="image"
            title="A picture behind the slide"
            description="From the Drive, newest first. It fills the slide behind everything on it."
            @pick="picked"
        />
    </div>
</template>
