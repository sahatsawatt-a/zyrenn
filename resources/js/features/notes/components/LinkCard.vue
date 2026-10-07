<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { NodeViewProps } from '@tiptap/vue-3';
import { ExternalLink, Link2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { LinkPreview } from '@/features/notes/lib/links';
import {
    hostOf,
    iconUrl,
    isSafeUrl,
    normaliseUrl,
    pictureUrl,
    previewOf,
    readFence,
    shortLabel,
    writeFence,
} from '@/features/notes/lib/links';

// A link shown as a card: the page's picture, title, what it says about
// itself, and the site. Kept as a ```link fence of the URL and its title, so
// Markdown and an agent still see where it goes and what it was called; the
// rest is read afresh from the page (through our server, LinkPreview).
const props = defineProps<NodeViewProps>();

const kept = computed(() => readFence(props.node.textContent));
const url = computed(() =>
    kept.value.url && isSafeUrl(kept.value.url) ? kept.value.url : '',
);

const preview = ref<LinkPreview | null>(null);
const failed = ref(false);

watch(
    url,
    async (now) => {
        preview.value = null;
        failed.value = false;

        if (now) {
            try {
                preview.value = await previewOf(now);
            } catch {
                failed.value = true;
            }
        }
    },
    { immediate: true },
);

// ---- an empty card (/link): asks for the link ----

const draft = ref('');
const problem = ref('');

const fill = async () => {
    const chosen = normaliseUrl(draft.value);

    if (!chosen || !/^https?:/i.test(chosen)) {
        problem.value = 'That isn’t a web address.';

        return;
    }

    let found: LinkPreview | null = null;

    try {
        found = await previewOf(chosen);
    } catch {
        // A card of the link alone
    }

    const from = props.getPos();

    if (typeof from === 'number') {
        props.editor
            .chain()
            .focus()
            .insertContentAt(
                { from: from + 1, to: from + props.node.nodeSize - 1 },
                writeFence({ url: chosen, title: found?.title ?? '' }),
            )
            .run();
    }
};

const title = computed(
    () => preview.value?.title || kept.value.title || shortLabel(url.value),
);
const site = computed(() => preview.value?.site || hostOf(url.value));
const picture = computed(() =>
    preview.value?.image ? pictureUrl(preview.value.image) : '',
);
const pictureBroken = ref(false);
const iconBroken = ref(false);

/** Back to an ordinary link, on a line of its own. */
const asLink = () => {
    const from = props.getPos();

    if (typeof from !== 'number') {
        return;
    }

    props.editor
        .chain()
        .focus()
        .insertContentAt(
            { from, to: from + props.node.nodeSize },
            {
                type: 'paragraph',
                content: [
                    {
                        type: 'text',
                        text: kept.value.title || shortLabel(url.value),
                        marks: [{ type: 'link', attrs: { href: url.value } }],
                    },
                ],
            },
        )
        .run();
};
</script>

<template>
    <NodeViewWrapper class="link-card-block" data-test="link-card">
        <div class="link-card" contenteditable="false">
            <a
                v-if="url"
                class="link-card-body"
                :href="url"
                target="_blank"
                rel="noopener noreferrer nofollow"
            >
                <div class="min-w-0 flex-1 space-y-1 p-3">
                    <p class="link-card-title" data-test="link-card-title">
                        {{ title }}
                    </p>
                    <p
                        v-if="preview?.description"
                        class="link-card-description"
                    >
                        {{ preview.description }}
                    </p>
                    <p class="link-card-site">
                        <img
                            v-if="!iconBroken"
                            :src="iconUrl(hostOf(url))"
                            alt=""
                            class="size-3.5 rounded-sm"
                            @error="iconBroken = true"
                        />
                        <Link2 v-else class="size-3.5" />
                        {{ site }}
                    </p>
                </div>
                <img
                    v-if="picture && !pictureBroken"
                    :src="picture"
                    alt=""
                    class="link-card-picture"
                    @error="pictureBroken = true"
                />
            </a>
            <form
                v-else-if="editor.isEditable && !kept.url"
                class="flex items-center gap-2 p-3"
                data-test="link-card-ask"
                @submit.prevent="fill"
            >
                <Link2 class="text-muted-foreground size-4 shrink-0" />
                <input
                    v-model="draft"
                    class="bg-background h-8 min-w-0 flex-1 rounded-md border px-2 text-sm"
                    placeholder="Paste or type a link"
                    aria-label="Link"
                    autofocus
                    data-test="link-card-url"
                    @keydown.stop
                />
                <button
                    type="submit"
                    class="bg-primary text-primary-foreground h-8 rounded-md px-3 text-xs"
                >
                    Add
                </button>
                <span v-if="problem" class="text-destructive text-xs">{{
                    problem
                }}</span>
            </form>
            <p v-else class="link-card-site p-3">
                Not a link that can be opened.
            </p>

            <div v-if="editor.isEditable && url" class="link-card-actions">
                <button
                    type="button"
                    data-test="link-card-as-link"
                    @click="asLink"
                >
                    Show as link
                </button>
                <a
                    v-if="url"
                    :href="url"
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    aria-label="Open"
                    ><ExternalLink class="size-3.5"
                /></a>
            </div>
        </div>

        <!-- The fence's own text: the URL and title, kept out of sight -->
        <pre class="link-card-source"><code /></pre>
    </NodeViewWrapper>
</template>

<style scoped>
.link-card-block {
    margin: 0.75rem 0;
}

.link-card {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background-color: var(--card);
    break-inside: avoid;
}

.link-card-body {
    display: flex;
    min-height: 5.5rem;
    color: inherit;
    text-decoration: none !important;
}
.link-card-body:hover {
    background-color: var(--accent);
}

.link-card-title {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.35;
}

.link-card-description {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    font-size: 0.75rem;
    line-height: 1.4;
    color: var(--muted-foreground);
}

.link-card-site {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
}

.link-card-picture {
    width: 30%;
    max-width: 12rem;
    flex-shrink: 0;
    object-fit: cover;
}

.link-card-actions {
    position: absolute;
    top: 0.375rem;
    right: 0.375rem;
    display: flex;
    gap: 0.25rem;
    padding: 0.125rem;
    border-radius: var(--radius-sm);
    background-color: var(--popover);
    box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
    opacity: 0;
    font-size: 0.75rem;
    transition: opacity 120ms;
}
.link-card:hover .link-card-actions,
.link-card-block.ProseMirror-selectednode .link-card-actions {
    opacity: 1;
}
.link-card-actions a,
.link-card-actions button {
    display: flex;
    align-items: center;
    padding: 0.125rem 0.375rem;
    color: var(--muted-foreground);
    cursor: pointer;
}
.link-card-actions a:hover,
.link-card-actions button:hover {
    color: var(--foreground);
}

.link-card-source {
    display: none;
}

@media print {
    .link-card-actions {
        display: none;
    }
}
</style>
