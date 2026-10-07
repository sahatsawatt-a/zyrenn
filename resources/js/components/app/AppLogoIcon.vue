<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();

/** One blade. Drawn about the origin so the two copies are the same path rotated. */
const BLADE =
    'M 0 -150 Q 15.18 -49.50 46 0 Q 15.18 49.50 0 150 Q -15.18 49.50 -46 0 Q -15.18 -49.50 0 -150 Z';
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 232 232"
        :class="className"
        v-bind="$attrs"
    >
        <path
            transform="translate(116 116) rotate(-45)"
            fill="#6D8CCF"
            :d="BLADE"
        />
        <path
            transform="translate(116 116) rotate(45)"
            fill="#CF6D6D"
            :d="BLADE"
        />
    </svg>
</template>
