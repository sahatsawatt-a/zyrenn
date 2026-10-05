<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { formulaText, isFormulaError } from '@/composables/table/formulas';
import type { ColumnMeta } from '@/types';

// A formula's answer for this row, worked out by the server: read, never typed
// into. One that can't be worked out says why when pointed at.
const props = defineProps<{
    column: ColumnMeta;
    modelValue?: unknown;
}>();

const problem = computed(() =>
    isFormulaError(props.modelValue) ? props.modelValue.error : null,
);
</script>

<template>
    <span
        v-if="problem"
        class="text-destructive flex min-w-0 items-center gap-1 px-2 text-xs"
        :title="problem"
        data-test="formula-error"
    >
        <TriangleAlert class="size-3.5 shrink-0" />
        <span class="truncate">{{ problem }}</span>
    </span>
    <span
        v-else
        class="w-full truncate px-2 select-text"
        :class="typeof modelValue === 'number' ? 'text-right tabular-nums' : ''"
        :title="column.expression ? `= ${column.expression}` : undefined"
        data-test="formula-value"
    >
        {{ formulaText(modelValue, column) }}
    </span>
</template>
