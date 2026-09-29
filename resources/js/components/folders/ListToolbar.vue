<script setup lang="ts">
import {
    ArrowDownUp,
    LayoutGrid,
    List,
    ListFilter,
    Search,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Option = { value: string; label: string };

const q = defineModel<string>('q', { required: true });
const sort = defineModel<string>('sort', { required: true });
const filter = defineModel<string | null>('filter', { required: true });
// Cards or rows; a page that has only one way to show things leaves it out
const view = defineModel<'grid' | 'list'>('view');

const VIEWS = [
    { value: 'grid', label: 'Cards', icon: LayoutGrid },
    { value: 'list', label: 'List', icon: List },
] as const;

defineProps<{
    placeholder: string;
    sortOptions: Option[];
    filterOptions: Option[];
    // Label of the filter's "everything" choice, e.g. "Any time"
    filterAll: string;
}>();

// Reka's Select can't hold null
const ALL = '__all';
const filterValue = computed({
    get: () => filter.value ?? ALL,
    set: (value: string) => (filter.value = value === ALL ? null : value),
});
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row">
        <div class="relative flex-1">
            <Search
                class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
            />
            <Input
                v-model="q"
                type="search"
                :placeholder="placeholder"
                class="pr-9 pl-9 [&::-webkit-search-cancel-button]:hidden"
                @keydown.esc="q = ''"
            />
            <button
                v-if="q"
                type="button"
                class="text-muted-foreground hover:text-foreground absolute top-1/2 right-2.5 -translate-y-1/2"
                title="Clear search"
                @click="q = ''"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="flex gap-2">
            <Select v-model="sort">
                <SelectTrigger class="w-full sm:w-44" aria-label="Sort">
                    <ArrowDownUp class="text-muted-foreground" />
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in sortOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="filterValue">
                <SelectTrigger class="w-full sm:w-40" aria-label="Filter">
                    <ListFilter class="text-muted-foreground" />
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">{{ filterAll }}</SelectItem>
                    <SelectItem
                        v-for="option in filterOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <div
                v-if="view"
                class="bg-muted flex shrink-0 items-center gap-0.5 rounded-md p-0.5"
                role="radiogroup"
                aria-label="Show as"
            >
                <button
                    v-for="option in VIEWS"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="view === option.value"
                    :title="option.label"
                    class="flex h-8 w-8 items-center justify-center rounded transition-colors"
                    :class="
                        view === option.value
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="view = option.value"
                >
                    <component :is="option.icon" class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
