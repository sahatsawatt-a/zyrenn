<script setup lang="ts">
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import ColourPicker from './ColourPicker.vue';

// A colour as a swatch, opened for the picker: one of a fade's colours, a
// glow's, a word fade's end.
defineProps<{
    colour: string;
    label: string;
    side: 'top' | 'left' | 'right' | 'bottom';
    // What the swatch shows, where it is more than the colour: a fade
    swatch?: string;
}>();
const emit = defineEmits<{ pick: [colour: string] }>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="hover:bg-muted inline-flex shrink-0 items-center gap-1.5 rounded-md p-1 text-xs"
                :title="label"
                :aria-label="label"
            >
                <span
                    class="inspector-chip"
                    :style="{ background: swatch ?? colour }"
                />
                <slot />
            </button>
        </PopoverTrigger>
        <PopoverContent
            :side="side"
            align="start"
            :collision-padding="8"
            class="w-64 p-3"
        >
            <ColourPicker
                :model-value="colour"
                :label="label"
                @update:model-value="emit('pick', $event)"
            />
        </PopoverContent>
    </Popover>
</template>
