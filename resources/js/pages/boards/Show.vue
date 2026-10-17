<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { Check, Copy, FileDown, Trash2 } from '@lucide/vue';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
import BoardCanvas from '@/features/boards/canvas/BoardCanvas.vue';
import BoardView from '@/features/boards/drawing/BoardView.vue';
import type { Deck } from '@/features/boards/deck/deck';
import type { Item } from '@/features/boards/model/items';
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
import { useSharedItems } from '@/features/boards/canvas/useBoardSync';
import { usePresence } from '@/composables/usePresence';
import { sharingIsOn, useShared } from '@/composables/useShared';
import { canChange, currentProject, owned } from '@/lib/projects';
import {
    destroy,
    index as ownIndex,
    pdf,
    png,
    presenter,
    show,
    update,
} from '@/routes/boards';
import { presenterChannel } from '@/features/boards/deck/usePresenterLink';
import PdfPreview from '@/components/media/PdfPreview.vue';
import PrototypeBar from '@/features/prototypes/PrototypeBar.vue';
import ScreenFeedback from '@/features/prototypes/ScreenFeedback.vue';
import type { PrototypeSettings } from '@/features/prototypes/devices';
import NoteBacklinks from '@/features/notes/components/NoteBacklinks.vue';
import { fetchExport, nameFrom } from '@/lib/exporting';
import { zip } from '@/lib/zip';
import type { ZipEntry } from '@/lib/zip';
import { index as projectIndex } from '@/routes/projects/boards';
import { index as prototypesIndex } from '@/routes/projects/prototypes';
import { show as prototypeShow } from '@/routes/prototypes';

type Board = {
    ref_id: string;
    title: string;
    content: { items?: Item[]; deck?: Deck | null } | null;
    updated_at: string;
};

const props = defineProps<{
    board: Board;
    // The folders the board sits in, top level first
    breadcrumbs: { ref_id: string; name: string }[];
    // A prototype's own settings: a board drawn as clickable screens
    prototype: PrototypeSettings | null;
    // A prototype's project's tables, for its screens to list and save to
    tables: {
        ref_id: string;
        title: string;
        columns: { label: string; type: string }[];
    }[];
}>();

// A prototype is drawn here as a board, but is called what it is, and its
// way back is to its project's prototypes
const noun = props.prototype ? 'prototype' : 'board';
const untitled = `Untitled ${noun}`;
const listOf = () =>
    props.prototype && currentProject()
        ? prototypesIndex(currentProject()!.ref_id)
        : index();

// The canvas, for the prototype's bar to go to and add screens
const canvas = ref<InstanceType<typeof BoardCanvas> | null>(null);

// A project's viewers see the whole board, drawn as it is, and change nothing
const editable = canChange();
// Shapes of one's own are kept from this board; its project's owners and
// editors may keep one for everyone there
const myShapes = {
    board: props.board.ref_id,
    project: editable ? (currentProject()?.name ?? null) : null,
};

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

        toast.info(`Someone deleted this ${noun}.`);
        router.visit(listOf());
    },
});

const title = ref(props.board.title);
let items: Item[] = props.board.content?.items ?? [];
// The deck its frames make: one look, and what every slide says
const deck = ref<Deck | null>(props.board.content?.deck ?? null);

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

    const nextDeck = (meta.get('deck') as Deck | null | undefined) ?? null;

    if (JSON.stringify(nextDeck) !== JSON.stringify(deck.value)) {
        deck.value = nextDeck;
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
            props.prototype
                ? { title: 'Prototypes', href: listOf() }
                : { title: 'Boards', href: index() },
            ...props.breadcrumbs.map((crumb) => ({
                title: crumb.name,
                href: index({ query: { folder: crumb.ref_id } }),
            })),
            {
                title: title.value || 'Untitled',
                href: props.prototype
                    ? prototypeShow(props.board.ref_id)
                    : show(props.board.ref_id),
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
        payload.content = deck.value ? { items, deck: deck.value } : { items };
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

function onDeckChange(next: Deck | null): void {
    deck.value = next;

    if (meta) {
        // Kept as plain JSON, not the reactive copy the canvas handed over
        meta.set('deck', next && JSON.parse(JSON.stringify(next)));

        return;
    }

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
    videoCount.value = boardVideos().length;
    previewing.value = true;
}

/**
 * The board's videos kept in the Drive, each once: a PDF or a picture can
 * only show a still of them, so an export of a board with videos is a zip
 * with them beside it, to be played.
 */
const boardVideos = () => {
    const seen = new Set<string>();

    return (shared ? viewed.value : items).filter((item) => {
        const src = item.kind === 'video' ? item.src?.split('?')[0] : null;

        if (!src || !/^\/drive\/files\/\w+$/.test(src) || seen.has(src)) {
            return false;
        }

        seen.add(src);

        return true;
    });
};

// Counted as the dialog opens, so it says what the zip will hold
const videoCount = ref(0);

/** What was drawn, and the board's videos in a folder beside it, as one zip. */
async function zipWithVideos(files: File[]): Promise<File> {
    const entries: ZipEntry[] = files.map((file) => ({
        name: file.name,
        data: file,
    }));
    const taken = new Set<string>();

    for (const [index, video] of boardVideos().entries()) {
        const response = await fetch(video.src!.split('?')[0]);

        if (!response.ok) {
            throw new Error(`Couldn’t take video ${index + 1} along.`);
        }

        const data = await response.blob();
        const extension = data.type.split('/')[1]?.split(';')[0] || 'mp4';
        const wanted = nameFrom(response) ?? `Video ${index + 1}.${extension}`;
        let name = wanted.replace(/[/\\]/g, '-');

        // Two videos of one name stay two files
        for (let n = 2; taken.has(name); n++) {
            name = wanted.replace(/(\.[^.]+)?$/, ` (${n})$1`);
        }

        taken.add(name);
        entries.push({ name: `videos/${name}`, data });
    }

    return new File([await zip(entries)], `${boardName()}.zip`, {
        type: 'application/zip',
    });
}

const bundle = computed(() =>
    videoCount.value
        ? {
              note: `It comes as a zip, with the board’s ${videoCount.value === 1 ? 'video' : `${videoCount.value} videos`} beside the ${format.value === 'pdf' ? 'PDF' : 'pictures'}, to play.`,
              make: zipWithVideos,
          }
        : null,
);

const boardName = () => title.value.trim() || untitled;

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
    <Head :title="title || untitled" />

    <div
        v-if="!editable"
        class="flex h-[calc(100svh-6rem)] min-h-0 flex-col gap-3 p-4 md:p-6"
        data-test="board-read-only"
    >
        <div class="flex items-center gap-3">
            <h1 class="truncate text-lg font-semibold">
                {{ title || untitled }}
            </h1>
            <span class="text-muted-foreground text-xs whitespace-nowrap"
                >View only</span
            >
            <PresenceAvatars :others="others" />
            <NoteBacklinks folded kind="board" :page="props.board.ref_id" />
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
            <BoardView :items="viewed" :deck="deck" :title="title" />
        </div>
    </div>

    <BoardCanvas
        v-else
        ref="canvas"
        :items="props.board.content?.items ?? []"
        :title="title || untitled"
        :status="statusLabel"
        :shared="shared"
        :picture-of-frame="pictureOfFrame"
        :deck="deck"
        :deck-ready="shared === null || shared.synced.value"
        :presenter="{
            url: presenter.url(props.board.ref_id),
            channel: presenterChannel(props.board.ref_id),
        }"
        :my-shapes="myShapes"
        :prototype="props.prototype"
        :tables="props.tables"
        @change="onBoardChange"
        @deck="onDeckChange"
    >
        <template #title>
            <input
                v-model="title"
                class="board-title"
                :placeholder="untitled"
                data-test="board-title"
                :readonly="shared !== null && !shared.synced.value"
                @input="onTitleInput"
            />
        </template>

        <!-- A prototype's comments over its screens, and its plays' taps -->
        <template v-if="props.prototype" #overlay="{ scale, position }">
            <ScreenFeedback
                :board="props.board.ref_id"
                :frames="canvas?.board.frames.value ?? []"
                :scale="scale"
                :position="position"
                :can-change="editable"
            />
        </template>

        <template #actions>
            <PresenceAvatars :others="others" class="mr-1" />

            <PrototypeBar
                v-if="props.prototype"
                :board="props.board.ref_id"
                :prototype="props.prototype"
                :can-change="editable"
                :canvas="canvas"
            />

            <NoteBacklinks folded kind="board" :page="props.board.ref_id" />

            <Button
                variant="ghost"
                size="sm"
                class="hidden sm:inline-flex"
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
                <span class="hidden sm:inline">Export</span>
            </Button>

            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        size="sm"
                        :title="`Delete this ${noun}`"
                        data-test="delete-board"
                    >
                        <Trash2 />
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this {{ noun }}?</DialogTitle>
                        <DialogDescription>
                            “{{ title || untitled }}” and everything on it will
                            be deleted for good.
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
                            Delete {{ noun }}
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
        :bundle="bundle"
        wait-for-choice
    />
</template>

<style scoped>
.board-title {
    width: 14rem;
    /* On a phone it gives way to what is beside it */
    min-width: 0;
    flex-shrink: 1;
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
