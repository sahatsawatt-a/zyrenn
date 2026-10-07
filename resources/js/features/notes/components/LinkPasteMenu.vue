<script setup lang="ts">
import type { Editor } from '@tiptap/vue-3';
import {
    Image,
    Link2,
    LoaderCircle,
    MapPin,
    PanelTop,
    Video,
} from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import type { LinkPreview } from '@/features/notes/lib/links';
import {
    cardBlock,
    mapBlock,
    previewOf,
    shortLabel,
} from '@/features/notes/lib/links';

// A link pasted on a line of its own is put in as a link, and this offers
// what else it could be: a card with its page's picture and title, or -- for
// a map link, a picture or a video -- the thing itself. Any other change to
// the note, or Escape, leaves it a link.

const props = defineProps<{ editor: Editor }>();
/** Where the pasted line starts, and the link on it; null once settled. */
const pasted = defineModel<{ pos: number; url: string } | null>({
    required: true,
});

const preview = ref<LinkPreview | null>(null);
const loading = ref(false);

watch(
    () => pasted.value?.url,
    async (url) => {
        preview.value = null;

        if (!url) {
            return;
        }

        loading.value = true;

        try {
            const found = await previewOf(url);

            if (pasted.value?.url === url) {
                preview.value = found;
            }
        } catch {
            // It stays a link, or a card of what the link says
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

/** The pasted line, while it is still just the link. */
const line = () => {
    const at = pasted.value;

    if (!at) {
        return null;
    }

    const node = props.editor.state.doc.nodeAt(at.pos);
    const href = node?.firstChild?.marks.find(
        (mark) => mark.type.name === 'link',
    )?.attrs.href;

    return node?.type.name === 'paragraph' &&
        node.childCount === 1 &&
        href === at.url
        ? { from: at.pos, to: at.pos + node.nodeSize }
        : null;
};

// Anything that changes the line, or moves away from it, settles it as a link
useEventListener(
    () => props.editor.view.dom,
    'keydown',
    (event: KeyboardEvent) => {
        if (pasted.value && event.key === 'Escape') {
            pasted.value = null;
        }
    },
);
watch(
    () => props.editor.state,
    () => {
        if (pasted.value && !line()) {
            pasted.value = null;
        }
    },
);

const where = computed(() => {
    if (!pasted.value || !line()) {
        return null;
    }

    const at = props.editor.view.coordsAtPos(pasted.value.pos + 1);

    return { left: `${at.left}px`, top: `${at.bottom + 6}px` };
});

const replaceWith = (content: Record<string, unknown>) => {
    const range = line();

    if (range) {
        props.editor.chain().focus().insertContentAt(range, content).run();
    }

    pasted.value = null;
};

const asCard = () =>
    replaceWith(
        cardBlock(
            preview.value?.url ?? pasted.value!.url,
            preview.value?.title ?? '',
        ),
    );

const asMap = async () => {
    const place = preview.value?.place;
    const url = pasted.value?.url;

    if (place && url) {
        replaceWith(await mapBlock(place, url));
    }
};

const choices = computed(() => {
    const list: {
        id: string;
        label: string;
        icon: Component;
        run: () => void;
    }[] = [];
    const kind = preview.value?.kind;

    if (kind === 'map') {
        list.push({ id: 'map', label: 'Map', icon: MapPin, run: asMap });
    }

    if (kind === 'image') {
        list.push({
            id: 'image',
            label: 'Picture',
            icon: Image,
            run: () =>
                replaceWith({
                    type: 'image',
                    attrs: { src: preview.value!.url },
                }),
        });
    }

    if (kind === 'video') {
        list.push({
            id: 'video',
            label: 'Video',
            icon: Video,
            run: () =>
                replaceWith({
                    type: 'video',
                    attrs: {
                        src: preview.value!.url,
                        title: shortLabel(preview.value!.url),
                    },
                }),
        });
    }

    list.push(
        { id: 'card', label: 'Card', icon: PanelTop, run: asCard },
        {
            id: 'link',
            label: 'Link',
            icon: Link2,
            run: () => (pasted.value = null),
        },
    );

    return list;
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="where"
            class="link-paste-menu"
            :style="where"
            data-test="link-paste-menu"
            @mousedown.prevent
        >
            <span class="link-paste-label">Show as</span>
            <button
                v-for="choice in choices"
                :key="choice.id"
                type="button"
                :data-test="`link-paste-${choice.id}`"
                @click="choice.run"
            >
                <component :is="choice.icon" class="size-3.5" />
                {{ choice.label }}
            </button>
            <LoaderCircle
                v-if="loading"
                class="text-muted-foreground size-3.5 animate-spin"
            />
        </div>
    </Teleport>
</template>

<style scoped>
/* A menu is for the screen, not for the printed note */
@media print {
    .link-paste-menu {
        display: none !important;
    }
}

.link-paste-menu {
    position: fixed;
    z-index: 50;
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 3px 4px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--popover);
    color: var(--popover-foreground);
    box-shadow: 0 4px 14px rgb(0 0 0 / 0.12);
    font-size: 0.75rem;
}

.link-paste-label {
    padding: 0 6px;
    color: var(--muted-foreground);
}

.link-paste-menu button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 24px;
    padding: 0 8px;
    border-radius: 5px;
    cursor: pointer;
}
.link-paste-menu button:hover {
    background: var(--accent);
}
.link-paste-menu button:first-of-type {
    background: var(--accent);
    font-weight: 500;
}
</style>
