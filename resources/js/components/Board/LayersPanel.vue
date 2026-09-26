<script setup lang="ts">
import { Eye, EyeOff, GripVertical, Lock, LockOpen } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Item } from './board';
import { nameOf } from './board';

// The stack, top first -- the order you see, not the order it is painted in.
const props = defineProps<{ items: Item[]; selection: string[] }>();

const emit = defineEmits<{
    select: [{ id: string; add: boolean }];
    move: [{ id: string; index: number }];
    toggle: [{ id: string; field: 'hidden' | 'locked' }];
}>();

const stack = computed(() => [...props.items].reverse());

const dragging = ref<string | null>(null);
const over = ref<string | null>(null);

const onDrop = (target: Item) => {
    const id = dragging.value;

    dragging.value = null;
    over.value = null;

    if (!id || id === target.id) {
        return;
    }

    // The list runs top-first, so its index is the paint order reversed
    emit('move', {
        id,
        index: props.items.findIndex((item) => item.id === target.id),
    });
};
</script>

<template>
    <section class="layers" data-test="layers">
        <p class="layers-heading">Layers</p>

        <div class="layers-list">
            <div
                v-for="item in stack"
                :key="item.id"
                class="layers-row"
                :class="{
                    'is-selected': selection.includes(item.id),
                    'is-over': over === item.id,
                    'is-dimmed': item.hidden,
                }"
                draggable="true"
                :data-test="`layer-${item.id}`"
                @click="emit('select', { id: item.id, add: $event.shiftKey })"
                @dragstart="dragging = item.id"
                @dragover.prevent="over = item.id"
                @dragleave="over = over === item.id ? null : over"
                @drop.prevent="onDrop(item)"
                @dragend="
                    dragging = null;
                    over = null;
                "
            >
                <GripVertical class="layers-grip size-3.5" />
                <span class="layers-name">{{ nameOf(item) }}</span>

                <button
                    type="button"
                    :title="item.locked ? 'Unlock' : 'Lock'"
                    :class="{ 'is-on': item.locked }"
                    :data-test="`lock-${item.id}`"
                    @click.stop="
                        emit('toggle', { id: item.id, field: 'locked' })
                    "
                >
                    <Lock v-if="item.locked" class="size-3.5" />
                    <LockOpen v-else class="size-3.5" />
                </button>
                <button
                    type="button"
                    :title="item.hidden ? 'Show' : 'Hide'"
                    :class="{ 'is-on': item.hidden }"
                    :data-test="`hide-${item.id}`"
                    @click.stop="
                        emit('toggle', { id: item.id, field: 'hidden' })
                    "
                >
                    <EyeOff v-if="item.hidden" class="size-3.5" />
                    <Eye v-else class="size-3.5" />
                </button>
            </div>
        </div>
    </section>
</template>

<style scoped>
.layers {
    display: flex;
    min-height: 0;
    flex: 1;
    flex-direction: column;
    gap: 0.375rem;
}

.layers-heading {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.layers-list {
    display: flex;
    min-height: 4rem;
    flex: 1;
    flex-direction: column;
    gap: 1px;
    overflow-y: auto;
}

.layers-row {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.375rem;
    font-size: 0.75rem;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.layers-row:hover {
    background-color: var(--muted);
}
.layers-row.is-selected {
    color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
    border-color: var(--primary);
}
/* Where the dragged row would land */
.layers-row.is-over {
    border-top-color: var(--primary);
}
.layers-row.is-dimmed .layers-name {
    opacity: 0.45;
}

.layers-grip {
    color: var(--muted-foreground);
    flex-shrink: 0;
    cursor: grab;
}

.layers-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.layers-row button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    height: 1.25rem;
    color: var(--muted-foreground);
    border-radius: var(--radius-sm);
    cursor: pointer;
    opacity: 0;
}
.layers-row:hover button,
.layers-row button.is-on {
    opacity: 1;
}
.layers-row button:hover {
    background-color: var(--background);
    color: var(--foreground);
}
</style>
