<script setup lang="ts">
import { Play } from '@lucide/vue';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Item } from '../model/items';
import type { BuildStep, Transition } from './build';
import { TRANSITIONS } from './build';
import StepRow from './StepRow.vue';

// A slide's animations as PowerPoint's Animation Pane has them: every step
// on it in the order they play, the click each starts on, earlier or later,
// and how the show comes to the slide. Played through here with Preview.
const props = defineProps<{
    frame: Item;
    // What an item on the slide is called, for its steps
    nameOf: (id: string) => string;
}>();
const emit = defineEmits<{
    update: [patch: Partial<Item>];
    preview: [];
}>();

const steps = computed(() => props.frame.build ?? []);

// The click each step starts, counted down the list
const clicks = computed(() => {
    let click = 0;

    return steps.value.map((step) =>
        step.start === 'click' ? ++click : undefined,
    );
});

const save = (build: BuildStep[]) => emit('update', { build });

const change = (index: number, patch: Partial<BuildStep>) =>
    save(
        steps.value.map((step, at) =>
            at === index ? { ...step, ...patch } : step,
        ),
    );

const remove = (index: number) =>
    save(steps.value.filter((_, at) => at !== index));

const move = (index: number, by: -1 | 1) => {
    const build = [...steps.value];
    const [step] = build.splice(index, 1);

    build.splice(index + by, 0, step);
    save(build);
};
</script>

<template>
    <div class="space-y-2" data-test="slide-animations">
        <div class="inspector-align">
            <p class="inspector-label">Transition</p>
            <Select
                :model-value="frame.transition ?? 'glide'"
                @update:model-value="
                    emit('update', { transition: $event as Transition })
                "
            >
                <SelectTrigger
                    class="w-32"
                    size="sm"
                    data-test="slide-transition"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="one in TRANSITIONS"
                        :key="one.id"
                        :value="one.id"
                        >{{ one.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <div class="inspector-align">
            <p class="inspector-label">Animations</p>
            <button
                v-if="steps.length"
                type="button"
                class="inline-flex items-center gap-1 text-xs"
                title="Play the slide's animations here"
                data-test="animation-preview"
                @click="emit('preview')"
            >
                <Play :size="12" />
                Preview
            </button>
        </div>
        <p v-if="!steps.length" class="inspector-hint">
            Select something on the slide and animate it: it comes in, draws the
            eye or goes, a click at a time while presenting.
        </p>
        <StepRow
            v-for="(step, index) in steps"
            :key="`${index}-${step.item}-${step.kind}`"
            :step="step"
            :name="nameOf(step.item)"
            :click="clicks[index]"
            movable
            :can-up="index > 0"
            :can-down="index < steps.length - 1"
            @change="change(index, $event)"
            @remove="remove(index)"
            @up="move(index, -1)"
            @down="move(index, 1)"
        />
    </div>
</template>
