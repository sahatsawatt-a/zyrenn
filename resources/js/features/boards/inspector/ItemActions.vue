<script setup lang="ts">
import {
    BringToFront,
    ChevronDown,
    ChevronUp,
    Copy,
    Group,
    RotateCw,
    SendToBack,
    Shapes,
    Trash2,
    Ungroup,
} from '@lucide/vue';

// What can be done to whatever is selected: its place in the stack, a
// quarter turn for a part of a circuit, a group made or undone, kept as a
// shape of one's own, a copy, and away with it.
defineProps<{
    turnable: boolean;
    // Several things that can be made one group, or one group that can be undone
    groupable?: boolean;
    grouped?: boolean;
    // Whether it can be kept as a shape of one's own, for any board
    savable?: boolean;
}>();
const emit = defineEmits<{
    reorder: [to: 'front' | 'back' | 'forward' | 'backward'];
    turn: [];
    group: [];
    ungroup: [];
    'save-shape': [];
    duplicate: [];
    remove: [];
}>();
</script>

<template>
    <div class="inspector-actions">
        <button
            type="button"
            title="Bring to front (Ctrl+Shift+])"
            data-test="to-front"
            @click="emit('reorder', 'front')"
        >
            <BringToFront class="size-4" />
        </button>
        <button
            type="button"
            title="Bring forward (Ctrl+])"
            data-test="forward"
            @click="emit('reorder', 'forward')"
        >
            <ChevronUp class="size-4" />
        </button>
        <button
            type="button"
            title="Send backward (Ctrl+[)"
            data-test="backward"
            @click="emit('reorder', 'backward')"
        >
            <ChevronDown class="size-4" />
        </button>
        <button
            type="button"
            title="Send to back (Ctrl+Shift+[)"
            data-test="to-back"
            @click="emit('reorder', 'back')"
        >
            <SendToBack class="size-4" />
        </button>
        <button
            v-if="turnable"
            type="button"
            title="Turn a quarter turn (R)"
            data-test="symbol-turn"
            @click="emit('turn')"
        >
            <RotateCw class="size-4" />
        </button>
        <button
            v-if="grouped"
            type="button"
            title="Ungroup: each on its own again"
            data-test="ungroup"
            @click="emit('ungroup')"
        >
            <Ungroup class="size-4" />
        </button>
        <button
            v-else-if="groupable"
            type="button"
            title="Group: picked, moved and sized together"
            data-test="group"
            @click="emit('group')"
        >
            <Group class="size-4" />
        </button>
        <button
            v-if="savable"
            type="button"
            title="Save as my shape, for any board"
            data-test="save-shape"
            @click="emit('save-shape')"
        >
            <Shapes class="size-4" />
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
</template>

<style scoped>
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
</style>
