<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import type { JSONContent } from '@tiptap/vue-3';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import { toast } from 'vue-sonner';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watchEffect,
} from 'vue';
import NoteToolbar from '@/components/Editor/NoteToolbar.vue';
import TiptapEditor from '@/components/Editor/TiptapEditor.vue';
import { fetchExport } from '@/lib/exporting';
import { formatRelativeTime, xsrfToken } from '@/lib/utils';
import PdfPreview from '@/components/PdfPreview.vue';
import PresenceAvatars from '@/components/PresenceAvatars.vue';
import { usePresence } from '@/composables/usePresence';
import { sharingIsOn, useShared } from '@/composables/useShared';
import { canChange, owned } from '@/lib/projects';
import { index as ownIndex, pdf, show, update } from '@/routes/notes';
import { index as projectIndex } from '@/routes/projects/notes';
import type { FolderPath } from '@/components/folders/MoveDialog.vue';

type Note = {
    ref_id: string;
    title: string;
    content: JSONContent | null;
    is_wide: boolean;
    updated_at: string;
    created_at: string;
    // The folder it is in (null = the top level), and who changed it last
    folder: string | null;
    edited_by: string | null;
};

const props = defineProps<{
    note: Note;
    // The folders the note sits in, top level first
    breadcrumbs: { ref_id: string; name: string }[];
    // Where it can be moved: only sent when the toolbar asks, to move it
    allFolders?: FolderPath[];
}>();

// A project's viewers read the note; nothing they do is saved
const editable = canChange();

// Set as this page deletes the note, which then hears of it like everyone else
let deletingHere = false;

// Who else has the note open
const { others } = usePresence(() => `notes.${props.note.ref_id}`, {
    // Deleted by someone else: close it rather than edit into nothing. One's
    // own delete is heard here too -- the request doesn't say which socket
    // sent it -- and goes where the server sends it, back to its folder
    deleted: () => {
        if (deletingHere) {
            return;
        }

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
//
// The PDF printer (?print=, see NotePdf) reads the note as the app keeps it:
// joining the live document, it could print before that had arrived.
const printing = document.documentElement.dataset.printStyle !== undefined;
const shared =
    sharingIsOn() && !printing ? useShared(`notes.${props.note.ref_id}`) : null;
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

// How the note looks on paper (NotePdf::STYLES, styled in print.css)
type PdfStyle = 'simple' | 'report';

const pdfStyles: { id: PdfStyle; label: string; hint: string }[] = [
    { id: 'simple', label: 'Simple', hint: 'As it looks on screen' },
    {
        id: 'report',
        label: 'Report',
        hint: 'Serif type, page header and numbers',
    },
];

const PDF_STYLE_KEY = 'zyrenn.pdfStyle';
const pdfStyle = ref<PdfStyle>('simple');

try {
    const saved = localStorage.getItem(PDF_STYLE_KEY);

    if (pdfStyles.some((style) => style.id === saved)) {
        pdfStyle.value = saved as PdfStyle;
    }
} catch {
    // Storage blocked: every export starts from Simple
}

function choosePdfStyle(style: PdfStyle): void {
    pdfStyle.value = style;

    try {
        localStorage.setItem(PDF_STYLE_KEY, style);
    } catch {
        // Storage blocked: remembered for this visit only
    }
}

// The report's running header and title block read these; a CSS string, so
// quotes and backslashes in the title are escaped
watchEffect(() => {
    const text = (title.value || 'Untitled').replace(/["\\]/g, '\\$&');
    document.documentElement.style.setProperty('--note-title', `"${text}"`);
});

onBeforeUnmount(() => {
    document.documentElement.style.removeProperty('--note-title');
});

const editedOn = computed(() =>
    new Date(savedAt.value).toLocaleDateString(undefined, {
        dateStyle: 'long',
    }),
);

// The server prints the saved note, so anything still unsaved goes first --
// shared, whatever anyone has typed that the app has not been handed yet
async function printPdf(): Promise<File> {
    if (shared) {
        await shared.flush({ everyone: true });
    } else {
        await save();
    }

    if (status.value === 'error') {
        throw new Error(
            'Couldn’t save the latest changes, so the PDF would be out of date.',
        );
    }

    return fetchExport(
        pdf.url(props.note.ref_id, { query: { style: pdfStyle.value } }),
        `${title.value.trim() || 'Untitled'}.pdf`,
        'application/pdf',
        'Couldn’t print this note to PDF.',
    );
}

// The PDF, printed and shown before it is downloaded or saved (PdfPreview)
const previewing = ref(false);

// Flush edits before a pin or restore; false when they could not be saved
async function saveBeforeVersionChange(): Promise<boolean> {
    // Shared, what everyone has typed goes to the app first
    if (shared) {
        await shared.flush({ everyone: true });

        return true;
    }

    await save();

    return status.value !== 'error';
}

// Shared, the restored note reaches every open editor through the
// collaboration server. Alone, the editor holds its own copy of the content,
// so reload to show the restored one.
function onRestored(): void {
    if (!shared) {
        window.location.reload();
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
        deletingHere = true;
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

// The colour of the dot beside it
const statusTone = computed<'ok' | 'busy' | 'offline' | 'error'>(() => {
    if (shared) {
        return shared.tone.value;
    }

    if (status.value === 'error') {
        return 'error';
    }

    return status.value === 'saved' ? 'ok' : 'busy';
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

    <div class="mx-8 flex flex-1 flex-col print:mx-0">
        <NoteToolbar
            :ref-id="note.ref_id"
            :title="title"
            :editable="editable"
            :status="{ label: statusLabel, tone: statusTone }"
            :others="others"
            :is-wide="isWide"
            :created-at="note.created_at"
            :saved-at="savedAt"
            :edited-by="note.edited_by"
            :folder="note.folder"
            :all-folders="allFolders"
            :stats="() => editorRef?.stats() ?? { words: 0, characters: 0 }"
            :before-version-change="saveBeforeVersionChange"
            @toggle-wide="toggleWide"
            @export="previewing = true"
            @restored="onRestored"
        />

        <PdfPreview
            v-model:open="previewing"
            :chosen="pdfStyle"
            :styles="pdfStyles"
            :print="printPdf"
            @update:chosen="choosePdfStyle($event as PdfStyle)"
        />

        <div
            class="mx-auto w-full px-10 pt-6 print:max-w-none print:px-0 print:pt-0"
            :class="isWide ? 'max-w-none' : 'max-w-[800px]'"
        >
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
                class="placeholder:text-muted-foreground/60 w-full bg-transparent text-4xl font-bold tracking-tight outline-none print:hidden"
                @input="onTitleInput"
                @keydown.enter.prevent="focusEditor"
            />
            <!-- An input is one line and clips a long title on paper; this wraps -->
            <h1
                class="note-print-title hidden text-4xl font-bold tracking-tight break-words print:block"
            >
                {{ title || 'Untitled' }}
            </h1>
            <!-- Shown under the title by the report style only (print.css) -->
            <p class="note-print-meta hidden">Last edited {{ editedOn }}</p>
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
