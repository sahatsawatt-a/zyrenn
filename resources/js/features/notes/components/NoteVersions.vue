<script setup lang="ts">
import { History, Pin, PinOff, RotateCcw, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { formatRelativeTime, xsrfToken } from '@/lib/utils';
import {
    destroy,
    index,
    restore,
    store,
    update,
} from '@/routes/notes/versions';

type Version = {
    ref_id: string;
    title: string;
    pinned: boolean;
    label: string | null;
    created_at: string;
};

const props = defineProps<{
    noteRef: string;
    // Flush unsaved edits so a pin or restore sees the note as it is on screen
    beforeChange: () => Promise<boolean>;
}>();

const emit = defineEmits<{ restored: [] }>();

const open = ref(false);
const versions = ref<Version[]>([]);
const loading = ref(false);
const label = ref('');

async function call<T>(
    route: { url: string; method: string },
    body?: unknown,
): Promise<T> {
    const response = await fetch(route.url, {
        method: route.method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return response.status === 204 ? (undefined as T) : response.json();
}

async function load(): Promise<void> {
    loading.value = true;

    try {
        versions.value = (
            await call<{ versions: Version[] }>(index(props.noteRef))
        ).versions;
    } catch {
        toast.error('Couldn’t load the version history.');
    } finally {
        loading.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        void load();
    }
});

// Pinned versions first, each group newest first (the server sends newest first)
function ordered(list: Version[]): Version[] {
    return [...list.filter((v) => v.pinned), ...list.filter((v) => !v.pinned)];
}

async function pinCurrent(): Promise<void> {
    if (!(await props.beforeChange())) {
        toast.error('Couldn’t save the latest changes.');

        return;
    }

    try {
        await call(store(props.noteRef), { label: label.value.trim() || null });
        label.value = '';
        await load();
        toast.success('Pinned this version');
    } catch {
        toast.error('Couldn’t pin this version.');
    }
}

async function togglePin(version: Version): Promise<void> {
    try {
        const updated = await call<Version>(
            update({ note: props.noteRef, version: version.ref_id }),
            { pinned: !version.pinned },
        );
        Object.assign(version, updated);
    } catch {
        toast.error('Couldn’t update this version.');
    }
}

async function remove(version: Version): Promise<void> {
    try {
        await call(destroy({ note: props.noteRef, version: version.ref_id }));
        versions.value = versions.value.filter((v) => v !== version);
    } catch {
        toast.error('Couldn’t delete this version.');
    }
}

async function restoreVersion(version: Version): Promise<void> {
    if (!(await props.beforeChange())) {
        toast.error('Couldn’t save the latest changes.');

        return;
    }

    try {
        await call(restore({ note: props.noteRef, version: version.ref_id }));
        open.value = false;
        emit('restored');
    } catch {
        toast.error('Couldn’t restore this version.');
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetTrigger as-child>
            <Button variant="ghost" size="sm" data-test="note-history">
                <History />
                History
            </Button>
        </SheetTrigger>
        <SheetContent class="flex w-96 flex-col gap-0 sm:max-w-sm">
            <SheetHeader>
                <SheetTitle>Version history</SheetTitle>
                <SheetDescription>
                    Snapshots are taken as you edit. Pin one to keep it;
                    unpinned snapshots are eventually replaced by newer ones.
                </SheetDescription>
            </SheetHeader>

            <form class="flex gap-2 px-4 pb-3" @submit.prevent="pinCurrent">
                <input
                    v-model="label"
                    type="text"
                    maxlength="100"
                    placeholder="Label (optional)"
                    aria-label="Label for the pinned version"
                    class="bg-background min-w-0 flex-1 rounded-md border px-3 py-1.5 text-sm outline-none"
                />
                <Button type="submit" size="sm" data-test="note-pin-current">
                    <Pin />
                    Pin now
                </Button>
            </form>

            <ul class="flex-1 space-y-1 overflow-y-auto px-4 pb-4">
                <li
                    v-if="!loading && versions.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No versions yet.
                </li>
                <li
                    v-for="version in ordered(versions)"
                    :key="version.ref_id"
                    class="flex items-center gap-2 rounded-md border px-3 py-2"
                    data-test="note-version"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 text-sm">
                            <Pin
                                v-if="version.pinned"
                                class="size-3.5 shrink-0 text-amber-500"
                            />
                            <span class="truncate font-medium">
                                {{
                                    version.label || version.title || 'Untitled'
                                }}
                            </span>
                        </div>
                        <div class="text-muted-foreground text-xs">
                            {{ formatRelativeTime(version.created_at) }}
                        </div>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        :title="version.pinned ? 'Unpin' : 'Pin'"
                        :aria-label="version.pinned ? 'Unpin' : 'Pin'"
                        data-test="note-version-pin"
                        @click="togglePin(version)"
                    >
                        <PinOff v-if="version.pinned" />
                        <Pin v-else />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        title="Restore"
                        aria-label="Restore this version"
                        data-test="note-version-restore"
                        @click="restoreVersion(version)"
                    >
                        <RotateCcw />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        title="Delete"
                        aria-label="Delete this version"
                        @click="remove(version)"
                    >
                        <Trash2 />
                    </Button>
                </li>
            </ul>
        </SheetContent>
    </Sheet>
</template>
