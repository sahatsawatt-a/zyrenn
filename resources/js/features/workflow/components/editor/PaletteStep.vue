<script setup lang="ts">
import { Zap } from '@lucide/vue';
import { iconOf, toneOf } from '@/features/workflow/steps';
import type { NodeType } from '@/features/workflow/types';

// One kind of step in the palette: dragged onto the canvas, or clicked to
// add it. Its name alone; what it does is said when it is pointed at -- or,
// on a phone with nothing to point with, in a line under it.

defineProps<{ type: NodeType; compact?: boolean }>();

const emit = defineEmits<{ add: [type: NodeType]; drag: [type: NodeType] }>();

function drag(event: DragEvent, type: NodeType) {
    event.dataTransfer?.setData('application/zyrenn-step', type.type);

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }

    emit('drag', type);
}
</script>

<template>
    <button
        type="button"
        draggable="true"
        class="hover:bg-muted/60 focus-visible:bg-muted/60 flex w-full cursor-grab items-center gap-2 rounded-md px-1.5 text-left transition-colors outline-none active:cursor-grabbing"
        :class="compact ? 'py-2' : 'py-1'"
        :title="type.description"
        :data-palette-step="type.type"
        @dragstart="drag($event, type)"
        @click="emit('add', type)"
    >
        <span
            class="flex size-6 shrink-0 items-center justify-center rounded-md"
            :class="toneOf(type).tile"
        >
            <component :is="iconOf(type)" class="size-3.5" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm leading-tight">{{ type.label }}</span>
            <span
                v-if="compact"
                class="text-muted-foreground line-clamp-1 text-xs"
                >{{ type.description }}</span
            >
        </span>
        <Zap
            v-if="type.trigger"
            class="size-3 shrink-0 text-emerald-600 dark:text-emerald-400"
            aria-label="Starts a run"
        />
    </button>
</template>
