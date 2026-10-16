<script setup lang="ts">
import type { Pointer } from './useBoardSync';

// Where the others' pointers are, in their colours: a board point each,
// placed on the screen as the camera has the board.
defineProps<{
    pointers: Pointer[];
    scale: number;
    position: { x: number; y: number };
}>();
</script>

<template>
    <div
        v-for="pointer in pointers"
        :key="pointer.id"
        class="pointer-events-none absolute top-0 left-0 z-10 flex items-start transition-transform duration-75"
        :style="{
            transform: `translate(${pointer.x * scale + position.x}px, ${pointer.y * scale + position.y}px)`,
        }"
        data-test="board-pointer"
    >
        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
            <path
                d="M1 1l5.5 13 2-5.5L14 6.5z"
                :fill="pointer.color"
                stroke="white"
                stroke-width="1.2"
                stroke-linejoin="round"
            />
        </svg>
        <span
            class="mt-3 rounded px-1.5 py-0.5 text-[11px] font-semibold whitespace-nowrap text-white"
            :style="{ backgroundColor: pointer.color }"
            >{{ pointer.name }}</span
        >
    </div>
</template>
