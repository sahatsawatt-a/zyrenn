<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FileText, Map, Presentation, Table2 } from '@lucide/vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { NodeViewProps } from '@tiptap/vue-3';
import type { Component } from 'vue';
import { computed, watch } from 'vue';
import { pageOf, want } from '@/features/notes/lib/pageLinks';
import type { PageKind } from '@/features/notes/lib/pageLinks';

// A link to another page: its sign, and what the page is called now. A
// click opens the page. One that names a page nobody has made is drawn
// faint, and waits for it.
const props = defineProps<NodeViewProps>();

const link = computed(() => ({
    kind: String(props.node.attrs.kind ?? ''),
    ref: String(props.node.attrs.ref ?? ''),
    title: String(props.node.attrs.title ?? ''),
}));

const page = computed(() => pageOf(link.value));

watch(link, (now) => want(now), { immediate: true });

// A link that only named its page is pointed at the page, once it is found:
// from then on it holds through a rename
watch(
    page,
    (found) => {
        if (found?.found && !link.value.ref && props.editor.isEditable) {
            props.updateAttributes({
                kind: found.kind,
                ref: found.ref,
                title: found.title,
            });
        }
    },
    { immediate: true },
);

const ICONS: Record<PageKind, Component> = {
    note: FileText,
    board: Presentation,
    table: Table2,
    trip: Map,
};

const icon = computed(
    () => ICONS[(page.value?.kind ?? link.value.kind) as PageKind] ?? FileText,
);

// Its own words when it has them; else what the page is called now
const shown = computed(
    () =>
        String(props.node.attrs.text ?? '') ||
        (page.value?.found ? page.value.title : link.value.title) ||
        'Untitled',
);

// Known to be missing only once the server has said so
const missing = computed(() => page.value?.found === false);

const open = (event: MouseEvent) => {
    const url = page.value?.url;

    if (!url) {
        return;
    }

    // With Ctrl or Cmd, in a tab of its own, as any link would
    if (event.metaKey || event.ctrlKey) {
        window.open(url, '_blank', 'noopener');

        return;
    }

    router.visit(url);
};
</script>

<template>
    <NodeViewWrapper
        as="span"
        class="page-link"
        :class="{ 'is-missing': missing, 'is-selected': selected }"
        :title="
            missing
                ? `No page called “${link.title}” yet`
                : `Open “${page?.title ?? link.title}”`
        "
        data-test="page-link"
        @click.prevent="open"
    >
        <component :is="icon" class="page-link-icon" aria-hidden="true" />{{
            shown
        }}
    </NodeViewWrapper>
</template>

<style scoped>
.page-link {
    display: inline-flex;
    align-items: baseline;
    gap: 0.2em;
    padding: 0 0.1em;
    font-weight: 500;
    color: var(--primary);
    text-decoration: underline;
    text-decoration-color: color-mix(in oklab, var(--primary) 35%, transparent);
    text-underline-offset: 0.2em;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.page-link:hover {
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
}
.page-link.is-selected {
    outline: 2px solid color-mix(in oklab, var(--primary) 50%, transparent);
}

.page-link-icon {
    align-self: center;
    width: 0.95em;
    height: 0.95em;
    flex-shrink: 0;
}

/* Nothing by that name yet: there to be made, not to be opened */
.page-link.is-missing {
    color: var(--muted-foreground);
    text-decoration-style: dashed;
    text-decoration-color: currentColor;
    cursor: default;
}
</style>
