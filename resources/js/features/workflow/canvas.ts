import { MarkerType } from '@vue-flow/core';
import type { Edge, Node } from '@vue-flow/core';
import type { StepEdge, StepNode as Step } from './types';

// A workflow's steps and connections as the canvas draws them

export type StepData = { step: Step };

export const toNode = (step: Step): Node<StepData> => ({
    id: step.id,
    type: 'step',
    position: { ...step.position },
    data: { step: { ...step } },
});

// An If's two ways out are drawn in their colours, and a line hops over any
// other it crosses (HopEdge)
export const toEdge = (edge: StepEdge): Edge => ({
    ...edge,
    type: 'hop',
    markerEnd: MarkerType.ArrowClosed,
    class: `edge-${edge.sourceHandle}`,
});

// A step is 240 by about 60: a spot clear of each one already there
export function clear(nodes: Node[], spot: { x: number; y: number }) {
    while (
        nodes.some(
            (node) =>
                Math.abs(node.position.x - spot.x) < 250 &&
                Math.abs(node.position.y - spot.y) < 80,
        )
    ) {
        spot = { x: spot.x, y: spot.y + 90 };
    }

    return spot;
}

// The steps before one, however far back: what its settings may read
export function before(edges: Edge[], id: string | null) {
    const found = new Set<string>();
    const queue = edges.filter((e) => e.target === id).map((e) => e.source);

    while (queue.length) {
        const next = queue.shift()!;

        if (!found.has(next)) {
            found.add(next);
            queue.push(
                ...edges.filter((e) => e.target === next).map((e) => e.source),
            );
        }
    }

    return found;
}
