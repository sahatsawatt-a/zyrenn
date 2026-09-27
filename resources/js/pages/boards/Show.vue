<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { Check, Copy, Trash2 } from '@lucide/vue';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watchEffect } from 'vue';
import BoardCanvas from '@/components/Board/BoardCanvas.vue';
import type { Item } from '@/components/Board/items';
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
import { destroy, index, show, update } from '@/routes/boards';

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

const title = ref(props.board.title);
let items: Item[] = props.board.content?.items ?? [];

// Saving works the same way a note does: mark what changed, send it debounced,
// and never let an older request land after a newer one.
type Field = 'title' | 'content';

const dirty = new Set<Field>();
const status = ref<'saved' | 'saving' | 'unsaved' | 'error'>('saved');
const savedAt = ref(props.board.updated_at);
let inFlight: Promise<void> | null = null;

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
    items = next;
    markDirty('content');
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

    <BoardCanvas
        :items="props.board.content?.items ?? []"
        :title="title || 'Untitled board'"
        :status="statusLabel"
        @change="onBoardChange"
    >
        <template #actions>
            <input
                v-model="title"
                class="board-title"
                placeholder="Untitled board"
                data-test="board-title"
                @input="markDirty('title')"
            />

            <Button
                variant="ghost"
                size="sm"
                :title="`Copy this board's reference (${props.board.ref_id})`"
                @click="copyRefId"
            >
                <Check v-if="refCopied" class="text-emerald-600" />
                <Copy v-else />
            </Button>

            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="ghost" size="sm" data-test="delete-board">
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
