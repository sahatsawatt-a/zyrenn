import { useVueFlow } from '@vue-flow/core';
import type { Connection, Edge, Node } from '@vue-flow/core';
import { computed, nextTick, ref } from 'vue';
import type { Ref } from 'vue';
import { toEdge, toNode } from '../canvas';
import type { StepData } from '../canvas';
import { copyConfig, edgeId, newStep, outputsOf } from '../steps';
import type { Definition, NodeType, StepNode as Step } from '../types';

// What a workflow's canvas holds -- its steps and the connections between
// them -- as the draft the server keeps, and the changes made to it: steps
// added, set up, copied and taken away, and joined.

export function useWorkflowCanvas({
    first,
    types,
}: {
    // The draft as the page was given it
    first: Definition;
    types: Record<string, NodeType>;
}) {
    const {
        onConnect,
        getSelectedEdges,
        addEdges,
        addNodes,
        removeNodes,
        findNode,
        removeEdges,
    } = useVueFlow();

    // Typed by hand: Vue Flow's node type is too deep for ref() to unwrap
    const nodes = ref(first.nodes.map(toNode)) as Ref<Node<StepData>[]>;
    const edges = ref(first.edges.map(toEdge)) as Ref<Edge[]>;

    const definition = (): Definition => ({
        schemaVersion: first.schemaVersion,
        nodes: nodes.value.map((node) => ({
            ...node.data!.step,
            position: {
                x: Math.round(node.position.x),
                y: Math.round(node.position.y),
            },
        })),
        edges: edges.value.map((edge) => ({
            id: edge.id,
            source: edge.source,
            sourceHandle: edge.sourceHandle ?? 'out',
            target: edge.target,
            targetHandle: edge.targetHandle ?? 'in',
        })),
        settings: {},
    });

    // Another copy's steps in place of these, as changes to what the canvas
    // holds: steps it has are updated where they are, new ones added, gone ones
    // removed -- then only the connections that differ. Handed a whole new set
    // of steps and connections at once, the canvas drops the connections.
    async function replaceSteps(copy: Definition) {
        const coming = new Set(copy.nodes.map((step) => step.id));
        const going = nodes.value.filter((node) => !coming.has(node.id));

        if (going.length) {
            removeNodes(going.map((node) => node.id));
        }

        const added = copy.nodes.filter((step) => !findNode(step.id));

        for (const step of copy.nodes) {
            const node = findNode(step.id);

            if (node) {
                node.position = { ...step.position };
                node.data = { step: { ...step } };
            }
        }

        if (added.length) {
            addNodes(added.map(toNode));
            // Steps just added are the canvas's only once it has drawn them
            await nextTick();
        }

        const wanted = new Set(copy.edges.map((edge) => edge.id));
        const stale = edges.value.filter((edge) => !wanted.has(edge.id));

        if (stale.length) {
            removeEdges(stale.map((edge) => edge.id));
        }

        const held = new Set(edges.value.map((edge) => edge.id));
        addEdges(copy.edges.filter((edge) => !held.has(edge.id)).map(toEdge));
    }

    const keys = () => new Set(nodes.value.map((node) => node.data!.step.key));

    const nodeOf = (id: string | null | undefined) =>
        nodes.value.find((node) => node.id === id);

    // Its ways out that lead somewhere, so the rest can offer a "+"
    const joinedOf = (id: string) =>
        edges.value
            .filter((edge) => edge.source === id)
            .map((edge) => edge.sourceHandle ?? 'out');

    // A new step of a kind, where it is put
    function place(type: NodeType, position: { x: number; y: number }) {
        const step = newStep(type, position, keys());

        addNodes([toNode(step)]);

        return step;
    }

    // A step just placed is the canvas's only once it has drawn it
    function joinLater(id: string, way: string, target: string) {
        void nextTick(() =>
            addEdges([
                toEdge({
                    id: edgeId(id, way, target),
                    source: id,
                    sourceHandle: way,
                    target,
                    targetHandle: 'in',
                }),
            ]),
        );
    }

    function updateStep(id: string, patch: Partial<Step>) {
        const node = nodeOf(id);

        if (node?.data) {
            node.data = { step: { ...node.data.step, ...patch } };

            // A way renamed or taken away leaves nothing joined to it
            const ways = new Set(
                outputsOf(node.data.step, types[node.data.step.type]),
            );
            const loose = edges.value.filter(
                (edge) =>
                    edge.source === node.id &&
                    !ways.has(edge.sourceHandle ?? 'out'),
            );

            if (loose.length) {
                removeEdges(loose.map((edge) => edge.id));
            }
        }
    }

    /** A copy of a step, a little below it; answers with the copy's id. */
    function duplicate(id: string) {
        const node = nodeOf(id);
        const type = node && types[node.data!.step.type];

        if (!node || !type) {
            return null;
        }

        const copy = newStep(
            type,
            { x: node.position.x + 40, y: node.position.y + 80 },
            keys(),
        );

        addNodes([
            toNode({
                ...copy,
                label: `${node.data!.step.label} copy`,
                config: copyConfig(type, node.data!.step.config),
                onError: node.data!.step.onError,
            }),
        ]);

        return copy.id;
    }

    // Takes its connections with it
    const removeStep = (id: string) => removeNodes([id]);

    // A way in takes any number of connections; nothing goes into a trigger or back into itself
    const canConnect = (connection: Connection) => {
        const target = nodeOf(connection.target);

        return (
            connection.source !== connection.target &&
            !!target &&
            !types[target.data!.step.type]?.trigger &&
            !edges.value.some(
                (edge) =>
                    edge.source === connection.source &&
                    (edge.sourceHandle ?? 'out') ===
                        (connection.sourceHandle ?? 'out') &&
                    edge.target === connection.target,
            )
        );
    };

    onConnect((connection) => {
        const handle = connection.sourceHandle ?? 'out';

        addEdges([
            toEdge({
                id: edgeId(connection.source, handle, connection.target),
                source: connection.source,
                sourceHandle: handle,
                target: connection.target,
                targetHandle: connection.targetHandle ?? 'in',
            }),
        ]);
    });

    // A connection chosen can be taken away without a keyboard
    function unlink() {
        removeEdges(getSelectedEdges.value.map((edge) => edge.id));
    }

    // Each step's name and key, for what a run says of it
    const stepNames = computed(() =>
        Object.fromEntries(
            nodes.value.map((node) => [
                node.id,
                { label: node.data!.step.label, key: node.data!.step.key },
            ]),
        ),
    );

    return {
        nodes,
        edges,
        definition,
        replaceSteps,
        keys,
        nodeOf,
        joinedOf,
        place,
        joinLater,
        updateStep,
        duplicate,
        removeStep,
        canConnect,
        unlink,
        stepNames,
    };
}
