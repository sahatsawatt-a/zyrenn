<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TableWorkspace from '@/features/tables/components/TableWorkspace.vue';
import { people } from '@/features/tables/composables/useTableState';
import { useTableStore } from '@/features/tables/composables/useTableStore';
import { mockColumns, mockRows } from '@/features/tables/lib/demoData';

// The scratch table: the same workspace the real tables use, opened on sample
// rows and saving nowhere (it has no ref_id to save to).
useTableStore().setInertiaStateData(
    structuredClone(mockColumns),
    structuredClone(mockRows),
    '',
);

// Sample people to assign rows to
people.value = [
    'Sahat S.',
    'Alex Mercer',
    'Sarah Connor',
    'Miles Morales',
    'Elena Rostova',
    'David Chen',
];
</script>

<template>
    <Head title="Table demo" />

    <div class="h-[calc(100vh-4rem)] p-4">
        <TableWorkspace />
    </div>
</template>
