<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus } from '@lucide/vue';
import ColumnHeaderMenu from '@/components/Table/ColumnHeaderMenu.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { columnIcon } from '@/composables/table/columnTypes';
import { formatCurrency } from '@/composables/table/useCellCell';
import { useTableStore } from '@/composables/table/useTableStore';
import type { ColumnMeta, TableSort } from '@/types';

defineProps<{
    columns: ColumnMeta[];
    sort: TableSort | null;
    isAllSelected: boolean;
    isPartiallySelected: boolean;
}>();

const emit = defineEmits<{
    (e: 'select-all', checked: boolean): void;
    (e: 'edit-column', column: ColumnMeta): void;
    (e: 'sort-column', name: string, direction: 'asc' | 'desc'): void;
    (e: 'hide-column', name: string): void;
    (e: 'duplicate-column', name: string): void;
    (e: 'delete-column', name: string): void;
    (e: 'resize-column', name: string, width: number): void;
    (e: 'open-add-column'): void;
}>();

const store = useTableStore();

// What the server will keep, so a drag never asks for a width it refuses
const MIN_WIDTH = 80;
const MAX_WIDTH = 600;
const bounded = (width: number) =>
    Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, Math.round(width)));

/** Drags a column's right edge. */
const startResize = (event: PointerEvent, column: ColumnMeta) => {
    const startX = event.clientX;
    const startWidth = column.width || 180;

    const move = (moved: PointerEvent) =>
        emit(
            'resize-column',
            column.name,
            bounded(startWidth + moved.clientX - startX),
        );

    const stop = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', stop);
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
};

/** How a value reads in its cell, near enough to measure it by. */
const shownAs = (column: ColumnMeta, value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    switch (column.type) {
        case 'currency':
            return formatCurrency(Number(value), column.currencySymbol || '$');
        case 'percent':
            return `${String(value)}%`;
        case 'multi_select':
            return Array.isArray(value) ? value.join('   ') : String(value);
        case 'boolean':
            return '';
        default:
            return String(value);
    }
};

/** Double-clicking the edge fits the column to its widest value. */
const autoFit = (column: ColumnMeta) => {
    const context = document.createElement('canvas').getContext('2d');

    if (!context) {
        return;
    }

    context.font = `14px ${getComputedStyle(document.body).fontFamily}`;

    const widest = [
        column.label,
        ...store.filteredRows.value.map((row) =>
            shownAs(column, row[column.name]),
        ),
    ].reduce(
        (most, text) => Math.max(most, context.measureText(text).width),
        0,
    );

    // Room for the cell's padding, and the icon and menu beside the label
    emit('resize-column', column.name, bounded(widest + 56));
};
</script>

<template>
    <div
        class="text-muted-foreground sticky top-0 z-20 flex h-9 w-full border-b bg-[color-mix(in_oklab,var(--muted)_70%,var(--card))] text-xs font-medium"
    >
        <div
            class="border-border/60 sticky left-0 z-10 flex w-14 shrink-0 items-center justify-center border-r bg-inherit"
        >
            <Checkbox
                :model-value="
                    isAllSelected
                        ? true
                        : isPartiallySelected
                          ? 'indeterminate'
                          : false
                "
                title="Select every row shown"
                @update:model-value="emit('select-all', !isAllSelected)"
            />
        </div>

        <div
            v-for="column in columns"
            :key="column.name"
            data-test="column-header"
            :data-column="column.name"
            :style="{ width: `${column.width || 180}px` }"
            class="group border-border/60 relative flex shrink-0 items-center gap-1.5 border-r pr-1 pl-3"
        >
            <component
                :is="columnIcon(column.type)"
                class="size-3.5 shrink-0 opacity-70"
            />
            <button
                type="button"
                class="text-foreground/80 hover:text-foreground min-w-0 truncate text-left"
                :title="`Edit ${column.label}`"
                @click="emit('edit-column', column)"
            >
                {{ column.label }}
            </button>
            <component
                :is="sort.direction === 'asc' ? ArrowUp : ArrowDown"
                v-if="sort?.column === column.name"
                class="text-primary size-3.5 shrink-0"
            />

            <ColumnHeaderMenu
                class="ml-auto"
                :column="column"
                @edit="emit('edit-column', column)"
                @sort="
                    (name, direction) => emit('sort-column', name, direction)
                "
                @hide="emit('hide-column', $event)"
                @duplicate="emit('duplicate-column', $event)"
                @delete="emit('delete-column', $event)"
            />

            <div
                class="hover:bg-primary active:bg-primary absolute inset-y-0 -right-px z-10 w-1 cursor-col-resize transition-colors"
                title="Drag to resize, double-click to fit"
                @pointerdown.stop.prevent="startResize($event, column)"
                @dblclick.stop.prevent="autoFit(column)"
            />
        </div>

        <button
            type="button"
            class="hover:bg-muted hover:text-foreground flex w-12 shrink-0 items-center justify-center transition-colors"
            title="Add a field"
            @click="emit('open-add-column')"
        >
            <Plus class="size-4" />
        </button>
    </div>
</template>
