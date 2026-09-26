<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BoardCanvas from '@/components/Board/BoardCanvas.vue';

// The scratch board: the same canvas the real boards use, opened on a sample
// deck and saving nowhere.
</script>

<template>
    <Head title="Board demo" />

    <BoardCanvas title="Board demo" status="Nothing here is saved" />
</template>
