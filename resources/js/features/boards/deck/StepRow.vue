<script setup lang="ts">
import { ChevronDown, ChevronUp, X } from '@lucide/vue';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BuildFrom, BuildStart, BuildStep } from './build';
import { EFFECTS, FROMS, hasFrom, KINDS, SPEEDS, STARTS } from './build';

// One animation of a slide's: what it does, how it starts, how fast, and
// where from -- as a row of the slide's list or of an item's own.
const props = defineProps<{
    step: BuildStep;
    // What it is of, in the slide's list; left out in the item's own
    name?: string;
    // The click it plays on, when it starts one
    click?: number;
    // In the slide's list, where it can be moved earlier or later
    movable?: boolean;
    canUp?: boolean;
    canDown?: boolean;
}>();
const emit = defineEmits<{
    change: [patch: Partial<BuildStep>];
    remove: [];
    up: [];
    down: [];
}>();

const kind = computed(
    () => KINDS.find((one) => one.id === props.step.kind)?.label ?? '',
);

const pickEffect = (effect: string) =>
    emit('change', {
        effect,
        from: hasFrom(props.step.kind, effect)
            ? (props.step.from ?? 'bottom')
            : undefined,
    });

const speed = computed(
    () =>
        SPEEDS.reduce((near, one) =>
            Math.abs(one.ms - props.step.duration) <
            Math.abs(near.ms - props.step.duration)
                ? one
                : near,
        ).ms,
);
</script>

<template>
    <div class="step-row" data-test="animation-step">
        <div class="step-head">
            <span v-if="click" class="step-click" :title="`Click ${click}`">{{
                click
            }}</span>
            <span class="step-name">
                <span class="step-kind" :class="`is-${step.kind}`">{{
                    kind
                }}</span>
                {{ name }}
            </span>
            <button
                v-if="movable"
                type="button"
                title="Earlier"
                :disabled="!canUp"
                data-test="animation-up"
                @click="emit('up')"
            >
                <ChevronUp :size="13" />
            </button>
            <button
                v-if="movable"
                type="button"
                title="Later"
                :disabled="!canDown"
                data-test="animation-down"
                @click="emit('down')"
            >
                <ChevronDown :size="13" />
            </button>
            <button
                type="button"
                title="Take this animation off"
                data-test="animation-remove"
                @click="emit('remove')"
            >
                <X :size="13" />
            </button>
        </div>

        <div
            class="step-grid"
            :class="{ 'is-one': !hasFrom(step.kind, step.effect) }"
        >
            <Select
                :model-value="step.effect"
                @update:model-value="pickEffect(String($event))"
            >
                <SelectTrigger
                    class="w-full"
                    size="sm"
                    data-test="animation-effect"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="effect in EFFECTS[step.kind]"
                        :key="effect.id"
                        :value="effect.id"
                        >{{ effect.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select
                v-if="hasFrom(step.kind, step.effect)"
                :model-value="step.from ?? 'bottom'"
                @update:model-value="
                    emit('change', { from: $event as BuildFrom })
                "
            >
                <SelectTrigger
                    class="w-full capitalize"
                    size="sm"
                    :title="
                        step.kind === 'out'
                            ? 'The side it goes to'
                            : 'The side it comes from'
                    "
                    data-test="animation-from"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="side in FROMS"
                        :key="side"
                        :value="side"
                        class="capitalize"
                        >{{ side }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <Select
            :model-value="step.start"
            @update:model-value="
                emit('change', { start: $event as BuildStart })
            "
        >
            <SelectTrigger class="w-full" size="sm" data-test="animation-start">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="start in STARTS"
                    :key="start.id"
                    :value="start.id"
                    >{{ start.label }}</SelectItem
                >
            </SelectContent>
        </Select>

        <div class="inspector-segments step-speeds">
            <button
                v-for="one in SPEEDS"
                :key="one.ms"
                type="button"
                :class="{ 'is-on': speed === one.ms }"
                :data-test="`animation-speed-${one.label.toLowerCase()}`"
                @click="emit('change', { duration: one.ms })"
            >
                {{ one.label }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.step-row {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding: 0.5rem;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
}
.step-head {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
}
.step-head button {
    display: inline-flex;
    padding: 0.125rem;
    border-radius: 0.25rem;
    color: var(--muted-foreground);
}
.step-head button:hover:not(:disabled) {
    background: var(--muted);
    color: var(--foreground);
}
.step-head button:disabled {
    opacity: 0.35;
}
.step-click {
    display: inline-flex;
    min-width: 1.125rem;
    justify-content: center;
    border-radius: 9999px;
    background: var(--muted);
    font-size: 0.6875rem;
    font-weight: 600;
}
.step-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.step-kind {
    margin-right: 0.25rem;
    font-size: 0.6875rem;
    font-weight: 600;
}
.step-kind.is-in {
    color: oklch(0.6 0.15 150);
}
.step-kind.is-emphasis {
    color: oklch(0.7 0.15 80);
}
.step-kind.is-out {
    color: oklch(0.6 0.18 25);
}
.step-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.375rem;
}
.step-grid.is-one {
    grid-template-columns: minmax(0, 1fr);
}
.step-speeds {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
</style>
