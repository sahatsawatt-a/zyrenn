<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { Check, Copy, FileDown, Trash2 } from '@lucide/vue';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
import BoardCanvas from '@/features/boards/components/BoardCanvas.vue';
import BoardView from '@/features/boards/components/BoardView.vue';
import type { Item } from '@/features/boards/composables/items';
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
import PresenceAvatars from '@/components/common/PresenceAvatars.vue';
import { useSharedItems } from '@/features/boards/composables/useBoardSync';
import { usePresence } from '@/composables/usePresence';
import { sharingIsOn, useShared } from '@/composables/useShared';
import { canChange, owned } from '@/lib/projects';
import {
    destroy,
    index as ownIndex,
    pdf,
    png,
    show,
    update,
} from '@/routes/boards';
import PdfPreview from '@/components/media/PdfPreview.vue';
import { fetchExport } from '@/lib/exporting';
import { index as projectIndex } from '@/routes/projects/boards';

type Board = {
    ref_id: string;
    title: string;
    content: { items?: Item[] } | null;
    updated_at: string;
};

const props = defineProps<{
    board: Board;
    // The folders the board sits in, top level first
    breadcrumbs: { ref_id: string; name: string }[];
}>();

// A project's viewers see the whole board, drawn as it is, and change nothing
const editable = canChange();

// Set as this page deletes the board, which then hears of it like everyone else
let deletingHere = false;

// Who else has the board open
const { others } = usePresence(() => `boards.${props.board.ref_id}`, {
    // Deleted by someone else: close it rather than edit into nothing. One's
    // own delete is heard here too, and goes where the server sends it
    deleted: () => {
        if (deletingHere) {
            return;
        }

        toast.info('Someone deleted this board.');
        router.visit(index());
    },
});

const title = ref(props.board.title);
let items: Item[] = props.board.content?.items ?? [];

// Drawn on live with everyone who has it open, when the collaboration server
// is on: the items are the shared document, the title a map beside it. The
// title is written there only once the saved one has arrived, so the two
// never race. Otherwise the page saves as it goes, below.
const shared = sharingIsOn() ? useShared(`boards.${props.board.ref_id}`) : null;
const meta = shared?.document.getMap('meta');

meta?.observe(() => {
    const next = meta.get('title');

    if (typeof next === 'string' && next !== title.value) {
        title.value = next;
    }
});

// A viewer's board follows the others' drawing as it happens
const viewed = shared
    ? useSharedItems(shared.document, items)
    : ref<Item[]>(items);

// Saving works the same way a note does: mark what changed, send it debounced,
// and never let an older request land after a newer one.
type Field = 'title' | 'content';

const dirty = new Set<Field>();
const status = ref<'saved' | 'saving' | 'unsaved' | 'error'>('saved');
const savedAt = ref(props.board.updated_at);
let inFlight: Promise<void> | null = null;

// Back to the list the page came from: the project's, or the user's own
const index = owned(ownIndex, projectIndex);

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Boards', href: index() },
            ...props.breadcrumbs.map((crumb) => ({
                title: crumb.name,
                href: index({ query: { folder: crumb.ref_id } }),
            })),
            {
                title: title.value || 'Untitled',
                href: show(props.board.ref_id),
            },
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
        payload.content = { items };
    }

    dirty.clear();

    return fetch(update.url(props.board.ref_id), {
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
            dirty.add('title').add('content');
            status.value = 'error';
        })
        .finally(() => {
            inFlight = null;
        });

    await inFlight;
}

// A board changes on every drag frame, so it waits longer than a note's typing
const debouncedSave = useDebounceFn(save, 1200);

function markDirty(field: Field): void {
    dirty.add(field);
    status.value = 'unsaved';
    void debouncedSave();
}

function onBoardChange(next: Item[]): void {
    // Shared, the collaboration server keeps the board
    if (shared) {
        return;
    }

    items = next;
    markDirty('content');
}

function onTitleInput(): void {
    if (meta) {
        meta.set('title', title.value);

        return;
    }

    markDirty('title');
}

// The server draws the saved board, so anything still unsaved goes first --
// shared, whatever anyone has drawn that the app has not been handed yet.
// Shown before it is handed over, the way a note's PDF is (PdfPreview).
type Format = 'pdf' | 'png';

/** What the server is about to draw: the board as everyone has it now. */
const currentFrames = () =>
    (shared ? viewed.value : items).filter((item) => item.kind === 'frame');

// Counted as the dialog opens, so the choice says what it will make
const frameCount = ref(0);

const formats = computed<{ id: Format; label: string; hint: string }[]>(() => [
    {
        id: 'pdf',
        label: 'PDF',
        hint: frameCount.value
            ? `One file, a page for each of the ${frameCount.value} frame${frameCount.value === 1 ? '' : 's'}`
            : 'One file, the whole board on a page',
    },
    {
        id: 'png',
        label: 'Pictures',
        hint: frameCount.value
            ? `A PNG of each of the ${frameCount.value} frame${frameCount.value === 1 ? '' : 's'}`
            : 'A PNG of the whole board',
    },
]);

const format = ref<Format>('pdf');
const previewing = ref(false);
const drawing = ref('Drawing the board…');

function openExport(): void {
    frameCount.value = currentFrames().length;
    previewing.value = true;
}

const boardName = () => title.value.trim() || 'Untitled board';

/**
 * Anything still unsaved goes to the app first -- shared, whatever anyone
 * has drawn that it has not been handed yet -- since the server draws what
 * it has.
 */
async function freshen(): Promise<void> {
    if (shared) {
        await shared.flush({ everyone: true });
    } else if (editable) {
        await save();
    }

    if (status.value === 'error') {
        throw new Error(
            'Couldn’t save the latest changes, so the export would be out of date.',
        );
    }
}

/**
 * One frame as a picture. Numbered when it is one of a set, so they list in
 * the order they are presented and two frames of one name stay two files.
 */
function drawFrame(frame: Item, number?: number): Promise<File> {
    const named = frame.text?.trim() || 'Frame';

    return fetchExport(
        png.url(props.board.ref_id, { query: { frame: frame.id } }),
        `${boardName()} - ${number ? `${number}. ` : ''}${named}.png`,
        'image/png',
        `Couldn’t draw “${named}” as a picture.`,
    );
}

/** Every frame's picture, a couple at a time, in the order they are presented. */
async function drawFrames(frames: Item[]): Promise<File[]> {
    const pictures: File[] = [];
    let next = 0;
    let done = 0;

    drawing.value = `Drawing frame 1 of ${frames.length}…`;

    const worker = async () => {
        while (next < frames.length) {
            const index = next++;

            pictures[index] = await drawFrame(frames[index], index + 1);
            done++;
            drawing.value = `Drawing frame ${Math.min(done + 1, frames.length)} of ${frames.length}…`;
        }
    };

    // Each holds a browser on the server for a moment, so not all at once
    await Promise.all([worker(), worker()]);

    return pictures;
}

async function printBoard(): Promise<File | File[]> {
    drawing.value = 'Drawing the board…';
    await freshen();

    const type = format.value;
    const frames = currentFrames();

    if (type === 'png' && frames.length) {
        return drawFrames(frames);
    }

    return fetchExport(
        (type === 'pdf' ? pdf : png).url(props.board.ref_id),
        `${boardName()}.${type}`,
        type === 'pdf' ? 'application/pdf' : 'image/png',
        `Couldn’t draw this board as a ${type.toUpperCase()}.`,
    );
}

/** A frame right-clicked on the canvas, as a picture of its own. */
async function pictureOfFrame(frame: Item): Promise<File> {
    await freshen();

    return drawFrame(frame);
}

const refCopied = ref(false);

// The ref_id is how this board is referenced elsewhere
async function copyRefId(): Promise<void> {
    try {
        await copyToClipboard(props.board.ref_id);
        refCopied.value = true;
        setTimeout(() => (refCopied.value = false), 2000);
    } catch (error) {
        console.error('Failed to copy board reference: ', error);
    }
}

// Closing the tab: keepalive lets the request outlive the page
useEventListener(window, 'pagehide', () => {
    void sendSave(true);
});

const removeBeforeListener = router.on('before', (event) => {
    if (event.detail.visit.method === 'delete') {
        // The board is about to be deleted; saving it would 404
        deletingHere = true;
        dirty.clear();
    } else {
        void save();
    }
});

onBeforeUnmount(() => {
    removeBeforeListener();
    void save();
});

const statusLabel = computed(() => {
    if (shared) {
        return shared.label.value;
    }

    switch (status.value) {
        case 'saving':
            return 'Saving…';
        case 'unsaved':
            return 'Unsaved changes';
        case 'error':
            return 'Could not save — retrying on next change';
        default:
            return `Saved ${formatRelativeTime(savedAt.value)}`;
    }
});
</script>

<template>
    <Head :title="title || 'Untitled board'" />

    <div
        v-if="!editable"
        class="flex h-[calc(100svh-6rem)] min-h-0 flex-col gap-3 p-4 md:p-6"
        data-test="board-read-only"
    >
        <div class="flex items-center gap-3">
            <h1 class="truncate text-lg font-semibold">
                {{ title || 'Untitled board' }}
            </h1>
            <span class="text-muted-foreground text-xs whitespace-nowrap"
                >View only</span
            >
            <PresenceAvatars :others="others" />
            <div class="ml-auto">
                <Button
                    variant="ghost"
                    size="sm"
                    data-test="board-export"
                    @click="openExport"
                >
                    <FileDown />
                    Export
                </Button>
            </div>
            <Button
                variant="ghost"
                size="sm"
                :title="`Copy this board's reference (${props.board.ref_id})`"
                @click="copyRefId"
            >
                <Check v-if="refCopied" class="text-emerald-600" />
                <Copy v-else />
            </Button>
        </div>
        <div class="min-h-0 flex-1 overflow-hidden rounded-xl border">
            <BoardView :items="viewed" />
        </div>
    </div>

    <BoardCanvas
        v-else
        :items="props.board.content?.items ?? []"
        :title="title || 'Untitled board'"
        :status="statusLabel"
        :shared="shared"
        :picture-of-frame="pictureOfFrame"
        @change="onBoardChange"
    >
        <template #title>
            <input
                v-model="title"
                class="board-title"
                placeholder="Untitled board"
                data-test="board-title"
                :readonly="shared !== null && !shared.synced.value"
                @input="onTitleInput"
            />
        </template>

        <template #actions>
            <PresenceAvatars :others="others" class="mr-1" />

            <Button
                variant="ghost"
                size="sm"
                :title="`Copy this board's reference (${props.board.ref_id})`"
                @click="copyRefId"
            >
                <Check v-if="refCopied" class="text-emerald-600" />
                <Copy v-else />
            </Button>

            <Button
                variant="ghost"
                size="sm"
                title="Export as a PDF or a picture"
                data-test="board-export"
                @click="openExport"
            >
                <FileDown />
                Export
            </Button>

            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        size="sm"
                        title="Delete this board"
                        data-test="delete-board"
                    >
                        <Trash2 />
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this board?</DialogTitle>
                        <DialogDescription>
                            “{{ title || 'Untitled board' }}” and everything on
                            it will be deleted for good.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button variant="outline">Keep it</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            @click="
                                router.delete(destroy.url(props.board.ref_id))
                            "
                        >
                            Delete board
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </template>
    </BoardCanvas>

    <PdfPreview
        v-model:open="previewing"
        v-model:chosen="format"
        :styles="formats"
        :print="printBoard"
        title="Export preview"
        styles-label="Export as"
        :working="drawing"
        wait-for-choice
    />
</template>

<style scoped>
.board-title {
    width: 14rem;
    height: 2rem;
    padding: 0 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--foreground);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
}
.board-title:hover {
    border-color: var(--border);
}
.board-title:focus {
    outline: none;
    border-color: var(--primary);
}
</style>
