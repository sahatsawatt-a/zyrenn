<template>
    <DiagramBlock
        ref="block"
        v-bind="props"
        :kind="plotKind"
        :redraw="turn"
        @pointermove="onMove"
        @pointerleave="onLeave"
    >
        <template v-if="sliders.length" #controls>
            <div
                class="plot-controls"
                contenteditable="false"
                data-test="plot-controls"
            >
                <label
                    v-for="each in sliders"
                    :key="each.name"
                    class="plot-slider"
                >
                    <span class="plot-slider-name">{{ each.name }}</span>
                    <input
                        type="range"
                        :min="each.min"
                        :max="each.max"
                        :step="each.step"
                        :value="values[each.name] ?? each.value"
                        :aria-label="each.name"
                        :data-test="`plot-slider-${each.name}`"
                        @input="slide(each.name, $event)"
                    />
                    <output class="plot-slider-value">{{
                        short(values[each.name] ?? each.value)
                    }}</output>
                </label>
                <button
                    v-if="moved"
                    type="button"
                    class="plot-reset"
                    title="Back to the values as written"
                    data-test="plot-reset"
                    @click="reset"
                >
                    Reset
                </button>
            </div>
        </template>
    </DiagramBlock>
</template>

<script setup lang="ts">
import { nodeViewProps } from '@tiptap/vue-3';
import { computed, ref, watch } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import type { DiagramKind } from '@/features/notes/lib/diagramKind';
import type { Scope } from '@/features/notes/plot/expression';
import { plotExampleFor, plotExamples } from '@/features/notes/plot/examples';
import {
    COLOURS,
    drawPlot,
    heightsAt,
    parsePlot,
    short,
    written,
} from '@/features/notes/plot/plot';
import type { Drawn, Plot, Points } from '@/features/notes/plot/plot';
import { forgetPoints, pointsOf } from '@/features/notes/plot/tableData';
import { svgToPng } from '@/lib/svgImage';
import DiagramBlock from './DiagramBlock.vue';

// A graph in a note, drawn from a few lines of text: curves from formulas,
// with the values a reader may slide, or the columns of a table. The block
// itself is DiagramBlock, as it is for a circuit.
const props = defineProps(nodeViewProps);

const source = computed(() => props.node.textContent.trim());
const parsed = computed(() => parsePlot(source.value));
const plot = computed<Plot | null>(() =>
    parsed.value.ok ? parsed.value.plot : null,
);
const sliders = computed(() => plot.value?.parameters ?? []);

// Where each slider stands: the reader's own, and not kept. The text says
// where they start.
const values = ref<Scope>({});
// The height the graph has shown, so that sliding moves the curve and not
// the numbers up the side
let hold: [number, number] | null = null;
// Counted up for the drawing to be made again
const turn = ref(0);
const moved = computed(() => Object.keys(values.value).length > 0);

watch(source, () => {
    values.value = {};
    hold = null;

    if (plot.value) {
        forgetPoints(plot.value);
    }
});

const slide = (name: string, event: Event) => {
    values.value = {
        ...values.value,
        [name]: Number((event.target as HTMLInputElement).value),
    };
    turn.value += 1;
};

const reset = () => {
    values.value = {};
    hold = null;
    turn.value += 1;
};

// What was last drawn, for the pointer to be read against
let shown: { plot: Plot; drawn: Drawn; points: Points[] } | null = null;

const draw = async (
    text: string,
    file = false,
): Promise<
    { ok: true; svg: string; drawn: Drawn } | { ok: false; error: string }
> => {
    const read = parsePlot(text);

    if (!read.ok) {
        return read;
    }

    try {
        const points = await pointsOf(read.plot);
        const drawn = drawPlot(read.plot, {
            values: values.value,
            points,
            hold: file ? null : hold,
            // A file stands on its own: dark ink, white paper
            ...(file
                ? { ink: '#0f172a', paper: '#ffffff', colours: COLOURS }
                : {}),
        });

        if (!file) {
            hold = drawn.geometry.y;
            shown = { plot: read.plot, drawn, points };
        }

        return { ok: true, svg: drawn.svg, drawn };
    } catch (error) {
        return {
            ok: false,
            error: error instanceof Error ? error.message : String(error),
        };
    }
};

const plotKind: DiagramKind = {
    name: 'plot',
    title: 'Graph',
    custom: 'Custom graph',
    empty: 'Empty graph — pick an example above or click to write one.',
    groups: [{ name: 'Examples', templates: plotExamples }],
    detect: plotExampleFor,
    render: (text) => draw(text),
    image: async (text, type) => {
        const made = await draw(text, true);

        if (!made.ok) {
            throw new Error(made.error);
        }

        return type === 'svg'
            ? new Blob([made.svg], { type: 'image/svg+xml' })
            : svgToPng(made.svg, made.drawn.width, made.drawn.height);
    },
    themed: false,
};

// ------------------------------------------------------------------ pointing
// Under the pointer: a line down the graph, a dot on each curve, and what
// each comes to there. The drawing carries these parts hidden; they are
// placed here.
const block = ref<ComponentPublicInstance | null>(null);

const drawing = (): SVGSVGElement | null =>
    (block.value?.$el as HTMLElement | undefined)?.querySelector?.(
        'svg.plot-drawing',
    ) ?? null;

const onLeave = () =>
    drawing()
        ?.querySelector('.plot-hover')
        ?.setAttribute('visibility', 'hidden');

const onMove = (event: PointerEvent) => {
    const svg = drawing();
    const hover = svg?.querySelector('.plot-hover');

    if (!svg || !hover || !shown) {
        return;
    }

    const { plot: read, drawn, points } = shown;
    const { left, right, top, bottom, x: across, y: up } = drawn.geometry;
    const box = svg.getBoundingClientRect();
    const at = ((event.clientX - box.left) / box.width) * drawn.width;

    if (at < left || at > right) {
        hover.setAttribute('visibility', 'hidden');

        return;
    }

    let x =
        across[0] + ((at - left) / (right - left)) * (across[1] - across[0]);

    // Over a table's points, it is the nearest row that is read
    const rows = points.filter((each) => each.points.length > 0);

    if (rows.length > 0) {
        x = rows[0].points.reduce(
            (best, [each]) =>
                Math.abs(each - x) < Math.abs(best - x) ? each : best,
            rows[0].points[0][0],
        );
    }

    const heights = [
        ...rows.map((each) => each.points.find(([px]) => px === x)?.[1] ?? NaN),
        ...heightsAt(read, { ...written(read), ...values.value }, x),
    ].slice(0, drawn.names.length);

    const px =
        left + ((x - across[0]) / (across[1] - across[0])) * (right - left);
    const line = hover.querySelector('.plot-hover-line');

    line?.setAttribute('x1', String(px));
    line?.setAttribute('x2', String(px));

    hover.querySelectorAll('.plot-hover-dot').forEach((dot, index) => {
        const height = heights[index];
        const py =
            bottom - ((height - up[0]) / (up[1] - up[0])) * (bottom - top);
        const inside =
            Number.isFinite(height) && py >= top - 2 && py <= bottom + 2;

        dot.setAttribute('visibility', inside ? 'inherit' : 'hidden');
        dot.setAttribute('cx', String(px));
        dot.setAttribute('cy', String(inside ? py : top));
    });

    const text = hover.querySelector('.plot-hover-text');

    if (text) {
        text.textContent = [
            `${read.variable} = ${short(x)}`,
            ...drawn.names.map(
                (name, index) => `${name} = ${short(heights[index])}`,
            ),
        ].join('    ');
    }

    hover.setAttribute('visibility', 'visible');
};
</script>

<style>
/* The colours of what a graph draws, first to fourth: one set for a light
   page and one for a dark, wherever the drawing is shown */
:root {
    --plot-1: #2a78d6;
    --plot-2: #eb6834;
    --plot-3: #1baf7a;
    --plot-4: #eda100;
}
.dark {
    --plot-1: #3987e5;
    --plot-2: #d95926;
    --plot-3: #199e70;
    --plot-4: #c98500;
}
</style>

<style scoped>
.plot-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 1.25rem;
    padding: 0.625rem 1rem 0.75rem;
    border-top: 1px solid var(--border);
    user-select: none;
}

.plot-slider {
    display: flex;
    flex: 1 1 12rem;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    font-size: 0.8125rem;
}
.plot-slider input {
    flex: 1;
    min-width: 0;
    accent-color: var(--primary);
    cursor: pointer;
}
.plot-slider-name {
    font-weight: 600;
}
.plot-slider-value {
    min-width: 2.75rem;
    font-variant-numeric: tabular-nums;
    text-align: right;
    color: var(--muted-foreground);
}

.plot-reset {
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.plot-reset:hover {
    color: var(--foreground);
    background-color: var(--muted);
}

@media print {
    .plot-controls {
        display: none;
    }
}
</style>
