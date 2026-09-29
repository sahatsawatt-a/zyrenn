<script setup lang="ts">
import CellLongText from '@/components/Table/Cells/CellLongText.vue';
import CellNumbers from '@/components/Table/Cells/CellNumbers.vue';
import CellSelects from '@/components/Table/Cells/CellSelects.vue';
import CellUser from '@/components/Table/Cells/CellUser.vue';
import CellWeblines from '@/components/Table/Cells/CellWeblines.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { CELL_INPUT } from '@/composables/table/useCellCell';
import type { ColumnMeta } from '@/types';

// Picks the editor for a cell by the kind of its column.
defineProps<{
    column: ColumnMeta;
    modelValue?: any;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: unknown): void;
}>();

const NUMBERS = ['integer', 'numeric', 'currency', 'percent', 'rating'];
const WEBLINES = ['date', 'email', 'url', 'phone'];
</script>

<template>
    <div class="group/cell relative flex h-full w-full items-center text-sm">
        <span
            v-if="column.isPrimary"
            class="text-muted-foreground px-2 text-xs tabular-nums select-none"
        >
            {{ modelValue }}
        </span>

        <div v-else-if="column.type === 'boolean'" class="flex w-full px-2">
            <Checkbox
                :model-value="!!modelValue"
                @update:model-value="emit('update:modelValue', $event === true)"
            />
        </div>

        <CellLongText
            v-else-if="column.type === 'text'"
            :column="column"
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <CellNumbers
            v-else-if="NUMBERS.includes(column.type)"
            :column="column"
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <CellWeblines
            v-else-if="WEBLINES.includes(column.type)"
            :column="column"
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <CellSelects
            v-else-if="
                column.type === 'select' || column.type === 'multi_select'
            "
            :column="column"
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <CellUser
            v-else-if="column.type === 'user'"
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
        />

        <input
            v-else
            type="text"
            :value="modelValue ?? ''"
            :class="CELL_INPUT"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
        />
    </div>
</template>
