<template>
    <DiagramBlock v-bind="props" :kind="timingKind" />
</template>

<script setup lang="ts">
import { nodeViewProps } from '@tiptap/vue-3';
import type { DiagramKind } from '@/features/notes/lib/diagramKind';
import {
    timingExampleFor,
    timingExamples,
} from '@/features/notes/timing/examples';
import { renderTiming } from '@/features/notes/timing/timing';
import { svgToPng } from '@/lib/svgImage';
import DiagramBlock from './DiagramBlock.vue';

// A timing diagram in a note: signals over time -- a clock, a bus, a line
// pulled low -- drawn from a few lines of text (features/notes/timing). The
// block itself is DiagramBlock, as it is for Mermaid and for a circuit.
const props = defineProps(nodeViewProps);

const timingKind: DiagramKind = {
    name: 'timing',
    title: 'Timing diagram',
    custom: 'Custom timing diagram',
    empty: 'Empty timing diagram — pick an example above or click to write one.',
    groups: [{ name: 'Examples', templates: timingExamples }],
    detect: timingExampleFor,
    // Drawn in the note's own text colour, so it follows light and dark
    render: async (source) => renderTiming(source),
    image: async (source, type) => {
        // A file stands on its own: dark ink, white paper
        const drawn = renderTiming(source, {
            ink: '#0f172a',
            paper: '#ffffff',
        });

        if (!drawn.ok) {
            throw new Error(drawn.error);
        }

        return type === 'svg'
            ? new Blob([drawn.svg], { type: 'image/svg+xml' })
            : svgToPng(drawn.svg, drawn.width, drawn.height);
    },
    themed: false,
};
</script>
