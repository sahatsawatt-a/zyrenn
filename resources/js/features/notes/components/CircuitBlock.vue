<template>
    <DiagramBlock v-bind="props" :kind="circuitKind" />
</template>

<script setup lang="ts">
import { nodeViewProps } from '@tiptap/vue-3';
import {
    circuitExamples,
    exampleFor,
} from '@/features/boards/circuit/examples';
import { renderCircuit } from '@/features/boards/circuit/render';
import type { DiagramKind } from '@/features/notes/lib/diagramKind';
import { svgToPng } from '@/lib/svgImage';
import DiagramBlock from './DiagramBlock.vue';

// A circuit in a note, drawn from a few lines of text with the symbols and
// wires a board uses (features/boards/circuit). The block itself is
// DiagramBlock, as it is for Mermaid.
const props = defineProps(nodeViewProps);

const circuitKind: DiagramKind = {
    name: 'circuit',
    title: 'Circuit',
    custom: 'Custom circuit',
    empty: 'Empty circuit — pick an example above or click to write one.',
    groups: [{ name: 'Examples', templates: circuitExamples }],
    detect: exampleFor,
    // Drawn in the note's own text colour, so it follows light and dark
    render: async (source) => renderCircuit(source),
    image: async (source, type) => {
        // A file stands on its own: dark ink, white paper
        const drawn = renderCircuit(source, {
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
