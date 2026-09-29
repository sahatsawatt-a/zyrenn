<script setup lang="ts">
import { Check, ChevronDown, Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { chipClass, randomTone } from '@/composables/table/tones';
import { CELL_PICKER, useCellCell } from '@/composables/table/useCellCell';
import { useTableStore } from '@/composables/table/useTableStore';
import type { ColumnMeta } from '@/types';

// A select holds one choice, a multi-select a list of them. Either can make a
// new choice on the spot, from what is typed into the search.
const props = defineProps<{
    column: ColumnMeta;
    modelValue?: string | string[] | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | string[] | null): void;
}>();

const store = useTableStore();
const cell = useCellCell();

const isMulti = computed(() => props.column.type === 'multi_select');

const chosen = computed<string[]>(() => {
    if (Array.isArray(props.modelValue)) {
        return props.modelValue;
    }

    return props.modelValue ? [props.modelValue] : [];
});

const colourOf = (value: string) =>
    chipClass(props.column.options?.find((o) => o.value === value)?.color);

const typed = computed(() => cell.searchQuery.value.trim());

const shown = computed(() => {
    const search = typed.value.toLowerCase();
    const all = props.column.options ?? [];

    return search
        ? all.filter((o) => o.value.toLowerCase().includes(search))
        : all;
});

const exists = computed(() =>
    (props.column.options ?? []).some(
        (o) => o.value.toLowerCase() === typed.value.toLowerCase(),
    ),
);

const pick = (value: string) => {
    if (!isMulti.value) {
        emit(
            'update:modelValue',
            value === props.modelValue ? null : value || null,
        );
        cell.isOpen.value = false;

        return;
    }

    emit(
        'update:modelValue',
        chosen.value.includes(value)
            ? chosen.value.filter((v) => v !== value)
            : [...chosen.value, value],
    );
};

const remove = (value: string) =>
    emit(
        'update:modelValue',
        chosen.value.filter((v) => v !== value),
    );

/** Enter picks the choice typed, making it first when there is none. */
const create = () => {
    const value = typed.value;

    if (!value) {
        return;
    }

    const existing = props.column.options?.find(
        (o) => o.value.toLowerCase() === value.toLowerCase(),
    );

    if (!existing) {
        store.addOptionToColumn(props.column.name, {
            id: String(Date.now()),
            value,
            color: randomTone(),
        });
    }

    cell.searchQuery.value = '';
    pick(existing?.value ?? value);
};
</script>

<template>
    <Popover v-model:open="cell.isOpen.value">
        <PopoverTrigger as-child>
            <button type="button" :class="CELL_PICKER">
                <span
                    v-for="value in chosen"
                    :key="value"
                    class="inline-flex shrink-0 items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="colourOf(value)"
                >
                    {{ value }}
                    <X
                        v-if="isMulti"
                        class="size-3 opacity-60 hover:opacity-100"
                        @click.stop="remove(value)"
                    />
                </span>
                <ChevronDown
                    class="text-muted-foreground ml-auto size-3.5 shrink-0 opacity-0 group-hover/cell:opacity-100"
                />
            </button>
        </PopoverTrigger>
        <PopoverContent align="start" class="w-60 p-1.5">
            <input
                v-model="cell.searchQuery.value"
                type="text"
                :placeholder="
                    isMulti ? 'Find or add tags' : 'Find or add a choice'
                "
                class="bg-muted placeholder:text-muted-foreground mb-1 h-8 w-full rounded-md px-2 text-sm outline-none"
                @keydown.enter.prevent="create"
            />
            <div class="flex max-h-52 flex-col overflow-y-auto">
                <button
                    v-for="option in shown"
                    :key="option.id"
                    type="button"
                    class="hover:bg-muted flex items-center justify-between rounded-md px-2 py-1.5 text-left"
                    @click="pick(option.value)"
                >
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="chipClass(option.color)"
                    >
                        {{ option.value }}
                    </span>
                    <Check
                        v-if="chosen.includes(option.value)"
                        class="text-primary size-3.5"
                    />
                </button>
                <button
                    v-if="typed && !exists"
                    type="button"
                    class="text-muted-foreground hover:bg-muted hover:text-foreground flex items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-sm"
                    @click="create"
                >
                    <Plus class="size-3.5" /> Add “{{ typed }}”
                </button>
                <p
                    v-if="!shown.length && !typed"
                    class="text-muted-foreground px-2 py-3 text-center text-xs"
                >
                    No choices yet — type one.
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
