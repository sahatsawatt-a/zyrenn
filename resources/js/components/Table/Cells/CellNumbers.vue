<script setup lang="ts">
import { Star } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import {
    CELL_INPUT,
    formatCurrency,
    useCellCell,
} from '@/composables/table/useCellCell';
import type { ColumnMeta } from '@/types';

// Numbers of every kind. A plain number is typed straight in; money and a
// percentage show themselves dressed up, and turn back into a number to edit.
const props = defineProps<{
    column: ColumnMeta;
    modelValue?: number | string | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const cell = useCellCell();
const editing = ref(false);
const input = ref<HTMLInputElement | null>(null);

const hasValue = () =>
    props.modelValue !== null &&
    props.modelValue !== undefined &&
    props.modelValue !== '';

const startEdit = async () => {
    editing.value = true;
    await nextTick();
    input.value?.focus();
    input.value?.select();
};

const save = (event: Event) => {
    const value = (event.target as HTMLInputElement).value;
    emit('update:modelValue', value === '' ? null : Number(value));
};

const rate = (star: number) =>
    emit('update:modelValue', Number(props.modelValue) === star ? null : star);

const percent = () => Math.min(100, Math.max(0, Number(props.modelValue) || 0));
</script>

<template>
    <input
        v-if="column.type === 'integer' || column.type === 'numeric'"
        type="number"
        :step="column.type === 'numeric' ? 'any' : 1"
        :value="modelValue ?? ''"
        :class="[CELL_INPUT, 'text-right tabular-nums']"
        @change="save"
    />

    <div
        v-else-if="column.type === 'rating'"
        class="flex h-full items-center gap-0.5 px-2"
        @mouseleave="cell.hoverRating.value = null"
    >
        <button
            v-for="star in column.maxRating || 5"
            :key="star"
            type="button"
            class="transition-transform hover:scale-110"
            :title="`${star} of ${column.maxRating || 5}`"
            @mouseenter="cell.hoverRating.value = star"
            @click="rate(star)"
        >
            <Star
                class="size-3.5"
                :class="
                    star <= (cell.hoverRating.value ?? Number(modelValue) ?? 0)
                        ? 'fill-amber-400 text-amber-400'
                        : 'text-muted-foreground/30'
                "
            />
        </button>
    </div>

    <!-- Money and percentages: shown dressed up until clicked -->
    <div v-else class="flex h-full w-full items-center">
        <div
            v-if="!editing"
            class="flex h-full w-full cursor-text items-center gap-2 px-2"
            :class="column.type === 'currency' ? 'justify-end' : ''"
            @click="startEdit"
        >
            <template v-if="column.type === 'percent'">
                <div
                    class="bg-muted h-1.5 max-w-16 flex-1 overflow-hidden rounded-full"
                >
                    <div
                        class="bg-primary h-full rounded-full"
                        :style="{ width: `${percent()}%` }"
                    />
                </div>
                <span v-if="hasValue()" class="text-sm tabular-nums">
                    {{ modelValue }}%
                </span>
            </template>
            <span v-else-if="hasValue()" class="text-sm tabular-nums">
                {{
                    formatCurrency(
                        Number(modelValue),
                        column.currencySymbol || '$',
                    )
                }}
            </span>
        </div>
        <template v-else>
            <span
                v-if="column.type === 'currency'"
                class="text-muted-foreground pl-2 text-sm"
            >
                {{ column.currencySymbol || '$' }}
            </span>
            <input
                ref="input"
                type="number"
                step="any"
                :value="modelValue ?? ''"
                :class="[CELL_INPUT, 'text-right tabular-nums']"
                @change="save"
                @blur="editing = false"
                @keydown.enter="($event.target as HTMLInputElement).blur()"
            />
            <span
                v-if="column.type === 'percent'"
                class="text-muted-foreground pr-2 text-sm"
            >
                %
            </span>
        </template>
    </div>
</template>

<style scoped>
/* A number cell has no spinner arrows; the cell is too small for them */
input[type='number']::-webkit-outer-spin-button,
input[type='number']::-webkit-inner-spin-button {
    appearance: none;
    margin: 0;
}

input[type='number'] {
    appearance: textfield;
}
</style>
