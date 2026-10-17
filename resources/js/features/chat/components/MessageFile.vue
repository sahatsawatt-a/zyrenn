<script setup lang="ts">
import { Paperclip } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import PictureDialog from '@/features/chat/components/PictureDialog.vue';
import VideoPlayer from '@/components/media/VideoPlayer.vue';
import Tip from '@/components/common/Tip.vue';

// A file under a chat message, from the Drive: a picture shown small and
// opened larger over the chat; a sticker small and bare, as a sticker is --
// moving, when it is one that moves, and played again on a click; a video
// to play; anything else as its name, to open.

const props = defineProps<{
    file: {
        ref_id: string;
        name: string;
        url: string;
        is_image: boolean;
        is_video: boolean;
    };
    sticker?: boolean;
}>();

const open = ref(false);

// A sticker that moves plays a few times and stops. A click plays it again:
// a browser only starts a picture's animation over for a picture it has not
// shown before, so the same bytes are handed to it under a new address --
// fetched once, never again.
const playing = ref<string | null>(null);
let bytes: Blob | null = null;
let replaying = false;

async function replay() {
    if (replaying) {
        return;
    }

    replaying = true;

    try {
        bytes ??= await (await fetch(props.file.url)).blob();

        const before = playing.value;
        playing.value = URL.createObjectURL(bytes);

        if (before) {
            URL.revokeObjectURL(before);
        }
    } catch {
        // It stays as it was: a sticker that would not play again is still a sticker
    } finally {
        replaying = false;
    }
}

// A sticker that is a short film goes round by itself; a click starts it over
function restart(event: Event) {
    const film = event.target as HTMLVideoElement;

    film.currentTime = 0;
    void film.play();
}

onBeforeUnmount(() => {
    if (playing.value) {
        URL.revokeObjectURL(playing.value);
    }
});
</script>

<template>
    <Tip v-if="file.is_image && sticker" text="Click to play it again">
        <img
            :src="playing ?? file.url"
            :alt="file.name"
            loading="lazy"
            class="mt-1 size-28 cursor-pointer object-contain"
            data-chat-file
            data-chat-sticker
            @click="replay"
        />
    </Tip>
    <!-- A sticker that is a short film: played over and over, with nothing to press -->
    <Tip v-else-if="file.is_video && sticker" text="Click to play it again">
        <video
            :src="file.url"
            autoplay
            loop
            muted
            playsinline
            aria-label="Click to play it again"
            class="mt-1 size-28 cursor-pointer object-contain"
            data-chat-file
            data-chat-sticker
            @click="restart"
        />
    </Tip>
    <template v-else-if="file.is_image">
        <button
            type="button"
            class="mt-1 block cursor-zoom-in"
            :aria-label="`Open ${file.name}`"
            data-chat-file
            @click="open = true"
        >
            <img
                :src="file.url"
                :alt="file.name"
                loading="lazy"
                class="max-h-72 max-w-full rounded-2xl border object-cover sm:max-w-xs"
            />
        </button>
        <PictureDialog v-model:open="open" :file="file" />
    </template>
    <VideoPlayer
        v-else-if="file.is_video"
        :src="file.url"
        class="mt-1 max-h-72 w-fit max-w-full rounded-2xl border sm:max-w-xs"
        data-chat-file
    />
    <a
        v-else
        :href="file.url"
        target="_blank"
        rel="noopener"
        class="bg-muted/50 mt-1 inline-flex max-w-full items-center gap-2 rounded-2xl border px-4 py-2 text-sm underline-offset-2 hover:underline"
        data-chat-file
    >
        <Paperclip class="size-3.5 shrink-0" />
        <span class="truncate">{{ file.name }}</span>
    </a>
</template>
