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
import type { Item } from './board';
import { boundsOf, isConnector, nameOf } from './board';

// The stack, top first -- the order you see, not the order it is painted in.
const props = defineProps<{ items: Item[]; selection: string[] }>();

const emit = defineEmits<{
    select: [{ id: string; add: boolean }];
    move: [{ id: string; index: number }];
    toggle: [{ id: string; field: 'hidden' | 'locked' }];
}>();

type Group = { key: string; frame: Item | null; items: Item[] };

const collapsed = ref(new Set<string>());

const toggleGroup = (key: string) => {
    const shut = new Set(collapsed.value);

    if (!shut.delete(key)) {
        shut.add(key);
    }

    collapsed.value = shut;
};

/** Whether the middle of an item falls inside a frame. */
const sitsIn = (item: Item, frame: Item) => {
    const box = boundsOf(item);
    const slide = boundsOf(frame);
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;

    return (
        x >= slide.x &&
        x <= slide.x + slide.width &&
        y >= slide.y &&
        y <= slide.y + slide.height
    );
};

/**
 * The stack, under the frame each thing sits on. A board is usually a handful
 * of slides with a dozen things on each, and one flat list of forty rows says
 * nothing about which slide anything belongs to.
 */
const groups = computed<Group[]>(() => {
    const frames = props.items.filter((item) => item.kind === 'frame');
    const onFrame = new Map<string, Item[]>(
        frames.map((frame) => [frame.id, []]),
    );
    const loose: Item[] = [];
    // Which frame each thing was filed under, so a connector can follow the
    // shapes it joins whichever order they are painted in
    const filedUnder = new Map<string, string>();

    for (const item of props.items) {
        if (item.kind === 'frame' || isConnector(item)) {
            continue;
        }

        const home = frames.find((frame) => sitsIn(item, frame));

        if (home) {
            filedUnder.set(item.id, home.id);
        }
    }

    for (const item of props.items) {
        if (item.kind === 'frame') {
            continue;
        }

        // A connector has no box of its own -- it is wherever its ends are --
        // so it is filed with whatever it joins rather than by where it sits
        const frameId = isConnector(item)
            ? (filedUnder.get(item.from?.item ?? '') ??
              filedUnder.get(item.to?.item ?? ''))
            : filedUnder.get(item.id);

        const home = frameId ? onFrame.get(frameId) : undefined;

        (home ?? loose).push(item);
    }

    // Each group runs top of the stack first, as the flat list did
    const grouped: Group[] = frames.map((frame) => ({
        key: frame.id,
        frame,
        items: [...(onFrame.get(frame.id) ?? [])].reverse(),
    }));

    if (loose.length || frames.length === 0) {
        grouped.push({
            key: 'board',
            frame: null,
            items: [...loose].reverse(),
        });
    }

    return grouped;
});

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
