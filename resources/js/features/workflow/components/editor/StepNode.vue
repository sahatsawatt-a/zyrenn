<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
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

// One step on the canvas: its kind's icon and colour, its name, and a dot
// for each way in and out. A step with something to fix says how many.

const props = defineProps<{
    id: string;
    selected?: boolean;
    data: { step: StepNode; type?: NodeType; problems: string[] };
    // Where it stands in the run being looked at
    status?: StepStatus;
}>();

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
</script>

<template>
    <div
        class="bg-card relative flex w-60 items-stretch rounded-xl border shadow-sm transition-[box-shadow,border-color]"
        :style="{ minHeight: outputs.length > 1 ? '76px' : undefined }"
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

        <span
            v-if="status && status !== 'pending'"
            class="bg-card absolute -top-2 -right-2 z-10 flex size-5 items-center justify-center rounded-full border shadow-sm"
            :title="statusLook(status).label"
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
                <p class="text-muted-foreground truncate font-mono text-[10px]">
                    {{ data.step.key }}
                </p>
            </div>
            <span
                v-if="data.problems.length"
                class="text-destructive flex shrink-0 items-center gap-0.5 text-[11px] font-medium"
                :title="data.problems.join('\n')"
                data-step-problems
            >
                <CircleAlert class="size-3.5" />
                {{ data.problems.length }}
            </span>
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
            <span
                v-if="labelOf(output)"
                class="pointer-events-none absolute left-full ml-2 -translate-y-[135%] text-[10px] font-medium"
                :class="
                    isNo(output)
                        ? 'text-rose-600 dark:text-rose-400'
                        : 'text-emerald-600 dark:text-emerald-400'
                "
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
</style>
