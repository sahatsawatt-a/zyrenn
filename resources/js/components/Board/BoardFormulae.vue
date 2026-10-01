<script setup lang="ts">
import type { Ref } from 'vue';
import { computed } from 'vue';
import type { Item } from '../../composables/board/items';
import { useFormulae } from '../../composables/board/useFormulae';

// Formulae, set by KaTeX over a board -- the canvas, or a board drawn
// somewhere else -- and moved and scaled with however it is being looked at.
// They take no clicks: the shape underneath is what gets selected.
const props = defineProps<{
    items: Item[];
    camera: {
        scale: Readonly<Ref<number>>;
        position: Readonly<Ref<{ x: number; y: number }>>;
    };
    /** The formula being typed into, which hides meanwhile. */
    editingId?: string | null;
}>();

const { formulae, mathHtml, mathStyle } = useFormulae({
    items: computed(() => props.items),
    camera: props.camera,
    editingId: computed(() => props.editingId ?? null),
});
</script>

<template>
    <div
        v-for="item in formulae"
        :key="item.id"
        class="math-item"
        :style="mathStyle(item)"
        :data-test="`math-${item.id}`"
        v-html="mathHtml(item)"
    />
</template>

<style scoped>
/* A formula sits over the board, but never in the way of it */
.math-item {
    position: absolute;
    display: flex;
    overflow: hidden;
    color: #0f172a;
    pointer-events: none;
}
/* KaTeX sets its own size; the wrapper's font-size is what scales it */
.math-item :deep(.katex-display) {
    margin: 0;
}
.math-item :deep(.katex) {
    font-size: 1em;
}
</style>
