<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core';
import FoldSection from '@/components/common/FoldSection.vue';
import NumberField from '@/components/common/NumberField.vue';
import type { Item } from '../model/items';

// Where one item is and how big, to the pixel: seldom wanted, so folded
// away, and left however the person last had it.
defineProps<{ item: Item }>();
const emit = defineEmits<{
    resize: [
        change: { x?: number; y?: number; width?: number; height?: number },
    ];
}>();

const geometryOpen = useLocalStorage('board.geometry', false);

const round = (value: number) => Math.round(value);

const FIELDS = [
    { field: 'x', short: 'X', label: 'Left' },
    { field: 'y', short: 'Y', label: 'Top' },
    { field: 'width', short: 'W', label: 'Width' },
    { field: 'height', short: 'H', label: 'Height' },
] as const;

const onNumber = (
    field: 'x' | 'y' | 'width' | 'height',
    value: number | null,
) => {
    if (value !== null && Number.isFinite(value)) {
        emit('resize', { [field]: value });
    }
};
</script>

<template>
    <!-- Where it is and how big, to the pixel: there when wanted, and kept
         in the page while folded, as a <details> keeps it -->
    <FoldSection
        v-model:open="geometryOpen"
        title="Position and size"
        :hint="`${round(item.width)} × ${round(item.height)}`"
        data-test="geometry"
        :unmount-on-hide="false"
    >
        <div class="inspector-grid">
            <label v-for="one in FIELDS" :key="one.field">
                {{ one.short }}
                <NumberField
                    :model-value="round(item[one.field])"
                    :label="one.label"
                    :min="
                        one.field === 'width' || one.field === 'height'
                            ? 1
                            : undefined
                    "
                    compact
                    class="min-w-0 flex-1"
                    :data-test="`prop-${one.field}`"
                    @change="onNumber(one.field, $event)"
                />
            </label>
        </div>
    </FoldSection>
</template>

<style scoped>
.inspector-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.375rem;
}
.inspector-grid label {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}
</style>
