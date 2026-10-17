<script setup lang="ts">
import { computed } from 'vue';
import NumberField from '@/components/common/NumberField.vue';
import { Switch } from '@/components/ui/switch';
import type { Action } from '@/features/prototypes/interactions';
import type { Item } from '../../model/items';
import { nameOf } from '../../model/items';
import { groupKeys } from '../../model/layers';
import { screenOf, valueNames } from '../../model/links';
import ActionPicker from './ActionPicker.vue';
import type { ProjectTable } from './tables';

// What a prototype's screen does by itself in Play: after so many seconds,
// something -- a splash screen moving on. And a word on scrolling, which a
// screen does by being drawn taller than its device.
const props = withDefaults(
    defineProps<{
        screen: Item;
        items: Item[];
        tables?: ProjectTable[];
    }>(),
    { tables: () => [] },
);

const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const after = computed(() => screenOf(props.screen).after ?? null);

const screens = computed(() =>
    props.items
        .filter((item) => item.kind === 'frame')
        .map((frame, at) => ({
            id: frame.id,
            title: frame.text.trim().split('\n')[0] || `Screen ${at + 1}`,
        })),
);

// What is on this screen, to show or hide
const things = computed(() => {
    const keys = groupKeys(props.items);

    return props.items
        .filter(
            (item) =>
                item.kind !== 'frame' &&
                item.kind !== 'arrow' &&
                keys.get(item.id) === props.screen.id,
        )
        .map((item) => ({ id: item.id, name: nameOf(item) }));
});

const names = computed(() => valueNames(props.items));

const setAfter = (next: { seconds: number; do: Action } | null) =>
    emit('update', {
        screen: next ? { ...props.screen.screen, after: next } : null,
    });

// Moving on starts by going to the next screen, or back from the last
const begin = () => {
    const at = screens.value.findIndex((each) => each.id === props.screen.id);
    const next = screens.value[at + 1];

    setAfter({ seconds: 2, do: next ? { to: next.id } : { back: true } });
};
</script>

<template>
    <div class="inspector-align" data-test="screen-play-config">
        <p class="inspector-label">In Play</p>
        <label class="inspector-switch">
            Moves on by itself
            <Switch
                :model-value="!!after"
                data-test="screen-after"
                @update:model-value="$event ? begin() : setAfter(null)"
            />
        </label>
        <template v-if="after">
            <label class="screen-after">
                After
                <NumberField
                    :model-value="after.seconds"
                    label="Seconds"
                    :min="0.5"
                    :max="30"
                    :step="0.5"
                    unit="s"
                    compact
                    data-test="screen-seconds"
                    @change="
                        $event !== null &&
                        setAfter({ seconds: $event, do: after!.do })
                    "
                />
            </label>
            <ActionPicker
                :model-value="after.do"
                :screens="screens"
                :things="things"
                :names="names"
                :tables="tables"
                test="after"
                @update:model-value="
                    setAfter(
                        $event ? { seconds: after!.seconds, do: $event } : null,
                    )
                "
            />
        </template>
        <p class="inspector-hint">
            Drawn taller than its device, a screen scrolls in Play.
        </p>
    </div>
</template>

<style scoped>
.screen-after {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
}
</style>
