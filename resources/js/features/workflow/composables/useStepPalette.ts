import { useVueFlow } from '@vue-flow/core';
import { computed, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { clear } from '../canvas';
import { outputsOf } from '../steps';
import type { NodeType } from '../types';
import { keep, kept } from './useEditorSpace';
import type { useWorkflowCanvas } from './useWorkflowCanvas';

// The palette of steps: beside the canvas, or over it on a narrow screen --
// and where a step chosen from it goes. A step's "+" opens it to add the
// next step already joined to that way out.

const PALETTE_SHOWN = 'workflows.palette';

export function useStepPalette({
    graph,
    types,
    editable,
    compact,
    tight,
    settingUp,
    selectedId,
    canvas,
}: {
    graph: ReturnType<typeof useWorkflowCanvas>;
    types: Record<string, NodeType>;
    editable: boolean;
    compact: Ref<boolean>;
    tight: Ref<boolean>;
    // A step's settings are on show
    settingUp: () => boolean;
    selectedId: Ref<string | null>;
    // What holds the canvas, to find what is in view
    canvas: Ref<HTMLElement | null>;
}) {
    const { screenToFlowCoordinate, findNode } = useVueFlow();

    // Over the canvas it is opened for one step, and shut again
    const paletteOpen = ref(!compact.value && kept(PALETTE_SHOWN));
    watch(compact, (now) => (paletteOpen.value = !now && kept(PALETTE_SHOWN)));

    // A step's "+": the step chosen next is joined to that way out of it
    const nextFrom = ref<{ id: string; way: string } | null>(null);

    // Where room is short it makes way for a step's settings, and comes back
    // after -- unless the step's "+" asked for it
    const paletteShown = computed(
        () =>
            editable &&
            (!!nextFrom.value ||
                (paletteOpen.value && !(tight.value && settingUp()))),
    );

    function showPalette(shown: boolean) {
        paletteOpen.value = shown;

        if (!shown) {
            nextFrom.value = null;
        } else if (tight.value) {
            // Asked for by name: the settings make way instead
            selectedId.value = null;
        }

        if (!compact.value) {
            keep(PALETTE_SHOWN, shown);
        }
    }

    function addAfter(id: string, way: string) {
        nextFrom.value = { id, way };
        paletteOpen.value = true;
    }

    const nextAfter = computed(() => {
        const from = nextFrom.value && findNode(nextFrom.value.id);

        return from
            ? {
                  label: from.data.step.label as string,
                  way: nextFrom.value!.way === 'out' ? '' : nextFrom.value!.way,
              }
            : null;
    });

    // Placed, and opened to be set up
    function put(type: NodeType, position: { x: number; y: number }) {
        const step = graph.place(type, position);

        selectedId.value = step.id;

        return step;
    }

    // Clicked in the palette: to the right of the step chosen (or the one added
    // last), where the next step usually goes -- or, when that is off screen,
    // in the middle of what is. Never on top of a step already there.
    function addInView(type: NodeType) {
        const box = canvas.value?.getBoundingClientRect();
        const from = nextFrom.value && findNode(nextFrom.value.id);
        const nodes = graph.nodes.value;

        if (!box) {
            return;
        }

        if (from && nextFrom.value && !type.trigger) {
            const { id, way } = nextFrom.value;
            const ways = outputsOf(from.data.step, types[from.data.step.type]);
            // Each way out to a row of its own, level with where it leaves
            const spot = clear(nodes, {
                x: from.position.x + 320,
                y:
                    from.position.y +
                    (ways.indexOf(way) - (ways.length - 1) / 2) * 110,
            });
            const step = put(type, spot);

            nextFrom.value = null;
            graph.joinLater(id, way, step.id);
            added();

            return;
        }

        const topLeft = screenToFlowCoordinate({ x: box.left, y: box.top });
        const bottomRight = screenToFlowCoordinate({
            x: box.right,
            y: box.bottom,
        });
        const middle = screenToFlowCoordinate({
            x: box.left + box.width / 2,
            y: box.top + box.height / 2,
        });
        const anchor = graph.nodeOf(selectedId.value) ?? nodes.at(-1);
        const beside = anchor && {
            x: anchor.position.x + 320,
            y: anchor.position.y,
        };
        const inView = (at: { x: number; y: number }) =>
            at.x >= topLeft.x &&
            at.y >= topLeft.y &&
            at.x + 240 <= bottomRight.x &&
            at.y + 60 <= bottomRight.y;

        const spot =
            beside && inView(beside)
                ? beside
                : { x: middle.x - 120, y: middle.y - 28 };

        put(type, clear(nodes, spot));
        added();
    }

    // Over the canvas, the palette has done what it was opened for
    function added() {
        if (compact.value) {
            showPalette(false);
        }
    }

    // Dragged from the palette onto the canvas
    function drop(event: DragEvent) {
        const type =
            types[event.dataTransfer?.getData('application/zyrenn-step') ?? ''];

        if (!type || !editable) {
            return;
        }

        const at = screenToFlowCoordinate({
            x: event.clientX,
            y: event.clientY,
        });
        // Dropped by its middle, not its corner
        put(type, { x: at.x - 120, y: at.y - 28 });
        nextFrom.value = null;
    }

    return {
        nextFrom,
        paletteShown,
        showPalette,
        addAfter,
        nextAfter,
        addInView,
        drop,
    };
}
