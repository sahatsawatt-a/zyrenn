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
import Tip from '@/components/common/Tip.vue';

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
        <Tip text="Bring to front (Ctrl+Shift+])">
            <button
                type="button"
                aria-label="Bring to front (Ctrl+Shift+])"
                data-test="to-front"
                @click="emit('reorder', 'front')"
            >
                <BringToFront class="size-4" />
            </button>
        </Tip>
        <Tip text="Bring forward (Ctrl+])">
            <button
                type="button"
                aria-label="Bring forward (Ctrl+])"
                data-test="forward"
                @click="emit('reorder', 'forward')"
            >
                <ChevronUp class="size-4" />
            </button>
        </Tip>
        <Tip text="Send backward (Ctrl+[)">
            <button
                type="button"
                aria-label="Send backward (Ctrl+[)"
                data-test="backward"
                @click="emit('reorder', 'backward')"
            >
                <ChevronDown class="size-4" />
            </button>
        </Tip>
        <Tip text="Send to back (Ctrl+Shift+[)">
            <button
                type="button"
                aria-label="Send to back (Ctrl+Shift+[)"
                data-test="to-back"
                @click="emit('reorder', 'back')"
            >
                <SendToBack class="size-4" />
            </button>
        </Tip>
        <Tip v-if="turnable" text="Turn a quarter turn (R)">
            <button
                type="button"
                aria-label="Turn a quarter turn (R)"
                data-test="symbol-turn"
                @click="emit('turn')"
            >
                <RotateCw class="size-4" />
            </button>
        </Tip>
        <Tip v-if="grouped" text="Ungroup: each on its own again">
            <button
                type="button"
                aria-label="Ungroup: each on its own again"
                data-test="ungroup"
                @click="emit('ungroup')"
            >
                <Ungroup class="size-4" />
            </button>
        </Tip>
        <Tip
            v-else-if="groupable"
            text="Group: picked, moved and sized together"
        >
            <button
                type="button"
                aria-label="Group: picked, moved and sized together"
                data-test="group"
                @click="emit('group')"
            >
                <Group class="size-4" />
            </button>
        </Tip>
        <Tip v-if="savable" text="Save as my shape, for any board">
            <button
                type="button"
                aria-label="Save as my shape, for any board"
                data-test="save-shape"
                @click="emit('save-shape')"
            >
                <Shapes class="size-4" />
            </button>
        </Tip>
        <Tip text="Duplicate (Ctrl+D)">
            <button
                type="button"
                aria-label="Duplicate (Ctrl+D)"
                @click="emit('duplicate')"
            >
                <Copy class="size-4" />
            </button>
        </Tip>
        <Tip text="Delete">
            <button
                type="button"
                aria-label="Delete"
                data-test="inspector-delete"
                @click="emit('remove')"
            >
                <Trash2 class="size-4" />
            </button>
        </Tip>
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
