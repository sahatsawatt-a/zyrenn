<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Check,
    Eye,
    FileDown,
    FolderInput,
    Hash,
    Link2,
    MoreHorizontal,
    MoveHorizontal,
    Trash2,
} from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import NoteVersions from '@/components/Editor/NoteVersions.vue';
import MoveDialog from '@/components/folders/MoveDialog.vue';
import type { FolderPath } from '@/components/folders/MoveDialog.vue';
import PresenceAvatars from '@/components/PresenceAvatars.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Member } from '@/lib/live';
import { copyToClipboard, formatRelativeTime } from '@/lib/utils';
import { destroy, show, update } from '@/routes/notes';

/**
 * A note's controls, kept in reach as the note scrolls: whether it is saved
 * and who else is here, its history and PDF, and -- under ⋯ -- its width,
 * links, folder, size and deleting it.
 */
const props = defineProps<{
    refId: string;
    title: string;
    editable: boolean;
    status: { label: string; tone: 'ok' | 'busy' | 'offline' | 'error' };
    others: Member[];
    isWide: boolean;
    createdAt: string;
    savedAt: string;
    editedBy: string | null;
    folder: string | null;
    allFolders?: FolderPath[];
    // How long the note is, read when the menu opens
    stats: () => { words: number; characters: number };
    // Flushes edits before a version is pinned or restored; false when it couldn't
    beforeVersionChange: () => Promise<boolean>;
}>();

const emit = defineEmits<{
    toggleWide: [];
    export: [];
    restored: [];
}>();

// ------------------------------------------------------------------ Stuck
// The bar gets an edge once the page has scrolled under it
const sentinel = useTemplateRef('sentinel');
const stuck = ref(false);

useIntersectionObserver(sentinel, ([entry]) => {
    stuck.value = !entry.isIntersecting;
});

// ------------------------------------------------------------------ Status
const dot = computed(
    () =>
        ({
            ok: 'bg-emerald-500',
            busy: 'bg-amber-500 animate-pulse',
            offline: 'bg-muted-foreground',
            error: 'bg-destructive',
        })[props.status.tone],
);

// ------------------------------------------------------------------ Menu
const size = ref({ words: 0, characters: 0 });

const onMenu = (open: boolean) => {
    if (open) size.value = props.stats();
};

// About 220 words a minute
const readingTime = computed(() =>
    size.value.words ? `${Math.max(1, Math.round(size.value.words / 220))} min read` : null,
);

const created = computed(() =>
    new Date(props.createdAt).toLocaleDateString(undefined, {
        dateStyle: 'medium',
    }),
);

const copied = ref<'link' | 'ref' | null>(null);

const copy = async (what: 'link' | 'ref') => {
    try {
        await copyToClipboard(
            what === 'link'
                ? new URL(show.url(props.refId), window.location.origin).href
                : props.refId,
        );
        copied.value = what;
        toast.success(what === 'link' ? 'Link copied' : 'Reference copied');
        setTimeout(() => (copied.value = null), 2000);
    } catch {
        toast.error('Couldn’t copy to the clipboard');
    }
};

// ------------------------------------------------------------------ Move
const moving = ref(false);
const busy = ref(false);

// The folders are only fetched once someone means to move it
const startMove = () =>
    router.reload({
        only: ['allFolders'],
        onSuccess: () => (moving.value = true),
    });

const move = (destination: string | null) => {
    busy.value = true;
    // Named now: the reload after moving leaves the folder list out again
    const path =
        props.allFolders?.find((folder) => folder.ref_id === destination)
            ?.path ?? 'Notes';

    router.patch(
        update.url(props.refId),
        { folder: destination },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                moving.value = false;
                toast.success(`Moved to ${path}`);
            },
            onFinish: () => (busy.value = false),
        },
    );
};

// ------------------------------------------------------------------ Delete
const deleting = ref(false);
const removing = ref(false);

// A DELETE visit, not a form's POST with _method: the note page knows a
// delete by its method, to stop saving into it and to ignore hearing of it
const remove = () =>
    router.delete(destroy.url(props.refId), {
        onStart: () => (removing.value = true),
        onFinish: () => (removing.value = false),
    });
</script>

<template>
    <div ref="sentinel" class="h-px print:hidden" aria-hidden="true" />

    <div
        class="bg-background/85 sticky top-0 z-30 -mx-8 mb-2 flex items-center gap-3 border-b px-8 py-2 backdrop-blur transition-colors print:hidden"
        :class="stuck ? 'border-border' : 'border-transparent'"
        data-test="note-toolbar"
    >
        <!-- Saved? Who else is here? -->
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <span
                class="text-muted-foreground flex min-w-0 items-center gap-2 text-xs"
                :class="{ 'text-destructive': status.tone === 'error' }"
                aria-live="polite"
                data-test="note-status"
            >
                <Eye v-if="!editable" class="size-3.5 shrink-0" />
                <span
                    v-else
                    class="size-2 shrink-0 rounded-full"
                    :class="dot"
                    aria-hidden="true"
                />
                <span class="truncate">{{ status.label }}</span>
            </span>
            <PresenceAvatars :others="others" />
        </div>

        <!-- What there is to do with it -->
        <div class="flex shrink-0 items-center gap-1">
            <NoteVersions
                v-if="editable"
                :note-ref="refId"
                :before-change="beforeVersionChange"
                @restored="emit('restored')"
            />

            <!-- Every PDF is seen before it is handed over -->
            <Button
                variant="outline"
                size="sm"
                data-test="note-export"
                @click="emit('export')"
            >
                <FileDown />
                <span class="hidden sm:inline">Export PDF</span>
            </Button>

            <DropdownMenu @update:open="onMenu">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="More"
                        data-test="note-more"
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-64">
                    <template v-if="editable">
                        <!-- Kept open, so the change can be seen and undone -->
                        <DropdownMenuItem
                            role="menuitemcheckbox"
                            :aria-checked="isWide"
                            data-test="note-wide"
                            @select.prevent="emit('toggleWide')"
                        >
                            <MoveHorizontal />
                            Full width
                            <Check v-if="isWide" class="ml-auto" />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </template>

                    <DropdownMenuItem
                        data-test="note-copy-link"
                        @select="copy('link')"
                    >
                        <Check v-if="copied === 'link'" />
                        <Link2 v-else />
                        Copy link
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        data-test="note-ref-id"
                        @select="copy('ref')"
                    >
                        <Check v-if="copied === 'ref'" />
                        <Hash v-else />
                        Copy reference
                        <span
                            class="text-muted-foreground ml-auto font-mono text-xs"
                            >{{ refId }}</span
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="editable"
                        data-test="note-move"
                        @select="startMove"
                    >
                        <FolderInput />
                        Move to…
                    </DropdownMenuItem>

                    <DropdownMenuSeparator />
                    <div
                        class="text-muted-foreground space-y-0.5 px-2 py-1.5 text-xs"
                        data-test="note-info"
                    >
                        <p class="text-foreground tabular-nums">
                            {{ size.words.toLocaleString() }}
                            {{ size.words === 1 ? 'word' : 'words' }}
                            <template v-if="readingTime">
                                · {{ readingTime }}</template
                            >
                        </p>
                        <p class="tabular-nums">
                            {{ size.characters.toLocaleString() }} characters
                        </p>
                        <p>Created {{ created }}</p>
                        <p>
                            Edited {{ formatRelativeTime(savedAt) }}
                            <template v-if="editedBy">
                                by {{ editedBy }}</template
                            >
                        </p>
                    </div>

                    <template v-if="editable">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            data-test="note-delete"
                            @select="deleting = true"
                        >
                            <Trash2 />
                            Delete
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>

    <MoveDialog
        v-if="allFolders"
        v-model:open="moving"
        :name="title || 'Untitled'"
        :folders="allFolders"
        :current="folder"
        root-label="Notes"
        :busy="busy"
        @submit="move"
    />

    <Dialog v-model:open="deleting">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete this note?</DialogTitle>
                <DialogDescription>
                    “{{ title || 'Untitled' }}” will be permanently deleted.
                    This cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="removing"
                    data-test="note-delete-confirm"
                    @click="remove"
                >
                    Delete note
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
