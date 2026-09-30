<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { Check, Copy, Trash2 } from '@lucide/vue';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import TableWorkspace from '@/components/Table/TableWorkspace.vue';
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
import { useTableStore } from '@/composables/table/useTableStore';
import { copyToClipboard, formatRelativeTime } from '@/lib/utils';
import { canChange, owned } from '@/lib/projects';
import { destroy, index as ownIndex, show } from '@/routes/tables';
import { index as projectIndex } from '@/routes/projects/tables';
import type { ColumnMeta, RowData, TableDensity } from '@/types';

type Table = {
    ref_id: string;
    title: string;
    density: TableDensity;
    updated_at: string;
};

const props = defineProps<{
    table: Table;
    columns: ColumnMeta[];
    rows: RowData[];
    // The folders the table sits in, top level first
    breadcrumbs: { ref_id: string; name: string }[];
}>();

const store = useTableStore();
const title = ref(props.table.title);
const savedAt = ref(props.table.updated_at);

// While a table is being opened, the density it arrives with is not a change
let opening = false;

const open = () => {
    opening = true;
    store.setInertiaStateData(
        props.columns,
        props.rows,
        props.table.ref_id,
        props.table.density,
    );
    title.value = props.table.title;
    savedAt.value = props.table.updated_at;
    opening = false;
};

open();

// Inertia keeps this page when going from one table to another; open the new one
watch(() => props.table.ref_id, open);

watch(store.density, (density) => {
    if (!opening) {
        void store.updateTable({ density });
    }
});

// The title is saved once the typing stops, like everything else here
const saveTitle = useDebounceFn(async () => {
    const saved = await store.updateTable({ title: title.value });

    if (saved) {
        savedAt.value = saved.updated_at;
    }
}, 800);

// Back to the list the page came from: the project's, or the user's own
const index = owned(ownIndex, projectIndex);

// A project's viewers read the rows; every control in the table is switched off
const editable = canChange();

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Tables', href: index() },
            ...props.breadcrumbs.map((crumb) => ({
                title: crumb.name,
                href: index({ query: { folder: crumb.ref_id } }),
            })),
            {
                title: title.value || 'Untitled',
                href: show(props.table.ref_id),
            },
        ],
    });
});

const statusLabel = computed(() => {
    if (!editable) {
        return 'View only';
    }

    switch (store.saveStatus.value) {
        case 'saving':
            return 'Saving…';
        case 'unsaved':
            return 'Unsaved changes';
        case 'error':
            return 'Could not save — try that again';
        default:
            return `Saved ${formatRelativeTime(savedAt.value)}`;
    }
});

const refCopied = ref(false);

// The ref_id is how this table is referenced elsewhere
async function copyRefId(): Promise<void> {
    try {
        await copyToClipboard(props.table.ref_id);
        refCopied.value = true;
        setTimeout(() => (refCopied.value = false), 2000);
    } catch (error) {
        console.error('Failed to copy table reference: ', error);
    }
}

// Anything still waiting to be sent goes before the page does
useEventListener(window, 'pagehide', () => void store.flush());

const removeBeforeListener = router.on('before', (event) => {
    // The table is about to be deleted; saving into it would 404
    if (event.detail.visit.method !== 'delete') {
        void store.flush();
    }
});

onBeforeUnmount(() => {
    removeBeforeListener();
    void store.flush();
});
</script>

<template>
    <Head :title="title || 'Untitled table'" />

    <div class="flex h-[calc(100vh-4rem)] min-h-0 flex-col gap-3 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <input
                v-model="title"
                class="table-title"
                placeholder="Untitled table"
                data-test="table-title"
                :readonly="!editable"
                @input="saveTitle"
            />
            <span
                class="text-muted-foreground text-xs"
                data-test="table-status"
            >
                {{ statusLabel }}
            </span>

            <div class="ml-auto flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    :title="`Copy this table's reference (${props.table.ref_id})`"
                    @click="copyRefId"
                >
                    <Check v-if="refCopied" class="text-emerald-600" />
                    <Copy v-else />
                </Button>

                <Dialog v-if="editable">
                    <DialogTrigger as-child>
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="delete-table"
                        >
                            <Trash2 />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete this table?</DialogTitle>
                            <DialogDescription>
                                “{{ title || 'Untitled table' }}” and every row
                                in it will be deleted for good.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <DialogClose as-child>
                                <Button variant="outline">Keep it</Button>
                            </DialogClose>
                            <Button
                                variant="destructive"
                                @click="
                                    router.delete(
                                        destroy.url(props.table.ref_id),
                                    )
                                "
                            >
                                Delete table
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <fieldset
            :disabled="!editable"
            class="m-0 min-h-0 min-w-0 flex-1 border-0 p-0"
            :class="{ 'read-only': !editable }"
        >
            <TableWorkspace />
        </fieldset>
    </div>
</template>

<style scoped>
/* A column's edge is dragged, not clicked, so the fieldset doesn't stop it */
.read-only :deep(.cursor-col-resize) {
    display: none;
}

.table-title {
    width: 20rem;
    height: 2.25rem;
    padding: 0 0.5rem;
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--foreground);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
}
.table-title:hover {
    border-color: var(--border);
}
.table-title:focus {
    outline: none;
    border-color: var(--primary);
}
</style>
