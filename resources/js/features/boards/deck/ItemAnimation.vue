<script setup lang="ts">
import { Play } from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import { computed } from 'vue';
import FoldSection from '@/components/common/FoldSection.vue';
import type { Item } from '../model/items';
import type { BuildKind, BuildStep } from './build';
import { KINDS, stepFor } from './build';
import StepRow from './StepRow.vue';

// An item's own animations on its slide: added, changed and taken off here,
// kept on the slide (its frame's list, where the order is set). Folded away
// until wanted, as most items on a board never move.
const props = defineProps<{ item: Item; slide: Item }>();
const emit = defineEmits<{ build: [build: BuildStep[]]; preview: [] }>();

const open = useLocalStorage('board.animate', false);

const all = computed(() => props.slide.build ?? []);

// Its steps, with their place in the slide's list
const own = computed(() =>
    all.value
        .map((step, index) => ({ step, index }))
        .filter(({ step }) => step.item === props.item.id),
);

const add = (kind: BuildKind) => {
    open.value = true;
    emit('build', [...all.value, stepFor(props.item.id, kind)]);
};

const change = (index: number, patch: Partial<BuildStep>) =>
    emit(
        'build',
        all.value.map((step, at) =>
            at === index ? { ...step, ...patch } : step,
        ),
    );

const remove = (index: number) =>
    emit(
        'build',
        all.value.filter((_, at) => at !== index),
    );
</script>

<template>
    <FoldSection
        :open="open || own.length > 0"
        title="Animate"
        :hint="
            own.length === 0
                ? undefined
                : own.length === 1
                  ? '1 animation'
                  : `${own.length} animations`
        "
        data-test="item-animation"
        @update:open="open = $event"
    >
        <div class="space-y-2">
            <StepRow
                v-for="{ step, index } in own"
                :key="index"
                :step="step"
                @change="change(index, $event)"
                @remove="remove(index)"
            />
            <div class="inspector-segments">
                <button
                    v-for="kind in KINDS"
                    :key="kind.id"
                    type="button"
                    :data-test="`animate-${kind.id}`"
                    @click="add(kind.id)"
                >
                    + {{ kind.label }}
                </button>
            </div>
            <p class="inspector-hint">
                Played on its slide, “{{ slide.text || 'Untitled' }}”, in the
                order its list sets.
                <button
                    v-if="own.length"
                    type="button"
                    class="inline-flex items-center gap-1 underline"
                    data-test="item-animation-preview"
                    @click="emit('preview')"
                >
                    <Play :size="11" />
                    Preview the slide
                </button>
            </p>
        </div>
    </FoldSection>
</template>
