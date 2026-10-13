<script setup lang="ts">
import { computed } from 'vue';
import type { SymbolDef } from '@/features/boards/circuit/symbols';

// A symbol small, as its own button: the library's drawing of it, so what is
// picked is what lands on the board.
const props = defineProps<{ symbol: SymbolDef }>();

// Square, with the drawing in its middle
const box = computed(() => {
    const side = Math.max(props.symbol.width, props.symbol.height, 40);

    return `${(props.symbol.width - side) / 2} ${(props.symbol.height - side) / 2} ${side} ${side}`;
});
</script>

<template>
    <svg :viewBox="box" aria-hidden="true">
        <path
            v-if="symbol.path"
            :d="symbol.path"
            fill="none"
            stroke="currentColor"
            stroke-width="1.4"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
        <path v-if="symbol.fill" :d="symbol.fill" fill="currentColor" />
    </svg>
</template>
