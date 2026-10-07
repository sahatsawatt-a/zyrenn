<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import BoardView from '@/features/boards/components/BoardView.vue';
import type { Item } from '@/features/boards/composables/items';
import { markEditorReady } from '@/lib/printReady';

// A board drawn for the renderer (App\Support\Board\BoardRender) and nothing else:
// as a picture -- one frame, or everything -- or as pages, a frame to each,
// for a PDF. The renderer sizes the window (or the paper) to fit.
const props = defineProps<{
    items: Item[];
    mode: 'picture' | 'pages';
    frame: string | null;
    frames: string[];
}>();

// A board with no frames prints as one page of everything
const pages = props.frames.length ? props.frames : [null];

// Drawn: the renderer waits for this, then for the pictures to finish loading
onMounted(() => markEditorReady());
</script>

<template>
    <Head title="Board" />

    <div v-if="mode === 'picture'" class="render-picture">
        <BoardView
            :items="items"
            :frame="frame"
            :bare="!!frame"
            :padding="frame ? 0 : 24"
        />
    </div>

    <template v-else>
        <div
            v-for="(page, index) in pages"
            :key="page ?? `whole-${index}`"
            class="render-page"
        >
            <BoardView
                :items="items"
                :frame="page"
                :bare="!!page"
                :padding="page ? 0 : 24"
            />
        </div>
    </template>
</template>

<style scoped>
.render-picture {
    width: 100vw;
    height: 100vh;
}

/* One frame to a sheet of paper the size of the frame */
.render-page {
    width: 100vw;
    height: 100vh;
    overflow: hidden;
    break-after: page;
}
.render-page:last-child {
    break-after: auto;
}

:global(html),
:global(body) {
    margin: 0;
    overflow: hidden;
}
</style>
