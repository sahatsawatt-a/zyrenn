<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

// A slider, the app's own way: a thicker track with what is filled in the
// app's colour, a handle a finger can take, and the value said beside it --
// the same in every browser, where the browser's own is thin on one, square
// on another, and says no value at all.

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue: number;
        // What it sets, for whoever cannot see it
        label: string;
        min?: number;
        max?: number;
        step?: number;
        // How the value is said beside it; false for none
        format?: ((value: number) => string) | false;
        // Said after the value when there is no format: "%", "px"
        unit?: string;
        disabled?: boolean;
        id?: string;
        class?: string;
    }>(),
    // format left out says the value as it is: a default stops Vue reading a
    // missing true-or-false option as false
    { min: 0, max: 100, step: 1, format: undefined },
);

const emit = defineEmits<{
    'update:modelValue': [value: number];
    // Once it is let go, for what should not happen at every step
    change: [value: number];
}>();

const fill = computed(
    () =>
        `${(((props.modelValue ?? props.min) - props.min) / Math.max(1e-9, props.max - props.min)) * 100}%`,
);

const said = computed(() =>
    props.format === false
        ? ''
        : props.format
          ? props.format(props.modelValue)
          : `${props.modelValue}${props.unit ?? ''}`,
);
</script>

<template>
    <div :class="cn('flex min-w-0 items-center gap-2.5', props.class)">
        <input
            v-bind="$attrs"
            :id="id"
            type="range"
            class="app-range min-w-0 flex-1"
            :style="{ '--fill': fill }"
            :min="min"
            :max="max"
            :step="step"
            :value="modelValue"
            :disabled="disabled"
            :aria-label="label"
            :aria-valuetext="said || undefined"
            @input="
                emit(
                    'update:modelValue',
                    Number(($event.target as HTMLInputElement).value),
                )
            "
            @change="
                emit(
                    'change',
                    Number(($event.target as HTMLInputElement).value),
                )
            "
        />
        <span
            v-if="said"
            class="text-muted-foreground min-w-10 shrink-0 text-right text-xs tabular-nums"
            >{{ said }}</span
        >
    </div>
</template>

<style scoped>
.app-range {
    -webkit-appearance: none;
    appearance: none;
    height: 20px;
    background: transparent;
    cursor: pointer;
}
.app-range:disabled {
    cursor: default;
    opacity: 0.5;
}
.app-range::-webkit-slider-runnable-track {
    height: 6px;
    border-radius: 9999px;
    background: linear-gradient(
        90deg,
        var(--primary) var(--fill),
        var(--muted) var(--fill)
    );
}
.app-range::-moz-range-track {
    height: 6px;
    border-radius: 9999px;
    background: var(--muted);
}
.app-range::-moz-range-progress {
    height: 6px;
    border-radius: 9999px;
    background: var(--primary);
}
.app-range::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    margin-top: -6px;
    border-radius: 50%;
    background: var(--background);
    border: 2px solid var(--primary);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.2);
}
.app-range::-moz-range-thumb {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--background);
    border: 2px solid var(--primary);
}
.app-range:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 4px;
    border-radius: 4px;
}
</style>
