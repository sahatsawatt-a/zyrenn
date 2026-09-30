<script setup lang="ts">
import { Form, Head, router, setLayoutProps } from '@inertiajs/vue3';
import type { JSONContent } from '@tiptap/vue-3';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import {
    Check,
    ChevronsLeftRight,
    ChevronsRightLeft,
    Copy,
    Download,
    FileDown,
    HardDrive,
    Trash2,
} from '@lucide/vue';
import { toast } from 'vue-sonner';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watchEffect,
} from 'vue';
import NoteVersions from '@/components/Editor/NoteVersions.vue';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { uploadToDrive } from '@/lib/drive';
import { copyToClipboard, formatRelativeTime, xsrfToken } from '@/lib/utils';
import { destroy, index, pdf, show, update } from '@/routes/notes';

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

const editorRef = useTemplateRef('editorRef');
const titleInput = useTemplateRef('titleInput');

const title = ref(props.note.title);
let content: JSONContent | null = props.note.content;
const isWide = ref(props.note.is_wide);

type Field = 'title' | 'content' | 'is_wide';

// Fields changed since the last save request was sent
const dirty = new Set<Field>();
const status = ref<'saved' | 'saving' | 'unsaved' | 'error'>('saved');
const savedAt = ref(props.note.updated_at);
let inFlight: Promise<void> | null = null;

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

function toggleWide(): void {
    isWide.value = !isWide.value;
    dirty.add('is_wide');
    status.value = 'unsaved';
    // A layout switch is a single deliberate click, so save it right away
    void save();
}

function onContentUpdate(json: JSONContent): void {
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

// The server prints the saved note, so anything still unsaved goes first
async function printPdf(): Promise<File> {
    await save();

    if (status.value === 'error') {
        throw new Error(
            'Couldn’t save the latest changes, so the PDF would be out of date.',
        );
    }

    const url = pdf.url(props.note.ref_id, {
        query: { style: pdfStyle.value },
    });
    const response = await fetch(url, {
        headers: {
            Accept: 'application/pdf, application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        const body = response.headers
            .get('Content-Type')
            ?.includes('application/json')
            ? await response.json()
            : null;

        throw new Error(
            body?.message ??
                (response.status === 429
                    ? 'Too many exports at once. Try again in a minute.'
                    : 'Couldn’t print this note to PDF.'),
        );
    }

    const warning = response.headers.get('X-Pdf-Warning');

    if (warning) {
        toast.warning(warning);
    }

    // A slash would make the Drive keep only what follows it as the file's name
    return new File(
        [await response.blob()],
        `${(title.value.trim() || 'Untitled').replace(/[/\\]/g, '-')}.pdf`,
        { type: 'application/pdf' },
    );
}

const exporting = ref(false);

async function exportPdf(to: 'download' | 'drive'): Promise<void> {
    if (exporting.value) {
        return;
    }

    exporting.value = true;
    const loading = toast.loading('Printing to PDF…');

    try {
        const file = await printPdf();

        if (to === 'download') {
            const url = URL.createObjectURL(file);
            const link = document.createElement('a');
            link.href = url;
            link.download = file.name;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } else {
            const stored = await uploadToDrive(file);
            toast.success(`Saved “${stored.name}” to your Drive`, {
                action: {
                    label: 'Open',
                    onClick: () => window.open(stored.url, '_blank'),
                },
            });
        }
    } catch (error) {
        toast.error((error as Error).message);
    } finally {
        toast.dismiss(loading);
        exporting.value = false;
    }
}

// Flush edits before a pin or restore; false when they could not be saved
async function saveBeforeVersionChange(): Promise<boolean> {
    await save();

    return status.value !== 'error';
}

// The editor holds its own copy of the content, so reload to show the restored one
function onRestored(): void {
    window.location.reload();
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
    if (!props.note.title) {
        titleInput.value?.focus();
    }
});

onBeforeUnmount(() => {
    removeBeforeListener();
    void save();
});

const statusLabel = computed(() => {
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
        <div
            class="mx-auto w-full px-10 pt-8 print:max-w-none print:px-0 print:pt-0"
            :class="isWide ? 'max-w-none' : 'max-w-[800px]'"
        >
            <div
                class="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 print:hidden"
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
                </div>

                <div class="flex items-center gap-1">
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

                    <NoteVersions
                        :note-ref="note.ref_id"
                        :before-change="saveBeforeVersionChange"
                        @restored="onRestored"
                    />

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="exporting"
                                data-test="note-export"
                            >
                                <FileDown />
                                Export
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-60">
                            <DropdownMenuLabel>PDF style</DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                :model-value="pdfStyle"
                                @update:model-value="
                                    choosePdfStyle($event as PdfStyle)
                                "
                            >
                                <!-- Picking a style keeps the menu open for the export -->
                                <DropdownMenuRadioItem
                                    v-for="style in pdfStyles"
                                    :key="style.id"
                                    :value="style.id"
                                    :data-test="`note-export-style-${style.id}`"
                                    @select.prevent
                                >
                                    <span class="flex flex-col">
                                        <span>{{ style.label }}</span>
                                        <span
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ style.hint }}
                                        </span>
                                    </span>
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                data-test="note-export-download"
                                @select="exportPdf('download')"
                            >
                                <Download />
                                Download PDF
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                data-test="note-export-drive"
                                @select="exportPdf('drive')"
                            >
                                <HardDrive />
                                Save PDF to Drive
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

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
                class="placeholder:text-muted-foreground/60 w-full bg-transparent text-4xl font-bold tracking-tight outline-none print:hidden"
                @input="markDirty('title')"
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
            class="min-h-0! pt-2!"
            @update="onContentUpdate"
        />
    </div>
</template>
