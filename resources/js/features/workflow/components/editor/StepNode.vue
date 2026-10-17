<script setup lang="ts">
import { CircleAlert, Plus } from '@lucide/vue';
import { Handle, Position } from '@vue-flow/core';
import { computed } from 'vue';
import {
    handleLabel,
    iconOf,
    isNo,
    outputsOf,
    statusLook,
    toneOf,
    TRIGGER_STRIP,
} from '@/features/workflow/steps';
import type { NodeType, StepNode, StepStatus } from '@/features/workflow/types';
import Tip from '@/components/common/Tip.vue';

// One step on the canvas: its kind's icon and colour, its name, and a dot
// for each way in and out. A step with something to fix says how many. A
// way out that leads nowhere yet has a "+" for the step after it.

const props = defineProps<{
    id: string;
    selected?: boolean;
    data: {
        step: StepNode;
        type?: NodeType;
        problems: string[];
        // Its ways out that lead somewhere already
        joined: string[];
        editable: boolean;
    };
    // Where it stands in the run being looked at
    status?: StepStatus;
}>();

const emit = defineEmits<{ next: [way: string] }>();

const RINGS: Partial<Record<StepStatus, string>> = {
    running: 'border-sky-500 ring-2 ring-sky-500/30',
    succeeded: 'border-emerald-500/70',
    failed: 'border-destructive ring-2 ring-destructive/25',
    skipped: 'border-dashed opacity-60',
    cancelled: 'opacity-60',
};

const ring = computed(() => (props.status && RINGS[props.status]) || '');

const type = computed(() => props.data.type);
const tone = computed(() => toneOf(type.value));
const icon = computed(() => iconOf(type.value));
const outputs = computed(() => outputsOf(props.data.step, type.value));

// A Switch's ways are called what it names them
const labelOf = (handle: string) =>
    props.data.step.type === 'logic.switch' ? handle : handleLabel(handle);

// Several ways out are spread down the right side
const top = (index: number) =>
    `${((index + 1) / (outputs.value.length + 1)) * 100}%`;

const wayTone = (handle: string) =>
    isNo(handle)
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-emerald-600 dark:text-emerald-400';

// Not while a run is looked at: the canvas then shows how it went
const canAdd = (handle: string) =>
    props.data.editable && !props.status && !props.data.joined.includes(handle);
</script>

<template>
    <div
        class="bg-card relative flex w-60 items-stretch rounded-xl border shadow-sm transition-[box-shadow,border-color]"
        :style="{
            minHeight:
                outputs.length > 1
                    ? `${outputs.length * 30 + 16}px`
                    : undefined,
        }"
        :class="[
            selected
                ? 'border-primary ring-primary/30 ring-2'
                : ring ||
                  (data.problems.length
                      ? 'border-destructive/50'
                      : 'hover:border-foreground/20 hover:shadow-md'),
        ]"
        :data-step-status="status"
        :data-step="data.step.key"
    >
        <div
            class="w-1 shrink-0 rounded-l-xl"
            :class="type?.trigger ? TRIGGER_STRIP : tone.strip"
        />

        <Tip
            v-if="status && status !== 'pending'"
            :text="statusLook(status).label"
        >
            <span
                class="bg-card absolute -top-2 -right-2 z-10 flex size-5 items-center justify-center rounded-full border shadow-sm"
                :aria-label="statusLook(status).label"
            >
                <component
                    :is="statusLook(status).icon"
                    class="size-3.5"
                    :class="[
                        statusLook(status).tone,
                        { 'animate-spin': statusLook(status).spin },
                    ]"
                />
            </span>
        </Tip>

        <Handle
            v-for="input in type?.inputs ?? []"
            :id="input"
            :key="input"
            type="target"
            :position="Position.Left"
            class="step-handle"
        />

        <div class="flex min-w-0 flex-1 items-center gap-2.5 px-3 py-2.5">
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                :class="tone.tile"
            >
                <component :is="icon" class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p
                    class="truncate text-sm leading-tight font-medium"
                    :title="data.step.label"
                >
                    {{ data.step.label }}
                </p>
                <!-- Its kind, once it has a name of its own -->
                <p
                    v-if="type && data.step.label !== type.label"
                    class="text-muted-foreground truncate text-[11px] leading-tight"
                >
                    {{ type.label }}
                </p>
            </div>
            <Tip v-if="data.problems.length" :text="data.problems.join('\n')">
                <span
                    class="text-destructive flex shrink-0 items-center gap-0.5 text-[11px] font-medium"
                    data-step-problems
                >
                    <CircleAlert class="size-3.5" />
                    {{ data.problems.length }}
                </span>
            </Tip>
        </div>

        <template v-for="(output, index) in outputs" :key="output">
            <Handle
                :id="output"
                type="source"
                :position="Position.Right"
                class="step-handle"
                :class="{
                    'step-handle-true': !!labelOf(output) && !isNo(output),
                    'step-handle-false': isNo(output),
                }"
                :style="{ top: top(index) }"
            />
            <!-- Leading nowhere yet: the step after it, from here -->
            <span
                v-if="canAdd(output)"
                class="absolute left-full ml-3 flex -translate-y-1/2 items-center gap-1.5"
                :style="{ top: top(index) }"
            >
                <Tip
                    :text="`Add a step after ${data.step.label}${labelOf(output) ? `, ${labelOf(output)}` : ''}`"
                >
                    <button
                        type="button"
                        class="step-next nodrag nopan bg-card text-muted-foreground hover:border-primary hover:text-primary flex size-5 items-center justify-center rounded-full border shadow-sm"
                        :aria-label="`Add a step after ${data.step.label}${labelOf(output) ? `, ${labelOf(output)}` : ''}`"
                        :data-step-next="output"
                        @click.stop="emit('next', output)"
                    >
                        <Plus class="size-3" />
                    </button>
                </Tip>
                <span
                    v-if="labelOf(output)"
                    class="pointer-events-none text-[10px] font-medium whitespace-nowrap"
                    :class="wayTone(output)"
                    >{{ labelOf(output) }}</span
                >
            </span>
            <!-- Over the line that leaves it -->
            <span
                v-else-if="labelOf(output)"
                class="pointer-events-none absolute left-full ml-2 -translate-y-[135%] text-[10px] font-medium whitespace-nowrap"
                :class="wayTone(output)"
                :style="{ top: top(index) }"
                >{{ labelOf(output) }}</span
            >
        </template>
    </div>
</template>

<style>
.vue-flow__handle.step-handle {
    width: 12px;
    height: 12px;
    border: 2px solid var(--card);
    background: var(--muted-foreground);
    transition: transform 0.15s;
}

.vue-flow__handle.step-handle:hover {
    transform: translateY(-50%) scale(1.3);
}

.vue-flow__handle-left.step-handle {
    left: -6px;
}

.vue-flow__handle-right.step-handle {
    right: -6px;
}

.vue-flow__handle.step-handle-true {
    background: var(--color-emerald-500);
}

.vue-flow__handle.step-handle-false {
    background: var(--color-rose-500);
}

/* A finger needs more to land on than a pointer does */
@media (pointer: coarse) {
    .vue-flow__handle.step-handle {
        width: 16px;
        height: 16px;
    }

    .vue-flow__handle.step-handle::after {
        position: absolute;
        inset: -12px;
        content: '';
    }

    .vue-flow__handle-left.step-handle {
        left: -8px;
    }

    .vue-flow__handle-right.step-handle {
        right: -8px;
    }

    .step-next {
        position: relative;
        width: 1.75rem;
        height: 1.75rem;
    }

    .step-next::after {
        position: absolute;
        inset: -10px;
        content: '';
    }
}
</style>
