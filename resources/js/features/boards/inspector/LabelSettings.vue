<script setup lang="ts">
import {
    AlignCenterHorizontal,
    AlignCenterVertical,
    AlignEndHorizontal,
    AlignEndVertical,
    AlignStartHorizontal,
    AlignStartVertical,
    Minus,
    Plus,
} from '@lucide/vue';
import type { Component } from 'vue';
import { Switch } from '@/components/ui/switch';
import type { Align, FontFamily, Item, VerticalAlign } from '../model/items';
import { FONT_FAMILIES } from '../model/items';

// The words written on what is selected: where they sit in it, their type
// and size, the room round them, and whether they are light Markdown. Shown
// with the first label's settings; a change goes to every selected label.
const props = defineProps<{ label: Item }>();
const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const aligns: { value: Align; icon: Component; title: string }[] = [
    {
        value: 'left',
        icon: AlignStartVertical,
        title: 'Line the label up left',
    },
    {
        value: 'center',
        icon: AlignCenterVertical,
        title: 'Centre the label',
    },
    {
        value: 'right',
        icon: AlignEndVertical,
        title: 'Line the label up right',
    },
];

const verticalAligns: {
    value: VerticalAlign;
    icon: Component;
    title: string;
}[] = [
    {
        value: 'top',
        icon: AlignStartHorizontal,
        title: 'Put the label at the top',
    },
    {
        value: 'middle',
        icon: AlignCenterHorizontal,
        title: 'Put the label in the middle',
    },
    {
        value: 'bottom',
        icon: AlignEndHorizontal,
        title: 'Put the label at the bottom',
    },
];

const families: { value: FontFamily; label: string }[] = [
    { value: 'sans', label: 'Sans' },
    { value: 'serif', label: 'Serif' },
    { value: 'mono', label: 'Mono' },
];

const round = (value: number) => Math.round(value);

/** A number typed for every selected label, held to what makes sense. */
const onLabelNumber = (
    field: 'fontSize' | 'padding',
    least: number,
    most: number,
    event: Event,
) => {
    const value = Number((event.target as HTMLInputElement).value);

    if (Number.isFinite(value)) {
        emit('update', { [field]: Math.min(most, Math.max(least, value)) });
    }
};

/** The label's size, a step up or down. */
const stepFontSize = (by: number) =>
    emit('update', {
        fontSize: Math.min(400, Math.max(6, round(props.label.fontSize) + by)),
    });
</script>

<template>
    <!-- Where the label sits in whatever it is written on -->
    <div v-if="label" class="inspector-align" data-test="align-config">
        <p class="inspector-label">Label</p>
        <div class="inspector-segments">
            <button
                v-for="option in aligns"
                :key="option.value"
                type="button"
                :title="option.title"
                :class="{ 'is-on': label.align === option.value }"
                :data-test="`align-${option.value}`"
                @click="emit('update', { align: option.value })"
            >
                <component :is="option.icon" class="size-4" />
            </button>
            <span class="inspector-divider" />
            <button
                v-for="option in verticalAligns"
                :key="option.value"
                type="button"
                :title="option.title"
                :class="{
                    'is-on': label.verticalAlign === option.value,
                }"
                :data-test="`valign-${option.value}`"
                @click="emit('update', { verticalAlign: option.value })"
            >
                <component :is="option.icon" class="size-4" />
            </button>
        </div>

        <!-- Its type: face and size, room round it, light Markdown -->
        <div class="inspector-row" data-test="font-config">
            <select
                class="inspector-select"
                aria-label="Font"
                :value="label.fontFamily"
                :style="{ fontFamily: FONT_FAMILIES[label.fontFamily] }"
                data-test="prop-font"
                @change="
                    emit('update', {
                        fontFamily: ($event.target as HTMLSelectElement)
                            .value as FontFamily,
                    })
                "
            >
                <option
                    v-for="option in families"
                    :key="option.value"
                    :value="option.value"
                    :style="{ fontFamily: FONT_FAMILIES[option.value] }"
                >
                    {{ option.label }}
                </option>
            </select>
            <div class="inspector-stepper" title="Text size">
                <button
                    type="button"
                    title="Smaller"
                    data-test="font-smaller"
                    @click="stepFontSize(-2)"
                >
                    <Minus class="size-3.5" />
                </button>
                <input
                    type="number"
                    min="6"
                    max="400"
                    aria-label="Text size"
                    :value="round(label.fontSize)"
                    data-test="prop-font-size"
                    @change="onLabelNumber('fontSize', 6, 400, $event)"
                />
                <button
                    type="button"
                    title="Larger"
                    data-test="font-larger"
                    @click="stepFontSize(2)"
                >
                    <Plus class="size-3.5" />
                </button>
            </div>
        </div>
        <label
            class="inspector-slider"
            title="Room between the edge and the words"
        >
            Padding
            <input
                type="range"
                min="0"
                :max="Math.max(64, round(label.padding))"
                :value="round(label.padding)"
                data-test="prop-padding"
                @input="onLabelNumber('padding', 0, 200, $event)"
            />
            <span>{{ round(label.padding) }}</span>
        </label>
        <label
            class="inspector-switch"
            title="# heading, - bullet, **bold**, *italic*"
        >
            Markdown
            <Switch
                :model-value="label.rich"
                data-test="prop-rich"
                @update:model-value="emit('update', { rich: $event })"
            />
        </label>
    </div>
</template>

<style scoped>
.inspector-row {
    display: flex;
    gap: 0.375rem;
}
.inspector-select {
    flex: 1;
    min-width: 0;
    height: 1.75rem;
    padding: 0 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

/* A number with a step either side of it */
.inspector-stepper {
    display: flex;
    align-items: stretch;
    height: 1.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.inspector-stepper button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.625rem;
    color: var(--muted-foreground);
    cursor: pointer;
}
.inspector-stepper button:hover {
    color: var(--foreground);
    background-color: var(--muted);
}
.inspector-stepper input {
    width: 2.5rem;
    padding: 0;
    font-size: 0.75rem;
    text-align: center;
    color: var(--foreground);
    background-color: var(--background);
    border: 0;
    border-inline: 1px solid var(--border);
    appearance: textfield;
    -moz-appearance: textfield;
}
.inspector-stepper input::-webkit-outer-spin-button,
.inspector-stepper input::-webkit-inner-spin-button {
    appearance: none;
    margin: 0;
}

/* Keeps the two halves of the label controls apart */
.inspector-divider {
    width: 1px;
    height: 1.25rem;
    margin: 0 0.25rem;
    background-color: var(--border);
}
</style>
