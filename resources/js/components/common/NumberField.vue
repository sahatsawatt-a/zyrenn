<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';

// A number, the app's own way: big − and + to tap, typing still works, the
// arrow keys step it, and the unit beside it. Not the browser's number box,
// whose tiny arrows are missing on phones and whose value the mouse wheel
// changes by accident while the page scrolls -- so this is a text field that
// takes only a number, kept between its least and most once it is left.

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue: number | null | undefined;
        // What it is, for whoever cannot see it
        label: string;
        min?: number;
        max?: number;
        step?: number;
        // Said after it: "px", "times", "nights"
        unit?: string;
        placeholder?: string;
        // Left empty, it stays empty rather than becoming 0
        nullable?: boolean;
        disabled?: boolean;
        // Shorter, for a dense panel
        compact?: boolean;
        id?: string;
        class?: string;
    }>(),
    { step: 1 },
);

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
    // Once it is left, as a field's change is
    change: [value: number | null];
}>();

const text = ref('');

const places = computed(() => (String(props.step).split('.')[1] ?? '').length);

const shown = (value: number | null | undefined) =>
    value === null || value === undefined || Number.isNaN(value)
        ? ''
        : String(
              Number(value.toFixed(Math.max(places.value, decimalsOf(value)))),
          );

const decimalsOf = (value: number) =>
    (String(value).split('.')[1] ?? '').length;

watch(
    () => props.modelValue,
    (value) => {
        if (read(text.value) !== value) {
            text.value = shown(value);
        }
    },
    { immediate: true },
);

function read(raw: string): number | null {
    const plain = raw.replace(/,/g, '').trim();

    return plain === '' || plain === '-' || Number.isNaN(Number(plain))
        ? null
        : Number(plain);
}

function within(value: number) {
    let kept = value;

    if (props.min !== undefined) {
        kept = Math.max(props.min, kept);
    }

    if (props.max !== undefined) {
        kept = Math.min(props.max, kept);
    }

    return Number(kept.toFixed(Math.max(places.value, decimalsOf(kept))));
}

// Typing: only what a number can be, given as it goes
function typed(event: Event) {
    const raw = (event.target as HTMLInputElement).value;

    if (!/^-?[\d,]*\.?\d*$/.test(raw)) {
        (event.target as HTMLInputElement).value = text.value;

        return;
    }

    text.value = raw;
    const value = read(raw);

    if (value !== null || props.nullable) {
        emit('update:modelValue', value);
    }
}

// Left: kept within its range, or back to what it was when left empty
function settle() {
    const value = read(text.value);

    if (value === null) {
        if (props.nullable) {
            emit('update:modelValue', null);
            emit('change', null);
        } else {
            text.value = shown(props.modelValue);
        }

        return;
    }

    const kept = within(value);
    text.value = shown(kept);
    emit('update:modelValue', kept);
    emit('change', kept);
}

function stepBy(direction: 1 | -1) {
    if (props.disabled) {
        return;
    }

    const now = read(text.value) ?? props.min ?? 0;
    const kept = within(now + direction * props.step);
    text.value = shown(kept);
    emit('update:modelValue', kept);
    emit('change', kept);
}

const atMin = computed(
    () =>
        props.min !== undefined && (read(text.value) ?? props.min) <= props.min,
);
const atMax = computed(
    () =>
        props.max !== undefined && (read(text.value) ?? -Infinity) >= props.max,
);
</script>

<template>
    <div
        :class="
            cn(
                'border-input dark:bg-input/30 bg-background focus-within:ring-ring/50 focus-within:border-ring inline-flex items-stretch overflow-hidden rounded-md border shadow-xs focus-within:ring-[3px]',
                compact ? 'h-8' : 'h-9',
                disabled && 'opacity-50',
                props.class,
            )
        "
        role="group"
        :aria-label="label"
    >
        <button
            type="button"
            class="bg-muted/60 hover:bg-accent text-muted-foreground hover:text-accent-foreground grid shrink-0 place-items-center disabled:pointer-events-none disabled:opacity-40"
            :class="compact ? 'w-7' : 'w-9'"
            :disabled="disabled || atMin"
            :aria-label="`Less ${label.toLowerCase()}`"
            tabindex="-1"
            @click="stepBy(-1)"
        >
            <Minus class="size-3.5" />
        </button>
        <input
            v-bind="$attrs"
            :id="id"
            :value="text"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-label="label"
            :aria-valuemin="min"
            :aria-valuemax="max"
            :aria-valuenow="read(text) ?? undefined"
            role="spinbutton"
            class="min-w-0 flex-1 bg-transparent px-1 text-center text-sm tabular-nums outline-none"
            :class="compact ? 'w-12' : 'w-16'"
            @input="typed"
            @blur="settle"
            @keydown.enter.prevent="settle"
            @keydown.up.prevent="stepBy(1)"
            @keydown.down.prevent="stepBy(-1)"
        />
        <span
            v-if="unit"
            class="text-muted-foreground flex items-center pr-2 text-xs"
            >{{ unit }}</span
        >
        <button
            type="button"
            class="bg-muted/60 hover:bg-accent text-muted-foreground hover:text-accent-foreground grid shrink-0 place-items-center disabled:pointer-events-none disabled:opacity-40"
            :class="compact ? 'w-7' : 'w-9'"
            :disabled="disabled || atMax"
            :aria-label="`More ${label.toLowerCase()}`"
            tabindex="-1"
            @click="stepBy(1)"
        >
            <Plus class="size-3.5" />
        </button>
    </div>
</template>
