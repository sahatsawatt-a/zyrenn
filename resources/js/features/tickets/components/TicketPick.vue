<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

// One of a few, from the app's own dropdown, each with the picture it is
// known by: a ticket's status, who has it, the part a list is narrowed to.
// As PickOne is for a workflow's steps -- but these carry icons, and one of
// them sits in every row of a list.
export type PickOption = {
    value: string;
    label: string;
    icon?: Component;
    tone?: string;
};

// The dropdown itself is no element: what is said of it goes on its button
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    modelValue: string;
    options: PickOption[];
    // What it chooses, for whoever cannot see it
    label: string;
    // In a row of a list: shorter, and no wider than what it says
    compact?: boolean;
    class?: string;
    disabled?: boolean;
    id?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const chosen = computed(() =>
    props.options.find((option) => option.value === props.modelValue),
);
</script>

<template>
    <Select
        :model-value="modelValue"
        :disabled="disabled"
        @update:model-value="emit('update:modelValue', String($event))"
    >
        <SelectTrigger
            v-bind="$attrs"
            :id="id"
            :size="compact ? 'sm' : 'default'"
            :aria-label="label"
            :class="
                cn(compact ? 'gap-1.5 px-2 text-xs' : 'w-full', props.class)
            "
        >
            <SelectValue>
                <template v-if="chosen">
                    <component
                        :is="chosen.icon"
                        v-if="chosen.icon"
                        class="size-3.5"
                        :class="chosen.tone"
                    />
                    <span class="truncate">{{ chosen.label }}</span>
                </template>
            </SelectValue>
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
                :data-pick="option.value"
            >
                <component
                    :is="option.icon"
                    v-if="option.icon"
                    class="size-4"
                    :class="option.tone"
                />
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
