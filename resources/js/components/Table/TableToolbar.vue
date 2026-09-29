<script setup lang="ts">
import {
    ArrowDownWideNarrow,
    ArrowUpDown,
    ArrowUpNarrowWide,
    Download,
    Eye,
    ListFilter,
    Plus,
    Rows3,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { columnIcon } from '@/composables/table/columnTypes';
import { useTableStore } from '@/composables/table/useTableStore';
import type { FilterOperator, TableDensity } from '@/types';

const emit = defineEmits<{
    (e: 'open-add-column'): void;
}>();

const store = useTableStore();
const fieldSearch = ref('');

const OPERATORS: { value: FilterOperator; label: string }[] = [
    { value: 'contains', label: 'contains' },
    { value: 'not_contains', label: 'does not contain' },
    { value: 'equals', label: 'is' },
    { value: 'not_equals', label: 'is not' },
    { value: 'gt', label: '>' },
    { value: 'lt', label: '<' },
    { value: 'is_empty', label: 'is empty' },
    { value: 'is_not_empty', label: 'is not empty' },
];

const DENSITIES: TableDensity[] = ['compact', 'normal', 'spacious'];

const hiddenCount = computed(
    () => store.columns.value.filter((column) => column.hidden).length,
);

const listedFields = computed(() => {
    const search = fieldSearch.value.toLowerCase();

    return search
        ? store.columns.value.filter((column) =>
              column.label.toLowerCase().includes(search),
          )
        : store.columns.value;
});

const sortedLabel = computed(
    () =>
        store.columns.value.find(
            (column) => column.name === store.sort.value?.column,
        )?.label,
);

const sortBy = (column: string) => {
    if (column) {
        store.setSort(column, store.sort.value?.direction ?? 'asc');
    }
};

const rowCount = computed(() => {
    const shown = store.filteredRows.value.length;
    const all = store.rows.value.length;

    return shown === all
        ? `${all} ${all === 1 ? 'row' : 'rows'}`
        : `${shown} of ${all} rows`;
});

// The small controls inside the popovers, drawn the way the app's inputs are
const field =
    'h-8 rounded-md border border-input bg-transparent px-2 text-xs text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 dark:bg-input/30';
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5 text-sm">
        <div class="relative mr-1 w-full sm:w-56">
            <Search
                class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
            />
            <input
                v-model="store.searchQuery.value"
                type="text"
                placeholder="Search rows"
                data-test="table-search"
                class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 h-8 w-full rounded-md border bg-transparent pr-7 pl-8 text-sm outline-none focus-visible:ring-2"
            />
            <button
                v-if="store.searchQuery.value"
                type="button"
                class="text-muted-foreground hover:text-foreground absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5"
                title="Clear the search"
                @click="store.searchQuery.value = ''"
            >
                <X class="size-3.5" />
            </button>
        </div>

        <!-- Filter -->
        <Popover>
            <PopoverTrigger as-child>
                <Button
                    :variant="
                        store.filters.value.length ? 'secondary' : 'ghost'
                    "
                    size="sm"
                    data-test="table-filter"
                >
                    <ListFilter />
                    Filter
                    <span
                        v-if="store.filters.value.length"
                        class="bg-primary text-primary-foreground rounded-full px-1.5 text-[10px] leading-4 font-semibold"
                    >
                        {{ store.filters.value.length }}
                    </span>
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-[26rem] p-3">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-muted-foreground text-xs font-medium">
                        Show rows where…
                    </span>
                    <button
                        v-if="store.filters.value.length"
                        type="button"
                        class="text-muted-foreground hover:text-destructive text-xs"
                        @click="store.clearFilters()"
                    >
                        Clear all
                    </button>
                </div>

                <p
                    v-if="!store.filters.value.length"
                    class="text-muted-foreground py-3 text-center text-xs"
                >
                    No filters yet.
                </p>

                <div class="flex max-h-60 flex-col gap-1.5 overflow-y-auto">
                    <div
                        v-for="filter in store.filters.value"
                        :key="filter.id"
                        class="flex items-center gap-1.5"
                    >
                        <select
                            v-model="filter.column"
                            :class="[field, 'min-w-0 flex-1']"
                        >
                            <option
                                v-for="column in store.visibleColumns.value"
                                :key="column.name"
                                :value="column.name"
                            >
                                {{ column.label }}
                            </option>
                        </select>
                        <select
                            v-model="filter.operator"
                            :class="[field, 'w-32']"
                        >
                            <option
                                v-for="operator in OPERATORS"
                                :key="operator.value"
                                :value="operator.value"
                            >
                                {{ operator.label }}
                            </option>
                        </select>
                        <input
                            v-if="
                                !['is_empty', 'is_not_empty'].includes(
                                    filter.operator,
                                )
                            "
                            v-model="filter.value"
                            type="text"
                            placeholder="Value"
                            :class="[field, 'min-w-0 flex-1']"
                        />
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            title="Remove this filter"
                            @click="store.removeFilter(filter.id)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
                </div>

                <Button
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground mt-2"
                    @click="store.addFilter()"
                >
                    <Plus /> Add a filter
                </Button>
            </PopoverContent>
        </Popover>

        <!-- Sort -->
        <Popover>
            <PopoverTrigger as-child>
                <Button
                    :variant="store.sort.value ? 'secondary' : 'ghost'"
                    size="sm"
                    data-test="table-sort"
                >
                    <ArrowUpDown />
                    <template v-if="store.sort.value">
                        {{ sortedLabel }}
                        <ArrowUpNarrowWide
                            v-if="store.sort.value.direction === 'asc'"
                        />
                        <ArrowDownWideNarrow v-else />
                    </template>
                    <template v-else>Sort</template>
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-72 p-3">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-muted-foreground text-xs font-medium">
                        Sort rows by
                    </span>
                    <button
                        v-if="store.sort.value"
                        type="button"
                        class="text-muted-foreground hover:text-destructive text-xs"
                        @click="store.clearSort()"
                    >
                        Clear
                    </button>
                </div>
                <select
                    :value="store.sort.value?.column ?? ''"
                    :class="[field, 'w-full']"
                    @change="sortBy(($event.target as HTMLSelectElement).value)"
                >
                    <option value="" disabled>Pick a column</option>
                    <option
                        v-for="column in store.visibleColumns.value"
                        :key="column.name"
                        :value="column.name"
                    >
                        {{ column.label }}
                    </option>
                </select>
                <div
                    v-if="store.sort.value"
                    class="bg-muted mt-2 grid grid-cols-2 gap-1 rounded-md p-1"
                >
                    <button
                        v-for="direction in ['asc', 'desc'] as const"
                        :key="direction"
                        type="button"
                        class="rounded px-2 py-1 text-xs font-medium transition-colors"
                        :class="
                            store.sort.value.direction === direction
                                ? 'bg-background text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="
                            store.setSort(store.sort.value.column, direction)
                        "
                    >
                        {{ direction === 'asc' ? 'A → Z' : 'Z → A' }}
                    </button>
                </div>
            </PopoverContent>
        </Popover>

        <!-- Which columns show -->
        <Popover>
            <PopoverTrigger as-child>
                <Button
                    :variant="hiddenCount ? 'secondary' : 'ghost'"
                    size="sm"
                    data-test="table-fields"
                >
                    <Eye />
                    {{ hiddenCount ? `${hiddenCount} hidden` : 'Fields' }}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-64 p-2">
                <input
                    v-model="fieldSearch"
                    type="text"
                    placeholder="Find a field"
                    :class="[field, 'mb-1.5 w-full']"
                />
                <div class="flex max-h-64 flex-col overflow-y-auto">
                    <label
                        v-for="column in listedFields"
                        :key="column.name"
                        class="hover:bg-muted flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        :class="
                            column.isPrimary
                                ? 'cursor-not-allowed opacity-60'
                                : 'cursor-pointer'
                        "
                    >
                        <Checkbox
                            :model-value="!column.hidden"
                            :disabled="column.isPrimary"
                            @update:model-value="
                                store.toggleColumnVisibility(column.name)
                            "
                        />
                        <component
                            :is="columnIcon(column.type)"
                            class="text-muted-foreground size-3.5 shrink-0"
                        />
                        <span class="truncate">{{ column.label }}</span>
                    </label>
                </div>
                <Button
                    v-if="hiddenCount"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground mt-1 w-full"
                    @click="store.showAllColumns()"
                >
                    Show all
                </Button>
            </PopoverContent>
        </Popover>

        <!-- Row height -->
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="sm" data-test="table-density">
                    <Rows3 />
                    <span class="capitalize">{{ store.density.value }}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-40">
                <DropdownMenuLabel class="text-muted-foreground text-xs">
                    Row height
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    :model-value="store.density.value"
                    @update:model-value="
                        store.density.value = $event as TableDensity
                    "
                >
                    <DropdownMenuRadioItem
                        v-for="density in DENSITIES"
                        :key="density"
                        :value="density"
                        class="capitalize"
                    >
                        {{ density }}
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>

        <div class="ml-auto flex items-center gap-1.5">
            <span
                class="text-muted-foreground mr-1 text-xs tabular-nums"
                data-test="table-count"
            >
                {{ rowCount }}
            </span>
            <Button
                variant="ghost"
                size="sm"
                title="Download the rows shown as a CSV file"
                @click="store.exportToCsv()"
            >
                <Download />
                <span class="hidden md:inline">Export</span>
            </Button>
            <Button
                variant="outline"
                size="sm"
                data-test="add-column"
                @click="emit('open-add-column')"
            >
                <Plus /> Field
            </Button>
            <Button size="sm" data-test="add-row" @click="store.addRow()">
                <Plus /> Row
            </Button>
        </div>
    </div>
</template>
