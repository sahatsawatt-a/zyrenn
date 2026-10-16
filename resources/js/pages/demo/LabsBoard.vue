<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BoardCanvas from '@/features/boards/canvas/BoardCanvas.vue';
import { logicBoard } from '@/features/labs/logicBoard';

// What was added to boards last, on the canvas real boards use: the new
// logic parts wired up and ready to click (they are in the shapes list under
// Electrical too), and a circuit to work out with Values. Nothing here is saved.

const items = logicBoard();
</script>

<template>
    <Head title="Labs: on a board" />

    <BoardCanvas
        :items="items"
        title="Labs: on a board"
        status="Nothing here is saved"
    />
</template>
