<script setup lang="ts">
import type { Editor as CoreEditor } from '@tiptap/core';
import type { Editor } from '@tiptap/vue-3';
import { BubbleMenu } from '@tiptap/vue-3/menus';
import {
    Check,
    Copy,
    ExternalLink,
    MapPin,
    PanelTop,
    Pencil,
    Unlink,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    cardBlock,
    hostOf,
    iconUrl,
    looksLikeMap,
    mapBlock,
    normaliseUrl,
    previewOf,
    shortLabel,
} from '@/features/notes/lib/links';

// The caret in a link: where it goes, written short, to open in a new tab;
// copy it; change where it goes and what it says; show it as a card (or, a
// map link, as a map); or take the link off.

const props = defineProps<{ editor: Editor }>();

const editing = ref(false);
const hrefDraft = ref('');
const textDraft = ref('');
const problem = ref('');
const copied = ref(false);
const iconBroken = ref(false);
const hrefInput = ref<HTMLInputElement>();

/**
 * The link the caret is in -- or just before, at its first letter, where a
 * click on its icon puts it.
 */
const linkAt = (editor: CoreEditor): string => {
    const { $from, empty } = editor.state.selection;
    const marks = [
        ...$from.marks(),
        ...(empty ? ($from.nodeAfter?.marks ?? []) : []),
    ];

    return (
        (marks.find((mark) => mark.type.name === 'link')?.attrs.href as
            | string
            | undefined) ?? ''
    );
};

const href = computed(() => linkAt(props.editor));

const shouldShow = ({ editor }: { editor: CoreEditor }) =>
    editor.isEditable && linkAt(editor) !== '';

/** The whole of the link the caret is in, chosen. */
const selectLink = () =>
    props.editor.chain().focus().extendMarkRange('link').run();

const startEditing = async () => {
    selectLink();
    const { from, to } = props.editor.state.selection;
    hrefDraft.value = href.value;
    textDraft.value = props.editor.state.doc.textBetween(from, to);
    problem.value = '';
    editing.value = true;
    await nextTick();
    hrefInput.value?.select();
};

const save = () => {
    const url = normaliseUrl(hrefDraft.value);

    if (!url) {
        problem.value = 'That isn’t a link that can be opened.';

        return;
    }

    const text = textDraft.value.trim() || shortLabel(url);
    selectLink();
    const { from } = props.editor.state.selection;

    props.editor
        .chain()
        .focus()
        .insertContent({
            type: 'text',
            text,
            marks: [{ type: 'link', attrs: { href: url } }],
        })
        // The caret after the link, not still in it
        .setTextSelection(from + text.length)
        .unsetMark('link')
        .run();
    editing.value = false;
};

/**
 * The link shown as a card or a map instead. Alone on its line, the line
 * becomes the block; in a sentence, the block goes under it and the sentence
 * keeps its words.
 */
const showAs = async (kind: 'card' | 'map') => {
    const url = href.value;
    let block: Record<string, unknown> | null = null;

    if (kind === 'map') {
        const place = (await previewOf(url).catch(() => null))?.place;
        block = place ? await mapBlock(place, url) : null;
    }

    selectLink();
    const { $from, from, to } = props.editor.state.selection;
    const words = props.editor.state.doc.textBetween(from, to);
    const line = $from.parent;
    const alone =
        line.type.name === 'paragraph' &&
        line.textContent.trim() === words.trim();

    // Its words are its title, unless they are only the address written short
    block ??= cardBlock(url, words === shortLabel(url) ? '' : words);

    props.editor
        .chain()
        .focus()
        .insertContentAt(
            alone
                ? { from: $from.before(), to: $from.after() }
                : { from: $from.after(), to: $from.after() },
            block,
        )
        .run();
};

const remove = () => {
    props.editor.chain().focus().extendMarkRange('link').unsetLink().run();
};

const copy = async () => {
    await navigator.clipboard.writeText(href.value);
    copied.value = true;
    toast.success('Link copied');
    setTimeout(() => (copied.value = false), 1500);
};
</script>

<template>
    <BubbleMenu
        :editor="editor"
        plugin-key="linkMenu"
        :should-show="shouldShow"
        :options="{ placement: 'bottom-start', offset: 6 }"
        @hide="editing = false"
    >
        <form
            v-if="editing"
            class="link-menu link-menu-form"
            data-test="link-edit"
            @submit.prevent="save"
            @keydown.escape.prevent="editing = false"
        >
            <input
                ref="hrefInput"
                v-model="hrefDraft"
                placeholder="https://"
                aria-label="Link to"
                data-test="link-edit-href"
            />
            <input
                v-model="textDraft"
                placeholder="Text"
                aria-label="Text shown"
                data-test="link-edit-text"
            />
            <p v-if="problem" class="link-menu-problem">{{ problem }}</p>
            <div class="flex justify-end gap-1">
                <button type="button" @click="editing = false">Cancel</button>
                <button
                    type="submit"
                    class="is-primary"
                    data-test="link-edit-save"
                >
                    Save
                </button>
            </div>
        </form>
        <div v-else class="link-menu" data-test="link-menu" @mousedown.prevent>
            <a
                class="link-menu-href"
                :href="href"
                target="_blank"
                rel="noopener noreferrer nofollow"
                :title="href"
                data-test="link-open"
            >
                <img
                    v-if="!iconBroken && /^https?:/i.test(href)"
                    :src="iconUrl(hostOf(href))"
                    alt=""
                    class="size-3.5 rounded-sm"
                    @error="iconBroken = true"
                />
                <ExternalLink v-else class="size-3.5" />
                <span class="truncate">{{ shortLabel(href, 36) }}</span>
            </a>
            <span class="link-menu-divider" />
            <button
                type="button"
                title="Copy link"
                aria-label="Copy link"
                @click="copy"
            >
                <Check v-if="copied" class="size-4" />
                <Copy v-else class="size-4" />
            </button>
            <button
                type="button"
                title="Edit link"
                aria-label="Edit link"
                data-test="link-edit-open"
                @click="startEditing"
            >
                <Pencil class="size-4" />
            </button>
            <button
                type="button"
                title="Show as a card"
                aria-label="Show as a card"
                data-test="link-as-card"
                @click="showAs('card')"
            >
                <PanelTop class="size-4" />
            </button>
            <button
                v-if="looksLikeMap(href)"
                type="button"
                title="Show as a map"
                aria-label="Show as a map"
                data-test="link-as-map"
                @click="showAs('map')"
            >
                <MapPin class="size-4" />
            </button>
            <button
                type="button"
                title="Remove link"
                aria-label="Remove link"
                data-test="link-remove"
                @click="remove"
            >
                <Unlink class="size-4" />
            </button>
        </div>
    </BubbleMenu>
</template>

<style scoped>
/* A menu is for the screen: a note printed with the caret in a link has none */
@media print {
    .link-menu {
        display: none !important;
    }
}

.link-menu {
    display: flex;
    align-items: center;
    gap: 2px;
    max-width: 22rem;
    padding: 3px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--popover);
    color: var(--popover-foreground);
    box-shadow: 0 4px 14px rgb(0 0 0 / 0.12);
    font-size: 0.8125rem;
}

.link-menu-form {
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
    width: 20rem;
    padding: 8px;
}
.link-menu-form input {
    height: 2rem;
    padding: 0 0.5rem;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--background);
}

.link-menu-href {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 6px;
    padding: 2px 8px;
    border-radius: 5px;
    color: var(--primary);
    text-decoration: none;
}
.link-menu-href:hover {
    background: var(--accent);
}

.link-menu button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 26px;
    padding: 0 6px;
    border-radius: 5px;
    color: var(--muted-foreground);
    cursor: pointer;
}
.link-menu button:hover {
    background: var(--accent);
    color: var(--foreground);
}
.link-menu button.is-primary {
    background: var(--primary);
    color: var(--primary-foreground);
}

.link-menu-divider {
    width: 1px;
    height: 18px;
    margin: 0 2px;
    background: var(--border);
}

.link-menu-problem {
    font-size: 0.75rem;
    color: var(--destructive);
}
</style>
