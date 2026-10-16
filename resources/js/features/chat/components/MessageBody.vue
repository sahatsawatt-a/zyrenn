<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { onlyEmoji } from '../conversation';
import type { Message, MessageFile as File } from '../types';
import MessageAlbum from './MessageAlbum.vue';
import MessageFile from './MessageFile.vue';
import MessagePlace from './MessagePlace.vue';
import MessageText from './MessageText.vue';

// What a message holds: its words -- large when they are only a few emoji,
// else in a bubble drawn as the speaker's -- and the pictures, file or
// place that came with them

defineProps<{
    message: Message;
    // Pictures sent together, drawn as one
    album: File[] | null;
    // How the bubble is drawn: whose it is, and its corner by the face
    bubble: HTMLAttributes['class'];
}>();
</script>

<template>
    <p
        v-if="message.content && onlyEmoji(message)"
        class="px-1 text-5xl leading-tight"
        data-chat-emoji-only
    >
        {{ message.content.trim() }}
    </p>
    <p
        v-else-if="message.content"
        class="rounded-2xl px-3.5 py-2 text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
        :class="bubble"
    >
        <MessageText :content="message.content" :emojis="message.emojis" />
    </p>
    <MessageAlbum v-if="album" :files="album" />
    <MessageFile
        v-else-if="message.file"
        :file="message.file"
        :sticker="message.sticker"
    />
    <MessagePlace v-if="message.place" :place="message.place" />
</template>
