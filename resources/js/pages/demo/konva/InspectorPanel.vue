<script setup lang="ts">
import { BringToFront, Copy, Play, SendToBack, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import type { Item } from './board';
import ColourPicker from './ColourPicker.vue';

// Lucidchart's right-hand panel: what is selected, and every property of it in
// one place instead of hidden behind a toolbar popover.
const props = defineProps<{
    selection: Item[];
    fill: string;
    stroke: string;
    itemCount: number;
    frameCount: number;
}>();

const emit = defineEmits<{
    paint: [string];
    'paint-stroke': [string];
    resize: [{ width?: number; height?: number; x?: number; y?: number }];
    duplicate: [];
    remove: [];
    reorder: ['front' | 'back'];
    present: [];
}>();

const one = computed(() =>
    props.selection.length === 1 ? props.selection[0] : null,
);

const summary = computed(() => {
    if (!props.selection.length) {
        return 'Nothing selected';
    }

    return props.selection.length === 1
        ? props.selection[0].kind
        : `${props.selection.length} items`;
});

const round = (value: number) => Math.round(value);

const onNumber = (field: 'x' | 'y' | 'width' | 'height', event: Event) => {
    const value = Number((event.target as HTMLInputElement).value);

    if (Number.isFinite(value)) {
        emit('resize', { [field]: value });
    }
};
</script>

<template>
    <aside class="inspector" data-test="inspector">
        <div class="inspector-head">
            <p class="inspector-title" data-test="inspector-title">
                {{ summary }}
            </p>
            <p class="inspector-sub">
                {{ itemCount }} items · {{ frameCount }} frames
            </p>
        </div>

        <div v-if="selection.length" class="inspector-body">
            <div class="inspector-actions">
                <button
                    type="button"
                    title="Bring to front"
                    @click="emit('reorder', 'front')"
                >
                    <BringToFront class="size-4" />
                </button>
                <button
                    type="button"
                    title="Send to back"
                    @click="emit('reorder', 'back')"
                >
                    <SendToBack class="size-4" />
                </button>
                <button
                    type="button"
                    title="Duplicate (Ctrl+D)"
                    @click="emit('duplicate')"
                >
                    <Copy class="size-4" />
                </button>
                <button
                    type="button"
                    title="Delete"
                    data-test="inspector-delete"
                    @click="emit('remove')"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>

            <div v-if="one" class="inspector-grid">
                <label>
                    X
                    <input
                        type="number"
                        :value="round(one.x)"
                        data-test="prop-x"
                        @change="onNumber('x', $event)"
                    />
                </label>
                <label>
                    Y
                    <input
                        type="number"
                        :value="round(one.y)"
                        @change="onNumber('y', $event)"
                    />
                </label>
                <label>
                    W
                    <input
                        type="number"
                        :value="round(one.width)"
                        data-test="prop-width"
                        @change="onNumber('width', $event)"
                    />
                </label>
                <label>
                    H
                    <input
                        type="number"
                        :value="round(one.height)"
                        @change="onNumber('height', $event)"
                    />
                </label>
            </div>

            <ColourPicker
                :model-value="fill"
                label="Fill"
                @update:model-value="emit('paint', $event)"
            />

            <div class="inspector-line">
                <span class="inspector-line-label">Line</span>
                <span
                    class="inspector-chip"
                    :style="{ backgroundColor: stroke }"
                />
                <input
                    type="color"
                    :value="stroke"
                    data-test="stroke-input"
                    @input="
                        emit(
                            'paint-stroke',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </div>
        </div>

        <p v-else class="inspector-hint">
            Pick a shape from the left and drag it out on the canvas. Select
            something to change its colour, size and stacking.
        </p>

        <button
            type="button"
            class="inspector-present"
            :disabled="!frameCount"
            data-test="present"
            @click="emit('present')"
        >
            <Play class="size-4" />
            Present {{ frameCount }} frame{{ frameCount === 1 ? '' : 's' }}
        </button>
    </aside>
</template>

<style scoped>
.inspector {
    display: flex;
    flex-direction: column;
    width: 16rem;
    flex-shrink: 0;
    gap: 0.75rem;
    padding: 0.75rem;
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    overflow-y: auto;
}

.inspector-title {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: capitalize;
}

.inspector-sub,
.inspector-hint {
    font-size: 0.75rem;
    color: var(--muted-foreground);
}

.inspector-body {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.inspector-actions {
    display: flex;
    gap: 2px;
}
.inspector-actions button {
    display: inline-flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    height: 2rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.inspector-actions button:hover {
    background-color: var(--muted);
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

.inspector-line {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
}
.inspector-line-label {
    color: var(--muted-foreground);
}
.inspector-chip {
    width: 1.25rem;
    height: 1.25rem;
    border: 1px solid var(--border);
    border-radius: 999px;
}
.inspector-line input {
    width: 2rem;
    height: 1.75rem;
    padding: 0;
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.inspector-present {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    height: 2.25rem;
    margin-top: auto;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-radius: var(--radius-lg);
    cursor: pointer;
}
.inspector-present:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
