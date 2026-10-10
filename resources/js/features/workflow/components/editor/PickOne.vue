<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

// One of a few, from a dropdown: a step's choices, its connection, its
// table, a column. Reka's Select can't hold an empty value, so "none" has a
// name of its own.

const NONE = '__none__';

const props = defineProps<{
    modelValue: string | null | undefined;
    options: { value: string; label: string; hint?: string }[];
    placeholder?: string;
    // Offered first when nothing need be chosen, under this name
    none?: string;
    disabled?: boolean;
    id?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const chosen = computed(() =>
    props.options.find((option) => option.value === props.modelValue),
);

// A value no longer offered -- a connection removed since -- still shows as itself
const orphan = computed(() =>
    props.modelValue && !chosen.value ? props.modelValue : null,
);

function pick(value: unknown) {
    emit('update:modelValue', value === NONE ? null : String(value));
}
</script>

<template>
    <Select
        :model-value="modelValue || (none ? NONE : undefined)"
        :disabled="disabled"
        @update:model-value="pick"
    >
        <SelectTrigger :id="id" class="h-8.5 w-full text-xs" data-pick-one>
            <SelectValue :placeholder="placeholder ?? 'Choose…'">
                <template v-if="chosen">
                    {{ chosen.label }}
                    <span
                        v-if="chosen.hint"
                        class="text-muted-foreground ml-1"
                        >{{ chosen.hint }}</span
                    >
                </template>
                <template v-else-if="orphan">
                    <span class="text-destructive">{{ orphan }} (gone)</span>
                </template>
                <template v-else-if="none">{{ none }}</template>
            </SelectValue>
        </SelectTrigger>
        <SelectContent>
            <SelectItem v-if="none" :value="NONE" class="text-xs">
                {{ none }}
            </SelectItem>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
                class="text-xs"
                :data-pick-option="option.value"
            >
                {{ option.label }}
                <span v-if="option.hint" class="text-muted-foreground ml-1">{{
                    option.hint
                }}</span>
            </SelectItem>
            <p
                v-if="!options.length && !none"
                class="text-muted-foreground px-2 py-1.5 text-xs"
            >
                Nothing to choose yet.
            </p>
        </SelectContent>
    </Select>
</template>
