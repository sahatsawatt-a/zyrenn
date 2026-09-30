<script setup lang="ts">
import { Form, Head, router, setLayoutProps } from '@inertiajs/vue3';
import type { JSONContent } from '@tiptap/vue-3';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import {
    Check,
    ChevronsLeftRight,
    ChevronsRightLeft,
    Copy,
    Trash2,
} from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watchEffect,
} from 'vue';
import { toast } from 'vue-sonner';
import TiptapEditor from '@/components/Editor/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { copyToClipboard, formatRelativeTime, xsrfToken } from '@/lib/utils';
import PresenceAvatars from '@/components/PresenceAvatars.vue';
import { usePresence } from '@/composables/usePresence';
import { sharingIsOn, useShared } from '@/composables/useShared';
import { canChange, owned } from '@/lib/projects';
import { destroy, index as ownIndex, show, update } from '@/routes/notes';
import { index as projectIndex } from '@/routes/projects/notes';

type Note = {
    ref_id: string;
    title: string;
    content: JSONContent | null;
    is_wide: boolean;
    updated_at: string;
};

const props = defineProps<{
    note: Note;
    // The folders the note sits in, top level first
    breadcrumbs: { ref_id: string; name: string }[];
}>();

// A project's viewers read the note; nothing they do is saved
const editable = canChange();

// Who else has the note open
const { others } = usePresence(() => `notes.${props.note.ref_id}`, {
    // Deleted by someone else: close it rather than edit into nothing
    deleted: () => {
        toast.info('Someone deleted this note.');
        router.visit(index());
    },
});

const editorRef = useTemplateRef('editorRef');
const titleInput = useTemplateRef('titleInput');

const title = ref(props.note.title);
let content: JSONContent | null = props.note.content;
const isWide = ref(props.note.is_wide);

// Edited live with everyone who has it open, when the collaboration server is
// on: the body is the shared document, the title and width a map beside it.
// Otherwise the page saves as it goes, below.
const shared = sharingIsOn() ? useShared(`notes.${props.note.ref_id}`) : null;
// The title is written into the shared map only once the saved one has
// arrived: set before that, the two would race and either could win
const meta = shared?.document.getMap('meta');

meta?.observe(() => {
    const nextTitle = meta.get('title');
    const nextWide = meta.get('is_wide');

    if (typeof nextTitle === 'string' && nextTitle !== title.value) {
        title.value = nextTitle;
    }

    if (typeof nextWide === 'boolean') {
        isWide.value = nextWide;
    }
});

type Field = 'title' | 'content' | 'is_wide';

// Fields changed since the last save request was sent
const dirty = new Set<Field>();
const status = ref<'saved' | 'saving' | 'unsaved' | 'error'>('saved');
const savedAt = ref(props.note.updated_at);
let inFlight: Promise<void> | null = null;

// Back to the list the page came from: the project's, or the user's own
const index = owned(ownIndex, projectIndex);

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Notes', href: index() },
            ...props.breadcrumbs.map((crumb) => ({
                title: crumb.name,
                href: index({ query: { folder: crumb.ref_id } }),
            })),
            { title: title.value || 'Untitled', href: show(props.note.ref_id) },
        ],
    });
});

function sendSave(keepalive = false): Promise<void> | null {
    if (dirty.size === 0) {
        return null;
    }

    const payload: Record<string, unknown> = {};

    if (dirty.has('title')) {
        payload.title = title.value;
    }

    if (dirty.has('content')) {
        payload.content = content;
    }

    if (dirty.has('is_wide')) {
        payload.is_wide = isWide.value;
    }

    dirty.clear();

    return fetch(update.url(props.note.ref_id), {
        method: 'PATCH',
        keepalive,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(payload),
    }).then(async (response) => {
        if (!response.ok) {
            throw new Error(`Save failed with status ${response.status}`);
        }

        savedAt.value = (await response.json()).updated_at;
    });
}

// Saves are serialized so an older request can never land after a newer one
async function save(): Promise<void> {
    if (inFlight) {
        await inFlight;
    }

    const request = sendSave();

    if (!request) {
        return;
    }

    status.value = 'saving';
    inFlight = request
        .then(() => {
            status.value = dirty.size ? 'unsaved' : 'saved';
        })
        .catch(() => {
            dirty.add('title').add('content').add('is_wide');
            status.value = 'error';
        })
        .finally(() => {
            inFlight = null;
        });

    await inFlight;
}

const debouncedSave = useDebounceFn(save, 800);

function markDirty(field: Field): void {
    dirty.add(field);
    status.value = 'unsaved';
    void debouncedSave();
}

function onTitleInput(): void {
    if (meta) {
        meta.set('title', title.value);

        return;
    }

    markDirty('title');
}

function toggleWide(): void {
    isWide.value = !isWide.value;

    if (meta) {
        meta.set('is_wide', isWide.value);

        return;
    }

    dirty.add('is_wide');
    status.value = 'unsaved';
    // A layout switch is a single deliberate click, so save it right away
    void save();
}

function onContentUpdate(json: JSONContent): void {
    // Shared, the collaboration server keeps the document
    if (shared) {
        return;
    }

    content = json;
    markDirty('content');
}

const refCopied = ref(false);

// The ref_id is how this note is referenced elsewhere, e.g. in MCP requests
async function copyRefId(): Promise<void> {
    try {
        await copyToClipboard(props.note.ref_id);
        refCopied.value = true;
        setTimeout(() => (refCopied.value = false), 2000);
    } catch (error) {
        console.error('Failed to copy note reference: ', error);
    }
}

function focusEditor(): void {
    editorRef.value?.focus();
}

// Closing the tab: keepalive lets the request outlive the page
useEventListener(window, 'pagehide', () => {
    void sendSave(true);
});

// Navigating within the app: flush before the next page loads
const removeBeforeListener = router.on('before', (event) => {
    if (event.detail.visit.method === 'delete') {
        // The note is about to be deleted; saving it would 404
        dirty.clear();
    } else {
        void save();
    }
});

onMounted(() => {
    if (editable && !props.note.title) {
        titleInput.value?.focus();
    }
});

onBeforeUnmount(() => {
    removeBeforeListener();
    void save();
});

const statusLabel = computed(() => {
    if (!editable) {
        return 'View only';
    }

    if (shared) {
        return shared.label.value;
    }

    switch (status.value) {
        case 'saving':
            return 'Saving…';
        case 'unsaved':
            return 'Unsaved changes';
        case 'error':
            return 'Could not save — retrying on next edit';
        default:
            return `Saved ${formatRelativeTime(savedAt.value)}`;
    }
});
</script>

<template>
    <Head :title="title || 'Untitled'" />

    <div class="mx-8 flex flex-1 flex-col">
        <div
            class="mx-auto w-full px-10 pt-8"
            :class="isWide ? 'max-w-none' : 'max-w-[800px]'"
        >
            <div
                class="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground hover:bg-muted inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 font-mono text-xs transition-colors"
                        :title="refCopied ? 'Copied' : 'Copy note reference'"
                        aria-label="Copy note reference"
                        data-test="note-ref-id"
                        @click="copyRefId"
                    >
                        {{ note.ref_id }}
                        <Check
                            v-if="refCopied"
                            class="size-3.5 text-green-600"
                        />
                        <Copy v-else class="size-3.5" />
                    </button>
                    <span
                        class="text-xs whitespace-nowrap"
                        :class="
                            status === 'error'
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                        aria-live="polite"
                    >
                        {{ statusLabel }}
                    </span>
                    <PresenceAvatars :others="others" />
                </div>

                <div v-if="editable" class="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        :aria-pressed="isWide"
                        :title="
                            isWide
                                ? 'Switch to narrow width'
                                : 'Switch to full width'
                        "
                        @click="toggleWide"
                    >
                        <ChevronsRightLeft v-if="isWide" />
                        <ChevronsLeftRight v-else />
                        {{ isWide ? 'Narrow' : 'Wide' }}
                    </Button>

                    <Dialog>
                        <DialogTrigger as-child>
                            <Button variant="ghost" size="sm">
                                <Trash2 />
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Delete this note?</DialogTitle>
                                <DialogDescription>
                                    “{{ title || 'Untitled' }}” will be
                                    permanently deleted. This cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Form
                                    v-bind="destroy.form(note.ref_id)"
                                    v-slot="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        :disabled="processing"
                                    >
                                        Delete note
                                    </Button>
                                </Form>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <input
                ref="titleInput"
                v-model="title"
                type="text"
                maxlength="255"
                placeholder="Untitled"
                aria-label="Note title"
                :readonly="
                    !editable || (shared !== null && !shared.synced.value)
                "
                class="placeholder:text-muted-foreground/60 w-full bg-transparent text-4xl font-bold tracking-tight outline-none"
                @input="onTitleInput"
                @keydown.enter.prevent="focusEditor"
            />
        </div>

        <TiptapEditor
            ref="editorRef"
            :key="note.ref_id"
            :content="note.content"
            :wide="isWide"
            :editable="editable"
            :shared="shared"
            class="min-h-0! pt-2!"
            @update="onContentUpdate"
        />
    </div>
</template>
