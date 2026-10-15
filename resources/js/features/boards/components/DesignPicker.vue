<script setup lang="ts">
import { computed } from 'vue';
import { PATHS } from '@/features/boards/composables/items';
import type { ItemKind } from '@/features/boards/composables/items';
import { designsOf } from '@/features/boards/composables/shapes';
import type { Design } from '@/features/boards/composables/shapes';
import { iconPath } from '@/features/boards/composables/styles';

// The ways a kind can be drawn -- a database as a cylinder, a stack of
// discs, a card with an icon -- each a small picture to click.
const props = defineProps<{ kind: ItemKind; variant?: string }>();

const emit = defineEmits<{ pick: [string] }>();

const designs = computed(() => designsOf(props.kind));
const chosen = computed(() =>
    designs.value.some((design) => design.id === props.variant)
        ? props.variant
        : 'classic',
);

const outline = (design: Design) => design.path ?? PATHS[props.kind] ?? '';
</script>

<template>
    <div class="design-picker" data-test="design-picker">
        <button
            v-for="design in designs"
            :key="design.id"
            type="button"
            class="design-tile"
            :class="{ 'is-on': chosen === design.id }"
            :title="design.label"
            :aria-pressed="chosen === design.id"
            :data-test="`design-${design.id}`"
            @click="emit('pick', design.id)"
        >
            <svg viewBox="-6 -6 112 112" aria-hidden="true">
                <rect
                    v-if="design.frame === 'card'"
                    x="4"
                    y="14"
                    width="92"
                    height="72"
                    rx="12"
                    class="design-fill"
                />
                <circle
                    v-else-if="design.frame === 'badge'"
                    cx="50"
                    cy="50"
                    r="46"
                    class="design-fill"
                />
                <path
                    v-else-if="!design.icon"
                    :d="outline(design)"
                    class="design-fill"
                    vector-effect="non-scaling-stroke"
                />
                <path
                    v-if="design.shade"
                    :d="design.shade"
                    class="design-shade"
                    vector-effect="non-scaling-stroke"
                />
                <path
                    v-if="design.solid"
                    :d="design.solid"
                    class="design-solid"
                />
                <path
                    v-if="design.icon"
                    :d="iconPath(design.icon)"
                    class="design-icon"
                    :transform="
                        design.frame === 'card'
                            ? 'translate(32 26) scale(1.5)'
                            : design.frame === 'badge'
                              ? 'translate(26 26) scale(2)'
                              : 'translate(8 8) scale(3.5)'
                    "
                />
            </svg>
            <span>{{ design.label }}</span>
        </button>
    </div>
</template>

<style scoped>
.design-picker {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(3.5rem, 1fr));
    gap: 0.375rem;
}

.design-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 0.375rem 0.25rem 0.25rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.design-tile:hover {
    color: var(--foreground);
    background-color: var(--muted);
}
.design-tile.is-on {
    color: var(--primary);
    border-color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 8%, transparent);
}
.design-tile svg {
    width: 2rem;
    height: 2rem;
}

.design-fill {
    fill: color-mix(in oklab, currentColor 10%, transparent);
    stroke: currentColor;
    stroke-width: 1.5;
}
.design-shade {
    fill: color-mix(in oklab, currentColor 30%, transparent);
    stroke: currentColor;
    stroke-width: 1.5;
}
.design-solid {
    fill: currentColor;
}
.design-icon {
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
</style>
