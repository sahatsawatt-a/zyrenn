<script setup lang="ts">
import { computed } from 'vue';

// A chat message's words, with a picture where some of them stand for one:
// an outside chat's own emoji, which come as their names in the text.

const props = defineProps<{
    content: string;
    // Where each picture goes: from which character, for how many, and what to draw
    emojis?: { index: number; length: number; url: string }[];
}>();

type Part = { text: string; url?: string; href?: string };

// An address among the words, without what closes the sentence after it
const ADDRESS = /https?:\/\/[^\s<>]+[^\s<>.,;:!?)\]'"]/g;

function worded(text: string): Part[] {
    const parts: Part[] = [];
    let at = 0;

    for (const found of text.matchAll(ADDRESS)) {
        if (found.index > at) {
            parts.push({ text: text.slice(at, found.index) });
        }

        parts.push({ text: found[0], href: found[0] });
        at = found.index + found[0].length;
    }

    if (at < text.length) {
        parts.push({ text: text.slice(at) });
    }

    return parts;
}

const parts = computed<Part[]>(() => {
    const found: Part[] = [];
    let at = 0;

    for (const emoji of [...(props.emojis ?? [])].sort(
        (a, b) => a.index - b.index,
    )) {
        if (emoji.index < at) {
            continue;
        }

        if (emoji.index > at) {
            found.push(...worded(props.content.slice(at, emoji.index)));
        }

        at = emoji.index + emoji.length;
        found.push({
            text: props.content.slice(emoji.index, at),
            url: emoji.url,
        });
    }

    if (at < props.content.length) {
        found.push(...worded(props.content.slice(at)));
    }

    return found;
});

// Nothing but emoji, and few of them: drawn larger, as a chat does
const large = computed(
    () =>
        parts.value.length > 0 &&
        parts.value.length <= 3 &&
        parts.value.every((part) => part.url),
);
</script>

<template>
    <template v-for="(part, at) in parts" :key="at">
        <img
            v-if="part.url"
            :src="part.url"
            :alt="part.text"
            :title="part.text"
            loading="lazy"
            class="inline-block align-text-bottom"
            :class="large ? 'size-12' : 'size-[1.4em]'"
            data-chat-emoji
        /><a
            v-else-if="part.href"
            :href="part.href"
            target="_blank"
            rel="noopener noreferrer"
            class="underline underline-offset-2 hover:opacity-80"
            >{{ part.text }}</a
        ><template v-else>{{ part.text }}</template>
    </template>
</template>
