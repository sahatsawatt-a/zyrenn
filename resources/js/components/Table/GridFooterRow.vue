<script setup lang="ts">
import { computed } from 'vue';
import { SUMMARIES, numberText, summarize } from '@/composables/table/formulas';
import type { ColumnMeta, RowData } from '@/types';

// Under the columns that ask for it, their sum (or average, smallest, largest,
// count) over the rows on show: a search or filter narrows it, as in a
// spreadsheet. Pinned to the bottom, so it stays in sight however long the
// table is.
const props = defineProps<{
    columns: ColumnMeta[];
    rows: RowData[];
}>();

const LABELS = Object.fromEntries(
    SUMMARIES.map((option) => [option.id, option.label]),
);

const totals = computed(() =>
    props.columns.map((column) => {
        if (!column.summary) {
            return null;
        }

        const value = summarize(
            props.rows.map((row) => row[column.name]),
            column.summary,
        );

        return {
            label: LABELS[column.summary],
            text:
                value === null
                    ? '–'
                    : column.summary === 'count'
                      ? String(value)
                      : numberText(value, column),
        };
    }),
);
</script>

<template>
    <div
        class="border-border/60 bg-muted text-muted-foreground sticky bottom-0 z-20 flex border-t text-xs"
        data-test="table-footer"
    >
        <div
            class="border-border/60 bg-muted sticky left-0 z-10 flex w-14 shrink-0 items-center justify-center border-r"
        />
        <div
            v-for="(column, index) in columns"
            :key="column.name"
            :style="{ width: `${column.width || 180}px` }"
            class="border-border/60 flex h-8 shrink-0 items-center justify-end gap-1.5 border-r px-2"
            :data-total="column.name"
        >
            <template v-if="totals[index]">
                <span class="text-[10px] tracking-wide uppercase">
                    {{ totals[index].label }}
                </span>
                <span class="text-foreground font-medium tabular-nums">
                    {{ totals[index].text }}
                </span>
            </template>
        </div>
        <div class="w-12 shrink-0" />
    </div>
</template>
