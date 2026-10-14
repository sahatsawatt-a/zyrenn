<template>
    <DiagramBlock v-bind="props" :kind="circuitKind" :redraw="turn">
        <template v-if="offered" #actions>
            <button
                type="button"
                class="diagram-btn"
                :class="{ 'is-on': showing }"
                :aria-pressed="showing"
                :title="
                    showing
                        ? 'Hide the voltages and currents'
                        : 'Work the circuit out: the voltage on each wire, and the current through each part'
                "
                data-test="circuit-values"
                @click="toggle"
            >
                Values
            </button>
        </template>
    </DiagramBlock>
</template>

<script setup lang="ts">
import { nodeViewProps } from '@tiptap/vue-3';
import { computed, ref } from 'vue';
import {
    circuitExamples,
    exampleFor,
} from '@/features/boards/circuit/examples';
import { parseCircuit } from '@/features/boards/circuit/parse';
import { renderCircuit } from '@/features/boards/circuit/render';
import type { DiagramKind } from '@/features/notes/lib/diagramKind';
import { svgToPng } from '@/lib/svgImage';
import DiagramBlock from './DiagramBlock.vue';

// A circuit in a note, drawn from a few lines of text with the symbols and
// wires a board uses (features/boards/circuit). The block itself is
// DiagramBlock, as it is for Mermaid.
const props = defineProps(nodeViewProps);

// ---------------------------------------------------------------- its values
// The circuit worked out -- the voltage on each wire, the current through
// each part -- and written on the drawing. The text says where a reader
// starts: "values" with them showing, "values hidden" with them kept back
// for a guess to be made first. Showing and hiding is each reader's own,
// and is not kept.
const asked = computed(() => {
    const read = parseCircuit(props.node.textContent);

    if (!read.ok) {
        return { shown: false, hidden: false, driven: false };
    }

    const { circuit } = read;

    return {
        shown: circuit.values,
        hidden: circuit.valuesHidden,
        // Something to drive it: without, there is nothing to work out
        driven:
            circuit.parts.some((part) => part.symbol.source) ||
            circuit.nets.some((net) => net.kind === 'power'),
    };
});
// Offered where the text asks for values, or where there is anything to
// work out
const offered = computed(
    () => asked.value.shown || asked.value.hidden || asked.value.driven,
);
const chosen = ref<boolean | null>(null);
const showing = computed(() => chosen.value ?? asked.value.shown);
// Counted up for the drawing to be made again
const turn = ref(0);

const toggle = () => {
    chosen.value = !showing.value;
    turn.value += 1;
};

const circuitKind: DiagramKind = {
    name: 'circuit',
    title: 'Circuit',
    custom: 'Custom circuit',
    empty: 'Empty circuit — pick an example above or click to write one.',
    groups: [{ name: 'Examples', templates: circuitExamples }],
    detect: exampleFor,
    // Drawn in the note's own text colour, so it follows light and dark
    render: async (source) => renderCircuit(source, { values: showing.value }),
    image: async (source, type) => {
        // A file stands on its own: dark ink, white paper
        const drawn = renderCircuit(source, {
            ink: '#0f172a',
            paper: '#ffffff',
            values: showing.value,
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

<style scoped>
.diagram-btn.is-on {
    color: var(--primary);
    border-color: color-mix(in oklab, var(--primary) 45%, var(--border));
    background: color-mix(in oklab, var(--primary) 8%, var(--background));
}
</style>
