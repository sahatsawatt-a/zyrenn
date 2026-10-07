<script setup lang="ts">
import { computed } from 'vue';

// Text with every occurrence of `query` marked, case-insensitively
const props = defineProps<{ text: string; query: string }>();

const parts = computed(() => {
    const query = props.query.trim();

    if (!query) {
        return [{ text: props.text, match: false }];
    }

    const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    return props.text
        .split(new RegExp(`(${escaped})`, 'gi'))
        .filter(Boolean)
        .map((text) => ({
            text,
            match: text.toLowerCase() === query.toLowerCase(),
        }));
});
</script>

<template>
    <span
        ><template v-for="(part, i) in parts" :key="i"
            ><mark
                v-if="part.match"
                class="bg-primary/15 text-foreground rounded-sm px-0.5"
                >{{ part.text }}</mark
            ><template v-else>{{ part.text }}</template></template
        ></span
    >
</template>
