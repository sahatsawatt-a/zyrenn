<script setup lang="ts">
import { Maximize, Minus, Plus } from '@lucide/vue';
import Tip from '@/components/common/Tip.vue';

// How close in, and the way back to everything. On a phone two fingers zoom,
// so the pill is only how far in and the way back.
defineProps<{ scale: number; compact: boolean }>();
const emit = defineEmits<{ zoom: [factor: number]; fit: [] }>();
</script>

<template>
    <div
        class="board-pill right-3"
        :class="compact ? 'is-compact bottom-16' : 'bottom-3'"
    >
        <Tip text="Zoom out">
            <button
                type="button"
                class="board-zoom board-zoom-step"
                aria-label="Zoom out"
                data-test="zoom-out"
                @click="emit('zoom', 0.8)"
            >
                <Minus class="size-3.5" />
            </button>
        </Tip>
        <button
            type="button"
            class="board-zoom w-12 text-xs tabular-nums"
            data-test="zoom"
            title="Fit everything"
            @click="emit('fit')"
        >
            {{ Math.round(scale * 100) }}%
        </button>
        <Tip text="Zoom in">
            <button
                type="button"
                class="board-zoom board-zoom-step"
                aria-label="Zoom in"
                data-test="zoom-in"
                @click="emit('zoom', 1.25)"
            >
                <Plus class="size-3.5" />
            </button>
        </Tip>
        <span class="board-zoom-step bg-border mx-0.5 h-4 w-px" />
        <Tip text="Fit everything">
            <button
                type="button"
                class="board-zoom board-zoom-step"
                aria-label="Fit everything"
                data-test="fit"
                @click="emit('fit')"
            >
                <Maximize class="size-3.5" />
            </button>
        </Tip>
    </div>
</template>

<style scoped>
.board-pill.is-compact .board-zoom-step {
    display: none;
}
</style>
