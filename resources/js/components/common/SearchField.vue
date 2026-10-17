<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { ref } from 'vue';
import { cn } from '@/lib/utils';

// A search box, the app's own way: a magnifier, and a clear button the same
// in every browser, shown only while there is something to clear -- where
// the browser's own adds its ✕ in one browser, none in another.

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
        // What it searches, for whoever cannot see it; the placeholder otherwise
        label?: string;
        compact?: boolean;
        // Off where the page gives Escape a meaning of its own
        escClears?: boolean;
        class?: string;
    }>(),
    { escClears: true },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const box = ref<HTMLInputElement | null>(null);

function clear() {
    emit('update:modelValue', '');
    box.value?.focus();
}

defineExpose({ focus: () => box.value?.focus() });
</script>

<template>
    <div :class="cn('relative min-w-0', props.class)">
        <Search
            class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
        />
        <input
            v-bind="$attrs"
            ref="box"
            type="search"
            :value="modelValue"
            :placeholder="placeholder"
            :aria-label="label ?? placeholder"
            autocomplete="off"
            class="border-input dark:bg-input/30 placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent pr-8 pl-8 text-sm shadow-xs outline-none focus-visible:ring-[3px] [&::-webkit-search-cancel-button]:hidden"
            :class="compact ? 'h-8' : 'h-9'"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
            @keydown.esc="
                escClears && modelValue && ($event.stopPropagation(), clear())
            "
        />
        <button
            v-if="modelValue"
            type="button"
            class="text-muted-foreground hover:bg-muted hover:text-foreground absolute top-1/2 right-1.5 grid size-6 -translate-y-1/2 place-items-center rounded"
            aria-label="Clear the search"
            data-search-clear
            @click="clear"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
