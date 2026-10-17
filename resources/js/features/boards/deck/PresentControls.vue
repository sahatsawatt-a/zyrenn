<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Maximize,
    MonitorUp,
    NotebookText,
    X,
} from '@lucide/vue';
import type { Item } from '../model/items';
import { NOTES_ROOM } from './useShow';
import Tip from '@/components/common/Tip.vue';

// What is laid over the board while it is presented: the speaker notes under
// the slide, when shown, and a bar with where the show is, the way through
// it, and the way out.
defineProps<{
    frameIndex: number;
    count: number;
    currentFrame: Item | null;
    /** Whether the presenter's own window can be opened from here. */
    canOpenPresenter: boolean;
    fullScreen: boolean;
    /** The clicks this slide's animations still wait for, and have had. */
    clicksLeft: number;
    clicksDone: number;
}>();
const notesShown = defineModel<boolean>('notesShown', { required: true });
const emit = defineEmits<{
    next: [];
    back: [];
    presenter: [];
    fullScreen: [];
    stop: [];
}>();
</script>

<template>
    <!-- What is said over this slide, under it, where the camera keeps it
         clear -->
    <div
        v-if="notesShown"
        class="bg-background/95 absolute inset-x-0 bottom-0 overflow-y-auto border-t px-6 pt-3 pb-16 text-lg leading-relaxed"
        :style="{ height: `${NOTES_ROOM * 100}%` }"
        data-test="present-notes"
    >
        <p
            v-if="currentFrame?.notes?.trim()"
            class="mx-auto max-w-3xl whitespace-pre-wrap"
        >
            {{ currentFrame.notes }}
        </p>
        <p v-else class="text-muted-foreground mx-auto max-w-3xl">
            No notes on this slide. Write them in the panel, under Speaker
            notes.
        </p>
    </div>

    <!-- Frame position, arrows, and a way out -->
    <div
        class="bg-background/95 absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full border px-2 py-1.5 shadow-lg"
    >
        <button
            type="button"
            class="board-zoom"
            data-test="prev-frame"
            :disabled="frameIndex === 0 && !clicksDone"
            @click="emit('back')"
        >
            <ChevronLeft class="size-4" />
        </button>
        <span
            class="max-w-[40vw] truncate px-1 text-sm whitespace-nowrap"
            data-test="frame-position"
        >
            {{ frameIndex + 1 }} / {{ count }}
            <span class="text-muted-foreground">
                · {{ currentFrame?.text }}
            </span>
        </span>
        <Tip
            :text="
                clicksLeft
                    ? `Next click: ${clicksLeft} left on this slide`
                    : 'Next slide'
            "
        >
            <button
                type="button"
                class="board-zoom"
                data-test="next-frame"
                :aria-label="
                    clicksLeft
                        ? `Next click: ${clicksLeft} left on this slide`
                        : 'Next slide'
                "
                :disabled="frameIndex >= count - 1 && !clicksLeft"
                @click="emit('next')"
            >
                <ChevronRight class="size-4" />
            </button>
        </Tip>
        <Tip
            :text="
                notesShown
                    ? 'Hide the speaker notes (N)'
                    : 'Show the speaker notes under the slide (N)'
            "
        >
            <button
                type="button"
                class="board-zoom"
                :class="{ 'is-on': notesShown }"
                :aria-label="
                    notesShown
                        ? 'Hide the speaker notes (N)'
                        : 'Show the speaker notes under the slide (N)'
                "
                data-test="present-notes-toggle"
                @click="notesShown = !notesShown"
            >
                <NotebookText class="size-4" />
            </button>
        </Tip>
        <Tip
            v-if="canOpenPresenter"
            text="Open the notes, the next slide and a clock in a window of their own, for your screen"
        >
            <button
                type="button"
                class="board-zoom"
                aria-label="Open the notes, the next slide and a clock in a window of their own, for your screen"
                data-test="present-presenter"
                @click="emit('presenter')"
            >
                <MonitorUp class="size-4" />
            </button>
        </Tip>
        <Tip v-if="!fullScreen" text="Full screen">
            <button
                type="button"
                class="board-zoom"
                aria-label="Full screen"
                data-test="present-full-screen"
                @click="emit('fullScreen')"
            >
                <Maximize class="size-4" />
            </button>
        </Tip>
        <Tip text="Leave (Esc)">
            <button
                type="button"
                class="board-zoom"
                aria-label="Leave (Esc)"
                data-test="exit-present"
                @click="emit('stop')"
            >
                <X class="size-4" />
            </button>
        </Tip>
    </div>
</template>
