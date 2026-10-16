import { LogIn, Zap } from '@lucide/vue';
import { computed } from 'vue';
import type { Ref } from 'vue';
import { before } from '../canvas';
import { iconOf, toneOf } from '../steps';
import type { NodeType } from '../types';
import type { useTestData } from './useTestData';
import type { useWorkflowCanvas } from './useWorkflowCanvas';

// The test data a step being set up can read, each part on a card with the
// name, icon and colour of the step it came from

export function useStepSamples({
    testData,
    graph,
    types,
    selectedId,
}: {
    testData: ReturnType<typeof useTestData>;
    graph: ReturnType<typeof useWorkflowCanvas>;
    types: Record<string, NodeType>;
    selectedId: Ref<string | null>;
}) {
    const { nodes, edges, nodeOf } = graph;

    // What is before the step, so its settings can say what it may read
    const beforeSteps = computed(() => {
        const found = before(edges.value, selectedId.value);

        return nodes.value
            .filter((node) => found.has(node.id))
            .map((node) => ({ id: node.id, key: node.data!.step.key }));
    });

    // A step's name, icon and colour, for the card of what it gave
    function lookOf(id?: string) {
        const node = nodeOf(id);
        const type = node && types[node.data!.step.type];

        return node
            ? {
                  title: node.data!.step.label,
                  icon: iconOf(type),
                  tile: toneOf(type).tile,
              }
            : null;
    }

    return computed(() => {
        const selected = nodeOf(selectedId.value);

        return (
            selected
                ? testData.samples(
                      selected.id,
                      !!types[selected.data!.step.type]?.trigger,
                      beforeSteps.value,
                      edges.value
                          .filter((edge) => edge.target === selected.id)
                          .map((edge) => edge.source),
                  )
                : []
        ).map((sample) => ({
            ...sample,
            ...(sample.name === 'input'
                ? { title: 'What came in', icon: LogIn }
                : sample.name === 'trigger'
                  ? // The step that began the run; before any run, the one that would
                    (lookOf(
                        sample.id ??
                            nodes.value.find(
                                (one) => types[one.data!.step.type]?.trigger,
                            )?.id,
                    ) ?? { title: 'Trigger', icon: Zap })
                  : lookOf(sample.id)),
        }));
    });
}
