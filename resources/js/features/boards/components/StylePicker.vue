<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    COLOUR_SETS,
    COLOURS,
    STYLES,
    styled,
} from '@/features/boards/composables/styles';

// Ready-made looks: a colour set to pick from, and in it every colour in
// every style -- soft, solid, outline -- drawn as it would look. A click puts
// one on. Which set is open is the person's own, kept for next time.
const props = defineProps<{
    // Whether what it is put on has a shadow under it, offered beside the
    // styles -- for what is chosen, not for a whole board
    shadow?: boolean;
    // For a whole board: a style at a time, in every colour of the set,
    // rather than one colour in one style
    board?: boolean;
}>();

const emit = defineEmits<{
    pick: [{ set: string; style: string; colour: string }];
    shadow: [boolean];
}>();

const set = useLocalStorage('board.colourSet', COLOUR_SETS[0].id);
const colours = computed(
    () =>
        (COLOUR_SETS.find((each) => each.id === set.value) ?? COLOUR_SETS[0])
            .colours,
);

/** How a tile looks: a shape in that colour and style, with words on it. */
const tile = (style: string, colour: string) => {
    const look = styled({ kind: 'rect' }, style, colour);

    return {
        backgroundColor: look.fill,
        borderColor: look.stroke,
        borderWidth: `${Math.max(1, look.lineWidth ?? 1)}px`,
        color: look.color,
    };
};
</script>

<template>
    <div class="style-picker" data-test="style-picker">
        <Select v-model="set">
            <SelectTrigger
                size="sm"
                class="w-full"
                aria-label="Colour set"
                data-test="style-set"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="each in COLOUR_SETS"
                    :key="each.id"
                    :value="each.id"
                >
                    <span class="style-set">
                        <span
                            v-for="colour in each.colours"
                            :key="colour"
                            class="style-dot"
                            :style="{
                                backgroundColor:
                                    COLOURS[colour].shades[
                                        COLOURS[colour].solid
                                    ],
                            }"
                        />
                        {{ each.label }}
                    </span>
                </SelectItem>
            </SelectContent>
        </Select>

        <!-- A whole board: a style at a time, shown in the set's colours -->
        <div v-if="board" class="style-rows">
            <button
                v-for="style in STYLES"
                :key="style.id"
                type="button"
                class="style-row"
                :data-test="`style-board-${style.id}`"
                @click="emit('pick', { set, style: style.id, colour: '' })"
            >
                <span class="style-row-name">{{ style.label }}</span>
                <span
                    v-for="colour in colours"
                    :key="colour"
                    class="style-tile is-small"
                    :style="tile(style.id, colour)"
                />
            </button>
        </div>

        <div
            v-else
            class="style-grid"
            :style="{ gridTemplateColumns: `repeat(${colours.length}, 1fr)` }"
        >
            <template v-for="style in STYLES" :key="style.id">
                <button
                    v-for="colour in colours"
                    :key="`${style.id}-${colour}`"
                    type="button"
                    class="style-tile"
                    :style="tile(style.id, colour)"
                    :title="`${style.label}, ${COLOURS[colour].label}`"
                    :data-test="`style-${style.id}-${colour}`"
                    @click="emit('pick', { set, style: style.id, colour })"
                >
                    Aa
                </button>
            </template>
        </div>

        <label v-if="!board" class="style-shadow">
            Shadow
            <Switch
                :model-value="props.shadow"
                data-test="style-shadow"
                @update:model-value="emit('shadow', $event)"
            />
        </label>
    </div>
</template>

<style scoped>
.style-picker {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.style-set {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.style-set .style-dot:last-of-type {
    margin-right: 0.375rem;
}
.style-dot {
    width: 0.625rem;
    height: 0.625rem;
    border-radius: 999px;
}

.style-grid {
    display: grid;
    gap: 0.375rem;
}

.style-tile {
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 4 / 3;
    font-size: 0.6875rem;
    font-weight: 600;
    border-style: solid;
    border-radius: 0.375rem;
    cursor: pointer;
    transition: transform 100ms ease;
}
.style-tile:hover {
    transform: scale(1.08);
}

.style-rows {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.style-row {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.375rem;
    border-radius: var(--radius-md);
    cursor: pointer;
}
.style-row:hover {
    background-color: var(--muted);
}
.style-row-name {
    flex: 1;
    font-size: 0.75rem;
    text-align: left;
}
.style-tile.is-small {
    width: 1.25rem;
    aspect-ratio: auto;
    height: 1rem;
}

.style-shadow {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.75rem;
}
</style>
