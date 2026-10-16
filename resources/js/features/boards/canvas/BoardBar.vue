<script setup lang="ts">
import { Gauge, LayoutTemplate, Play } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import BoardCircuit from '../circuit/BoardCircuit.vue';
import type { SlideLayout } from '../deck/deck';
import SlideMenu from '../deck/SlideMenu.vue';
import BoardSchema from '../drawing/BoardSchema.vue';
import type { Item } from '../model/items';
import BoardShortcuts from './BoardShortcuts.vue';

// The slim bar over the board: what it is, where it stands, and what to do
// with it -- the page's own title and actions, and the board's: a circuit's
// values, its schema as SQL, a template, a new slide, presenting.
defineProps<{
    title: string;
    status: string;
    compact: boolean;
    items: Item[];
    canWorkOut: boolean;
    hasCircuit: boolean;
    hasSchema: boolean;
    frameCount: number;
}>();
const showValues = defineModel<boolean>('showValues', { required: true });
const readingSchema = defineModel<boolean>('readingSchema', {
    required: true,
});
const emit = defineEmits<{
    templates: [];
    addSlide: [layout: SlideLayout];
    present: [];
}>();
</script>

<template>
    <div class="board-bar">
        <div class="flex min-w-0 items-center gap-2">
            <slot name="title">
                <h1 class="truncate px-2 text-sm font-semibold">
                    {{ title || 'Board' }}
                </h1>
            </slot>
            <span
                v-if="status && !compact"
                class="text-muted-foreground truncate text-xs"
                data-test="board-status"
                >{{ status }}</span
            >
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-1">
            <slot name="actions" />

            <!-- The voltages and currents of what is drawn, written on
                 it: shown and hidden by whoever is looking, and not kept -->
            <Button
                v-if="canWorkOut"
                variant="ghost"
                size="sm"
                :class="{ 'bg-primary/10 text-primary': showValues }"
                :aria-pressed="showValues"
                :title="
                    showValues
                        ? 'Hide the voltages and currents'
                        : 'Work the circuit out: the voltage on each wire, and the current through each part'
                "
                data-test="board-values"
                @click="showValues = !showValues"
            >
                <Gauge />
                <span v-if="!compact">Values</span>
            </Button>

            <BoardCircuit
                v-if="hasCircuit"
                :items="items"
                :values="canWorkOut"
            />

            <!-- The tables drawn here written out as SQL: there once
                 there is a table -->
            <BoardSchema
                v-if="hasSchema"
                v-model:open="readingSchema"
                :items="items"
                :button="!compact"
            />

            <!-- A diagram to start from, rather than an empty board. On
                 a phone both are under the tool bar's "More": this bar
                 has the title to show -->
            <Button
                v-if="!compact"
                variant="ghost"
                size="sm"
                title="Start from a template: a flowchart, an org chart, a data model…"
                data-test="board-templates"
                @click="emit('templates')"
            >
                <LayoutTemplate />
                Templates
            </Button>

            <!-- Nothing to press on a phone -->
            <BoardShortcuts v-if="!compact" />

            <SlideMenu :compact="compact" @add="emit('addSlide', $event)" />

            <button
                type="button"
                class="board-present"
                :disabled="!frameCount"
                :title="
                    frameCount
                        ? `Present ${frameCount} frame${frameCount === 1 ? '' : 's'} as slides`
                        : 'Draw a frame (F) to present it as a slide'
                "
                data-test="present"
                @click="emit('present')"
            >
                <Play class="size-3.5" />
                <span v-if="!compact">Present</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.board-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
    height: 3rem;
    flex-shrink: 0;
    padding: 0 0.75rem 0 0.5rem;
    border-bottom: 1px solid var(--border);
}

.board-present {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    height: 2rem;
    margin-left: 0.25rem;
    padding: 0 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.board-present:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
