<script setup lang="ts">
import CellRegistry from './CellRegistry.vue';
import { Checkbox } from '@/components/ui/checkbox';
import type { ColumnMeta, RowData } from '@/types';

defineProps<{
    row: RowData;
    rowIndex: number;
    columns: ColumnMeta[];
    isSelected: boolean;
    densityPaddingClass: string;
}>();

const emit = defineEmits<{
    (e: 'toggle-row-selection', rowId: number): void;
    (
        e: 'patch-cell',
        rowId: number,
        columnName: string,
        value: unknown,
        index: number,
    ): void;
}>();
</script>

<template>
    <!-- The row's colour is solid, so the pinned number cell can inherit it -->
    <div
        data-test="table-row"
        class="group/row border-border/60 flex border-b transition-colors"
        :class="
            isSelected
                ? 'bg-[color-mix(in_oklab,var(--primary)_8%,var(--card))]'
                : 'bg-card hover:bg-[color-mix(in_oklab,var(--muted)_60%,var(--card))]'
        "
    >
        <div
            class="border-border/60 text-muted-foreground sticky left-0 z-10 flex w-14 shrink-0 items-center justify-center border-r bg-inherit text-xs tabular-nums"
        >
            <span :class="isSelected ? 'hidden' : 'group-hover/row:hidden'">
                {{ rowIndex + 1 }}
            </span>
            <Checkbox
                :model-value="isSelected"
                data-test="select-row"
                :class="isSelected ? '' : 'hidden group-hover/row:flex'"
                @update:model-value="emit('toggle-row-selection', row.id)"
            />
        </div>

        <div
            v-for="column in columns"
            :key="column.name"
            :data-column="column.name"
            :style="{ width: `${column.width || 180}px` }"
            class="border-border/60 focus-within:ring-primary/50 relative flex shrink-0 items-center border-r focus-within:ring-2 focus-within:ring-inset"
            :class="densityPaddingClass"
        >
            <CellRegistry
                :column="column"
                :model-value="row[column.name]"
                @update:model-value="
                    emit('patch-cell', row.id, column.name, $event, rowIndex)
                "
            />
        </div>

        <div class="w-12 shrink-0" />
    </div>
</template>
