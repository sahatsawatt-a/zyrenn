<script setup lang="ts">
import {
    AlignCenterHorizontal,
    AlignCenterVertical,
    AlignEndHorizontal,
    AlignEndVertical,
    AlignStartHorizontal,
    AlignStartVertical,
} from '@lucide/vue';
import type { Component } from 'vue';
import NumberField from '@/components/common/NumberField.vue';
import RangeField from '@/components/common/RangeField.vue';
import Tip from '@/components/common/Tip.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import type { Align, FontFamily, Item, VerticalAlign } from '../model/items';
import { FONT_CHOICES, FONT_FAMILIES } from '../model/items';
import { loadHandFonts } from '../drawing/fonts';

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

const families = FONT_CHOICES;

/**
 * A typeface picked: a handwritten one is fetched first, so the label is
 * measured -- and a box hugging its words sized -- in the font itself.
 */
const pickFont = async (fontFamily: FontFamily) => {
    await loadHandFonts();
    emit('update', { fontFamily });
};

const round = (value: number) => Math.round(value);

/** A number set for every selected label, held to what makes sense. */
const onLabelNumber = (
    field: 'fontSize' | 'padding',
    least: number,
    most: number,
    value: number | null,
) => {
    if (value !== null && Number.isFinite(value)) {
        emit('update', { [field]: Math.min(most, Math.max(least, value)) });
    }
};
</script>

<template>
    <!-- Where the label sits in whatever it is written on -->
    <div v-if="label" class="inspector-align" data-test="align-config">
        <p class="inspector-label">Label</p>
        <div class="inspector-segments">
            <Tip
                v-for="option in aligns"
                :key="option.value"
                :text="option.title"
            >
                <button
                    type="button"
                    :aria-label="option.title"
                    :class="{ 'is-on': label.align === option.value }"
                    :data-test="`align-${option.value}`"
                    @click="emit('update', { align: option.value })"
                >
                    <component :is="option.icon" class="size-4" />
                </button>
            </Tip>
            <span class="inspector-divider" />
            <Tip
                v-for="option in verticalAligns"
                :key="option.value"
                :text="option.title"
            >
                <button
                    type="button"
                    :aria-label="option.title"
                    :class="{
                        'is-on': label.verticalAlign === option.value,
                    }"
                    :data-test="`valign-${option.value}`"
                    @click="emit('update', { verticalAlign: option.value })"
                >
                    <component :is="option.icon" class="size-4" />
                </button>
            </Tip>
        </div>

        <!-- Its type: face and size, room round it, light Markdown -->
        <div class="inspector-row" data-test="font-config">
            <Select
                :model-value="label.fontFamily"
                @update:model-value="pickFont($event as FontFamily)"
            >
                <SelectTrigger
                    size="sm"
                    class="h-8 min-w-0 flex-1 px-2 text-xs"
                    aria-label="Font"
                    :style="{ fontFamily: FONT_FAMILIES[label.fontFamily] }"
                    data-test="prop-font"
                    :data-value="label.fontFamily"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in families"
                        :key="option.value"
                        :value="option.value"
                        :style="{ fontFamily: FONT_FAMILIES[option.value] }"
                        :data-test="`font-${option.value}`"
                        >{{ option.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <NumberField
                :model-value="round(label.fontSize)"
                label="Text size"
                :min="6"
                :max="400"
                :step="2"
                compact
                data-test="prop-font-size"
                @change="onLabelNumber('fontSize', 6, 400, $event)"
            />
        </div>
        <label class="inspector-slider">
            Padding
            <RangeField
                :model-value="round(label.padding)"
                label="Room between the edge and the words"
                :min="0"
                :max="Math.max(64, round(label.padding))"
                class="flex-1"
                data-test="prop-padding"
                @update:model-value="onLabelNumber('padding', 0, 200, $event)"
            />
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
/* Keeps the two halves of the label controls apart */
.inspector-divider {
    width: 1px;
    height: 1.25rem;
    margin: 0 0.25rem;
    background-color: var(--border);
}
</style>
