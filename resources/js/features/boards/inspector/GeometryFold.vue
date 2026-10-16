<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core';
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

const onNumber = (field: 'x' | 'y' | 'width' | 'height', event: Event) => {
    const value = Number((event.target as HTMLInputElement).value);

    if (Number.isFinite(value)) {
        emit('resize', { [field]: value });
    }
};
</script>

<template>
    <!-- Where it is and how big, to the pixel: there when wanted -->
    <details
        class="inspector-fold"
        :open="geometryOpen"
        data-test="geometry"
        @toggle="geometryOpen = ($event.target as HTMLDetailsElement).open"
    >
        <summary>
            Position and size
            <span>{{ round(item.width) }} × {{ round(item.height) }}</span>
        </summary>
        <div class="inspector-grid">
            <label>
                X
                <input
                    type="number"
                    :value="round(item.x)"
                    data-test="prop-x"
                    @change="onNumber('x', $event)"
                />
            </label>
            <label>
                Y
                <input
                    type="number"
                    :value="round(item.y)"
                    data-test="prop-y"
                    @change="onNumber('y', $event)"
                />
            </label>
            <label>
                W
                <input
                    type="number"
                    :value="round(item.width)"
                    data-test="prop-width"
                    @change="onNumber('width', $event)"
                />
            </label>
            <label>
                H
                <input
                    type="number"
                    :value="round(item.height)"
                    data-test="prop-height"
                    @change="onNumber('height', $event)"
                />
            </label>
        </div>
    </details>
</template>

<style scoped>
/* A fold keeps the fiddly settings out of the way until they are wanted */
.inspector-fold > summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.25rem 0;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
    cursor: pointer;
}
.inspector-fold > summary span {
    font-weight: 400;
    letter-spacing: 0;
    text-transform: none;
}
.inspector-fold[open] > summary {
    margin-bottom: 0.25rem;
}

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
.inspector-grid input {
    width: 100%;
    min-width: 0;
    height: 1.75rem;
    padding: 0 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
</style>
