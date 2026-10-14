<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileText, Link2 } from '@lucide/vue';
import { createReusableTemplate } from '@vueuse/core';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { notesLinkingTo } from '@/features/notes/lib/pageLinks';
import type { LinkingNote, PageKind } from '@/features/notes/lib/pageLinks';

// The notes that link to a page: where it is mentioned. Under a note they
// are a list; on a board, a table or a trip, which have no foot to put one
// at, they are folded behind a button that says how many. Nothing is shown
// for a page nothing links to.
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{ page: string; kind?: PageKind; folded?: boolean }>(),
    { kind: 'note', folded: false },
);

const notes = ref<LinkingNote[]>([]);

watch(
    () => [props.page, props.kind] as const,
    async ([page, kind]) => {
        notes.value = [];
        notes.value = await notesLinkingTo(page, kind).catch(() => []);
    },
    { immediate: true },
);

const [DefineList, List] = createReusableTemplate();
</script>

<template>
    <DefineList>
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
    </DefineList>

    <Popover v-if="folded && notes.length">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                v-bind="$attrs"
                :title="`${notes.length === 1 ? 'A note links' : `${notes.length} notes link`} here`"
                data-test="linked-from"
            >
                <Link2 />
                {{ notes.length }}
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="note-backlinks w-72 max-w-[calc(100vw-2rem)]"
            data-test="note-backlinks"
        >
            <List />
        </PopoverContent>
    </Popover>
    <section
        v-else-if="!folded && notes.length"
        v-bind="$attrs"
        class="note-backlinks is-foot"
        data-test="note-backlinks"
    >
        <List />
    </section>
</template>

<style>
.note-backlinks.is-foot {
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
