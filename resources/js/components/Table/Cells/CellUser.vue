<script setup lang="ts">
import { Check, ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    CELL_PICKER,
    initialsOf,
    useCellCell,
} from '@/composables/table/useCellCell';

// Who a row is assigned to. The people are a fixed list for now.
const props = defineProps<{ modelValue?: string | null }>();
const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void;
}>();

const cell = useCellCell();

const PEOPLE = [
    'Sahat S.',
    'Alex Mercer',
    'Sarah Connor',
    'Miles Morales',
    'Elena Rostova',
    'David Chen',
];

const BADGES = [
    'bg-sky-500',
    'bg-indigo-500',
    'bg-purple-500',
    'bg-rose-500',
    'bg-emerald-500',
];

const shown = computed(() => {
    const search = cell.searchQuery.value.toLowerCase();

    return search
        ? PEOPLE.filter((name) => name.toLowerCase().includes(search))
        : PEOPLE;
});

/** Each person keeps the same badge colour, worked out from their name. */
const badgeOf = (name: string) => {
    let hash = 0;

    for (const letter of name) {
        hash = letter.charCodeAt(0) + ((hash << 5) - hash);
    }

    return BADGES[Math.abs(hash) % BADGES.length];
};

const pick = (name: string) => {
    emit('update:modelValue', name === props.modelValue ? null : name);
    cell.isOpen.value = false;
};
</script>

<template>
    <Popover v-model:open="cell.isOpen.value">
        <PopoverTrigger as-child>
            <button type="button" :class="CELL_PICKER">
                <template v-if="modelValue">
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold text-white"
                        :class="badgeOf(modelValue)"
                    >
                        {{ initialsOf(modelValue) }}
                    </span>
                    <span class="truncate">{{ modelValue }}</span>
                </template>
                <ChevronDown
                    class="text-muted-foreground ml-auto size-3.5 shrink-0 opacity-0 group-hover/cell:opacity-100"
                />
            </button>
        </PopoverTrigger>
        <PopoverContent align="start" class="w-56 p-1.5">
            <input
                v-model="cell.searchQuery.value"
                type="text"
                placeholder="Find someone"
                class="bg-muted placeholder:text-muted-foreground mb-1 h-8 w-full rounded-md px-2 text-sm outline-none"
            />
            <div class="flex max-h-52 flex-col overflow-y-auto">
                <button
                    v-for="name in shown"
                    :key="name"
                    type="button"
                    class="hover:bg-muted flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                    @click="pick(name)"
                >
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold text-white"
                        :class="badgeOf(name)"
                    >
                        {{ initialsOf(name) }}
                    </span>
                    <span class="truncate">{{ name }}</span>
                    <Check
                        v-if="name === modelValue"
                        class="text-primary ml-auto size-3.5"
                    />
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
