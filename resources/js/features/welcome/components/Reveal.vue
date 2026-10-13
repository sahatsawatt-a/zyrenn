<script setup lang="ts">
// What it holds comes up into place the first time it is scrolled to.
import { useIntersectionObserver } from '@vueuse/core';
import { ref } from 'vue';

const box = ref<HTMLElement | null>(null);
const seen = ref(false);

const { stop } = useIntersectionObserver(
    box,
    ([entry]) => {
        if (entry?.isIntersecting) {
            seen.value = true;
            stop();
        }
    },
    { threshold: 0.15 },
);
</script>

<template>
    <div
        ref="box"
        class="transition duration-700 ease-out motion-reduce:transition-none"
        :class="
            seen
                ? 'translate-y-0 opacity-100'
                : 'translate-y-6 opacity-0 motion-reduce:translate-y-0 motion-reduce:opacity-100'
        "
    >
        <slot />
    </div>
</template>
