<script setup lang="ts" generic="T extends string">
import type { Component } from 'vue';

// A few ways of looking at one list, side by side, one of them on: the kinds
// of ticket, a list or a board, where a customer's chat stands.
export type Segment<T extends string> = {
    value: T;
    // Left out for one that is only a picture, which then needs a title
    label?: string;
    title?: string;
    icon?: Component;
    tone?: string;
    // How many it holds, said after its name
    count?: number;
    // Its name for a test: data-test
    test?: string;
};

defineProps<{ modelValue: T; options: Segment<T>[]; label?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();
</script>

<template>
    <div class="segment-tabs" role="tablist" :aria-label="label">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="tab"
            :title="option.title"
            :aria-selected="modelValue === option.value"
            :class="{ 'is-on': modelValue === option.value }"
            :data-test="option.test"
            @click="emit('update:modelValue', option.value)"
        >
            <component
                :is="option.icon"
                v-if="option.icon"
                :class="[option.label ? 'size-3.5' : 'size-4', option.tone]"
            />
            <template v-if="option.label">{{ option.label }}</template>
            <span
                v-if="option.count !== undefined"
                class="text-muted-foreground tabular-nums"
                >{{ option.count }}</span
            >
        </button>
    </div>
</template>

<style scoped>
.segment-tabs {
    display: inline-flex;
    padding: 2px;
    gap: 2px;
    background-color: var(--muted);
    border-radius: var(--radius-md);
}
.segment-tabs button {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    height: 2rem;
    padding: 0 0.625rem;
    font-size: 0.8125rem;
    font-weight: 500;
    white-space: nowrap;
    color: var(--muted-foreground);
    border-radius: calc(var(--radius-md) - 2px);
    cursor: pointer;
}
.segment-tabs button.is-on {
    color: var(--foreground);
    background-color: var(--background);
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.12);
}
</style>
