<script setup lang="ts">
import { Plus, SlidersHorizontal } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useTableStore } from '@/composables/table/useTableStore';
import { canChange } from '@/lib/projects';
import type { TableParameter } from '@/types';

// The table's parameters, above the grid: named values its formulas share --
// an exchange rate, how many share the costs, the first day of a trip. One
// changed here changes every formula that names it.
const emit = defineEmits<{
    // Taken by the server: the rows' formulas want working out again
    (e: 'saved'): void;
}>();

const store = useTableStore();
// A project's viewers read them; only its owners and editors change them
const editable = canChange();

// The one being edited, by its place in the list, or "new"
const editing = ref<number | 'new' | null>(null);
const name = ref('');
const value = ref('');
const problem = ref<string | null>(null);
const busy = ref(false);

const shown = (parameter: TableParameter) =>
    parameter.value === null ? '' : String(parameter.value);

const open = (which: number | 'new') => {
    const parameter = which === 'new' ? null : store.parameters.value[which];
    name.value = parameter?.name ?? '';
    value.value = parameter ? shown(parameter) : '';
    problem.value = null;
    editing.value = which;
};

const close = () => {
    editing.value = null;
};

/** What was typed, as what it is: a number, true or false, or text (a date too). */
const typed = (text: string): TableParameter['value'] => {
    const trimmed = text.trim();

    if (trimmed !== '' && !Number.isNaN(Number(trimmed))) {
        return Number(trimmed);
    }

    if (trimmed === 'true' || trimmed === 'false') {
        return trimmed === 'true';
    }

    return trimmed;
};

const save = async (next: TableParameter[]) => {
    busy.value = true;
    problem.value = await store.saveParameters(next);
    busy.value = false;

    if (problem.value === null) {
        close();
        emit('saved');
    }
};

const keep = () => {
    const parameter = { name: name.value.trim(), value: typed(value.value) };
    const next = [...store.parameters.value];

    if (editing.value === 'new') {
        next.push(parameter);
    } else if (editing.value !== null) {
        next.splice(editing.value, 1, parameter);
    }

    void save(next);
};

const remove = () => {
    if (typeof editing.value === 'number') {
        const at = editing.value;
        void save(store.parameters.value.filter((_, index) => index !== at));
    }
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5 text-xs"
        data-test="table-parameters"
    >
        <span
            class="text-muted-foreground mr-1 flex items-center gap-1.5"
            title="Named values the formulas share"
        >
            <SlidersHorizontal class="size-3.5" /> Parameters
        </span>

        <Popover
            v-for="(parameter, index) in store.parameters.value"
            :key="parameter.name"
            :open="editing === index"
            @update:open="(isOpen) => (isOpen ? open(index) : close())"
        >
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="bg-muted hover:bg-accent rounded-full px-2.5 py-1 font-mono transition-colors disabled:cursor-default"
                    :disabled="!editable"
                    :data-test="`parameter-${parameter.name}`"
                >
                    {{ parameter.name }}
                    <span class="text-muted-foreground">=</span>
                    {{ shown(parameter) }}
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-72" align="start">
                <form class="grid gap-2" @submit.prevent="keep">
                    <Input v-model="name" placeholder="Name, e.g. rate" />
                    <Input
                        v-model="value"
                        placeholder="Value: 5, text, or 2026-12-03"
                        data-test="parameter-value"
                    />
                    <p v-if="problem" class="text-destructive text-xs">
                        {{ problem }}
                    </p>
                    <div class="flex justify-between gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            :disabled="busy"
                            @click="remove"
                        >
                            Remove
                        </Button>
                        <Button type="submit" size="sm" :disabled="busy">
                            Save
                        </Button>
                    </div>
                </form>
            </PopoverContent>
        </Popover>

        <Popover
            v-if="editable"
            :open="editing === 'new'"
            @update:open="(isOpen) => (isOpen ? open('new') : close())"
        >
            <PopoverTrigger as-child>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    data-test="parameter-add"
                >
                    <Plus class="size-3.5" /> Add
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-72" align="start">
                <form class="grid gap-2" @submit.prevent="keep">
                    <Input
                        v-model="name"
                        placeholder="Name, e.g. rate"
                        data-test="parameter-name"
                    />
                    <Input
                        v-model="value"
                        placeholder="Value: 5, text, or 2026-12-03"
                        data-test="parameter-new-value"
                    />
                    <p v-if="problem" class="text-destructive text-xs">
                        {{ problem }}
                    </p>
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="busy || !name.trim()"
                            data-test="parameter-save"
                        >
                            Add parameter
                        </Button>
                    </div>
                </form>
            </PopoverContent>
        </Popover>
    </div>
</template>
