<script setup lang="ts">
import {
    ChevronDown,
    ChevronRight,
    Eye,
    EyeOff,
    Frame,
    GripVertical,
    Lock,
    LockOpen,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { nameOf } from '../../composables/board/items';
import type { Item } from '../../composables/board/items';
import { groupItems } from '../../composables/board/layers';

// The stack, top first -- the order you see, not the order it is painted in.
const props = defineProps<{ items: Item[]; selection: string[] }>();

const emit = defineEmits<{
    select: [{ id: string; add: boolean }];
    move: [{ id: string; index: number }];
    toggle: [{ id: string; field: 'hidden' | 'locked' }];
}>();

// The whole list, folded away when the settings above want the room
const folded = ref(false);

const collapsed = ref(new Set<string>());

const toggleGroup = (key: string) => {
    const shut = new Set(collapsed.value);

    if (!shut.delete(key)) {
        shut.add(key);
    }

    collapsed.value = shut;
};

const groups = computed(() => groupItems(props.items));

// --- dragging a row to restack it
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
        <button
            type="button"
            class="layers-heading"
            :aria-expanded="!folded"
            data-test="layers-fold"
            @click="folded = !folded"
        >
            <ChevronRight v-if="folded" class="size-3.5" />
            <ChevronDown v-else class="size-3.5" />
            Layers
            <span class="layers-total">{{ items.length }}</span>
        </button>

        <div v-show="!folded" class="layers-list">
            <template v-for="group in groups" :key="group.key">
                <!-- A frame is the heading for whatever sits on it -->
                <div
                    class="layers-group"
                    :class="{
                        'is-selected':
                            group.frame && selection.includes(group.frame.id),
                        'is-dimmed': group.frame?.hidden,
                    }"
                    :data-test="`layer-group-${group.key}`"
                    @click="
                        group.frame &&
                        emit('select', {
                            id: group.frame.id,
                            add: $event.shiftKey,
                        })
                    "
                >
                    <button
                        type="button"
                        class="layers-fold"
                        :title="
                            collapsed.has(group.key)
                                ? 'Show these'
                                : 'Hide these'
                        "
                        :data-test="`fold-${group.key}`"
                        @click.stop="toggleGroup(group.key)"
                    >
                        <ChevronRight
                            v-if="collapsed.has(group.key)"
                            class="size-3.5"
                        />
                        <ChevronDown v-else class="size-3.5" />
                    </button>

                    <Frame v-if="group.frame" class="size-3.5 shrink-0" />
                    <span class="layers-name">{{
                        group.frame ? nameOf(group.frame) : 'On the board'
                    }}</span>
                    <span class="layers-count">{{ group.items.length }}</span>

                    <template v-if="group.frame">
                        <button
                            type="button"
                            :title="group.frame.locked ? 'Unlock' : 'Lock'"
                            :class="{ 'is-on': group.frame.locked }"
                            :data-test="`lock-${group.frame.id}`"
                            @click.stop="
                                emit('toggle', {
                                    id: group.frame.id,
                                    field: 'locked',
                                })
                            "
                        >
                            <Lock v-if="group.frame.locked" class="size-3.5" />
                            <LockOpen v-else class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            :title="group.frame.hidden ? 'Show' : 'Hide'"
                            :class="{ 'is-on': group.frame.hidden }"
                            :data-test="`hide-${group.frame.id}`"
                            @click.stop="
                                emit('toggle', {
                                    id: group.frame.id,
                                    field: 'hidden',
                                })
                            "
                        >
                            <EyeOff
                                v-if="group.frame.hidden"
                                class="size-3.5"
                            />
                            <Eye v-else class="size-3.5" />
                        </button>
                    </template>
                </div>

                <p
                    v-if="!group.items.length && !collapsed.has(group.key)"
                    class="layers-empty"
                >
                    Nothing on it yet
                </p>

                <div
                    v-for="item in collapsed.has(group.key) ? [] : group.items"
                    :key="item.id"
                    class="layers-row"
                    :class="{
                        'is-selected': selection.includes(item.id),
                        'is-over': over === item.id,
                        'is-dimmed': item.hidden,
                    }"
                    draggable="true"
                    :data-group="group.key"
                    :data-test="`layer-${item.id}`"
                    @click="
                        emit('select', { id: item.id, add: $event.shiftKey })
                    "
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
            </template>
        </div>
    </section>
</template>

<style scoped>
.layers {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.layers-heading {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    margin-left: -0.25rem;
    cursor: pointer;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.layers-total {
    margin-left: auto;
    font-weight: 400;
    letter-spacing: 0;
}

.layers-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

/* The frame a group belongs to, and how many things are on it */
.layers-group {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    margin-top: 0.25rem;
    padding: 0.25rem 0.375rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--foreground);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.layers-group:hover {
    background-color: var(--muted);
}
.layers-group.is-selected {
    color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
    border-color: var(--primary);
}
.layers-group.is-dimmed .layers-name {
    opacity: 0.45;
}
.layers-group button {
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
.layers-group:hover button,
.layers-group button.is-on,
.layers-group .layers-fold {
    opacity: 1;
}
.layers-count {
    font-size: 0.6875rem;
    font-weight: 400;
    color: var(--muted-foreground);
}
.layers-empty {
    padding: 0.25rem 0.375rem 0.25rem 1.75rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

/* Rows sit under their heading */
.layers-row {
    margin-left: 0.75rem;
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
