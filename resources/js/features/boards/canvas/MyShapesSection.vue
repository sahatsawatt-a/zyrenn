<script setup lang="ts">
import { Shapes, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { MyShape } from '../model/myShapes';

// The top of the shape library: shapes of one's own, made on a board, to put
// on this one -- a tap places one. "Manage" lets them be renamed, or taken
// away with a second tap, each where it is.

const props = defineProps<{ shapes: MyShape[]; search: string }>();

const emit = defineEmits<{
    place: [shape: MyShape];
    rename: [shape: MyShape, name: string];
    remove: [shape: MyShape];
}>();

const managing = ref(false);
// The one asked to be deleted, waiting for the second tap
const deleting = ref<string | null>(null);

const shown = computed(() => {
    const words = props.search.trim().toLowerCase();

    return words
        ? props.shapes.filter((shape) =>
              shape.name.toLowerCase().includes(words),
          )
        : props.shapes;
});

const changeable = computed(() => props.shapes.some((one) => one.can_change));

function rename(shape: MyShape, event: Event) {
    const name = (event.target as HTMLInputElement).value.trim();

    if (name && name !== shape.name) {
        emit('rename', shape, name);
    }
}

function remove(shape: MyShape) {
    if (deleting.value !== shape.ref_id) {
        deleting.value = shape.ref_id;

        return;
    }

    deleting.value = null;
    emit('remove', shape);
}
</script>

<template>
    <section
        v-if="shown.length || !search.trim()"
        class="mt-2.5"
        data-test="my-shapes"
    >
        <div class="mine-head">
            <p class="mine-heading">My shapes</p>
            <button
                v-if="changeable"
                type="button"
                class="mine-link"
                :aria-pressed="managing"
                data-test="my-shapes-manage"
                @click="
                    managing = !managing;
                    deleting = null;
                "
            >
                {{ managing ? 'Done' : 'Manage' }}
            </button>
        </div>

        <p v-if="!shapes.length" class="mine-empty">
            Select something you drew and choose
            <Shapes class="inline size-3.5 align-[-2px]" /> Save as my shape.
        </p>

        <div v-else class="mine-grid">
            <div
                v-for="shape in shown"
                :key="shape.ref_id"
                class="mine-item"
                :data-test="`my-shape-${shape.name}`"
            >
                <button
                    type="button"
                    class="mine-pick"
                    :title="
                        shape.shared
                            ? `${shape.name}, kept for the project`
                            : shape.name
                    "
                    :disabled="managing"
                    @click="emit('place', shape)"
                >
                    <img
                        v-if="shape.preview"
                        :src="shape.preview"
                        alt=""
                        class="mine-preview"
                    />
                    <Shapes v-else class="text-muted-foreground size-6" />
                    <span v-if="!managing" class="mine-name">
                        <Users
                            v-if="shape.shared"
                            class="mr-0.5 inline size-3 align-[-1px]"
                        />{{ shape.name }}
                    </span>
                </button>
                <template v-if="managing && shape.can_change">
                    <input
                        :value="shape.name"
                        maxlength="80"
                        class="mine-input"
                        :aria-label="`Rename ${shape.name}`"
                        data-test="my-shape-name"
                        @change="rename(shape, $event)"
                        @keydown.enter="
                            ($event.target as HTMLInputElement).blur()
                        "
                    />
                    <button
                        type="button"
                        class="mine-delete"
                        :class="{ 'is-asking': deleting === shape.ref_id }"
                        :aria-label="
                            deleting === shape.ref_id
                                ? `Tap again to delete ${shape.name}`
                                : `Delete ${shape.name}`
                        "
                        data-test="my-shape-delete"
                        @click="remove(shape)"
                    >
                        <Trash2 class="size-3.5" />
                        <span v-if="deleting === shape.ref_id">Sure?</span>
                    </button>
                </template>
            </div>
        </div>
    </section>
</template>

<style scoped>
.mine-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
}

.mine-heading {
    margin-bottom: 0.25rem;
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.mine-link {
    font-size: 11px;
    color: var(--muted-foreground);
}
.mine-link:hover {
    color: var(--foreground);
}

.mine-empty {
    font-size: 12px;
    line-height: 1.4;
    color: var(--muted-foreground);
}

.mine-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 4px;
}

.mine-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.mine-pick {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 6px 2px;
    border-radius: var(--radius-md);
}
.mine-pick:hover:not(:disabled) {
    background-color: var(--muted);
}

.mine-preview {
    width: 2.5rem;
    height: 2rem;
    object-fit: contain;
}

.mine-name {
    max-width: 100%;
    overflow: hidden;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mine-input {
    width: 100%;
    padding: 1px 4px;
    font-size: 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--background);
}

.mine-delete {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 2px;
    font-size: 11px;
    color: var(--muted-foreground);
    border-radius: var(--radius-sm);
}
.mine-delete:hover,
.mine-delete.is-asking {
    color: var(--destructive);
}
</style>
