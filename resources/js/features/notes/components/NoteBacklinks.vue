<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { ref, watch } from 'vue';
import { notesLinkingTo } from '@/features/notes/lib/pageLinks';
import type { LinkingNote } from '@/features/notes/lib/pageLinks';

// The notes that link to this one, under it: where it is mentioned. Nothing
// is shown for a note nothing links to.
const props = defineProps<{ note: string }>();

const notes = ref<LinkingNote[]>([]);

watch(
    () => props.note,
    async (note) => {
        notes.value = [];
        notes.value = await notesLinkingTo(note).catch(() => []);
    },
    { immediate: true },
);
</script>

<template>
    <section
        v-if="notes.length"
        class="note-backlinks"
        data-test="note-backlinks"
    >
        <h2>Linked from</h2>
        <ul>
            <li v-for="from in notes" :key="from.ref_id">
                <Link :href="from.url">
                    <FileText class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ from.title || 'Untitled' }}</span>
                    <span v-if="from.folder" class="note-backlinks-folder">{{
                        from.folder
                    }}</span>
                </Link>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.note-backlinks {
    margin-top: 2.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
}
.note-backlinks h2 {
    margin-bottom: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}
.note-backlinks ul {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.note-backlinks a {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    padding: 0.3125rem 0.5rem;
    margin: 0 -0.5rem;
    font-size: 0.875rem;
    border-radius: var(--radius-md);
}
.note-backlinks a:hover {
    background-color: var(--muted);
}
.note-backlinks-folder {
    flex-shrink: 0;
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--muted-foreground);
}
</style>
