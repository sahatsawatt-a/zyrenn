<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CloudUpload,
    History,
    Loader2,
    Play,
    Rocket,
    Target,
} from '@lucide/vue';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MarkerType, VueFlow, useVueFlow } from '@vue-flow/core';
import type { Connection, Edge, Node, NodeChange } from '@vue-flow/core';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import { computed, nextTick, ref, watch, watchEffect } from 'vue';
import type { Ref } from 'vue';
import { toast } from 'vue-sonner';
import PresenceAvatars from '@/components/common/PresenceAvatars.vue';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import StepNode from '@/features/workflow/components/editor/StepNode.vue';
import StepPalette from '@/features/workflow/components/editor/StepPalette.vue';
import RunPanel from '@/features/workflow/components/editor/RunPanel.vue';
import StepSettings from '@/features/workflow/components/editor/StepSettings.vue';
import TestData from '@/features/workflow/components/editor/TestData.vue';
import type { Activity } from '@/features/audit/ActivityList.vue';
import { usePresence } from '@/composables/usePresence';
import { useSocketState } from '@/composables/useSocketState';
import { useTestData } from '@/features/workflow/composables/useTestData';
import { useWorkflowRuns } from '@/features/workflow/composables/useWorkflowRuns';
import type {
    RunHeard,
    TextHeard,
} from '@/features/workflow/composables/useWorkflowRuns';
import { useWorkflowSave } from '@/features/workflow/composables/useWorkflowSave';
import type { SavedCopy } from '@/features/workflow/composables/useWorkflowSave';
import {
    copyConfig,
    edgeId,
    newStep,
    outputsOf,
} from '@/features/workflow/steps';
import type {
    Definition,
    NodeType,
    Options,
    Problem,
    Publishing,
    StepEdge,
    StepNode as Step,
} from '@/features/workflow/types';
import { owned } from '@/lib/projects';
import { xsrfToken } from '@/lib/utils';
import { index as projectIndex } from '@/routes/projects/workflows';
import {
    enable as enableRoute,
    index as ownIndex,
    show,
} from '@/routes/workflows';

// A workflow's draft on a canvas: steps from the palette, joined by dragging
// from a dot on one to the next; each step set up beside it. It saves itself;
// what runs on its own changes only when it is published.

const props = defineProps<{
    workflow: {
        ref_id: string;
        title: string;
        definition: Definition;
        revision: number;
        enabled: boolean;
        updated_at: string;
        edited_by: string | null;
        problems: Problem[];
        can: { update: boolean };
    } & Publishing;
    nodeTypes: NodeType[];
    options: Options;
    listening: { node_id: string; kind: string }[];
    activity: Activity[];
    groups: Record<string, string>;
    breadcrumbs: { ref_id: string; name: string }[];
}>();

const editable = props.workflow.can.update;
const types = Object.fromEntries(props.nodeTypes.map((t) => [t.type, t]));

const title = ref(props.workflow.title);
const problems = ref<Problem[]>(props.workflow.problems);
const publishing = ref<Publishing>({
    published: props.workflow.published,
    unpublished_changes: props.workflow.unpublished_changes,
});

const index = owned(ownIndex, projectIndex);
watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            { title: 'Workflows', href: index() },
            ...props.breadcrumbs.map((crumb) => ({
                title: crumb.name,
                href: index({ query: { folder: crumb.ref_id } }),
            })),
            {
                title: title.value || 'Untitled workflow',
                href: show(props.workflow.ref_id),
            },
        ],
    }),
);

// ---- what the canvas holds, and the draft it is ----

type StepData = { step: Step };

const toNode = (step: Step): Node<StepData> => ({
    id: step.id,
    type: 'step',
    position: { ...step.position },
    data: { step: { ...step } },
});

// An If's two ways out are drawn in their colours
const toEdge = (edge: StepEdge): Edge => ({
    ...edge,
    type: 'smoothstep',
    markerEnd: MarkerType.ArrowClosed,
    class: `edge-${edge.sourceHandle}`,
});

// Typed by hand: Vue Flow's node type is too deep for ref() to unwrap
const nodes = ref(props.workflow.definition.nodes.map(toNode)) as Ref<
    Node<StepData>[]
>;
const edges = ref(props.workflow.definition.edges.map(toEdge)) as Ref<Edge[]>;

const definition = (): Definition => ({
    schemaVersion: props.workflow.definition.schemaVersion,
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

const saving = useWorkflowSave({
    refId: props.workflow.ref_id,
    firstRevision: props.workflow.revision,
    title,
    definition,
    editable,
    publishing,
    problems,
});

// Saved whenever what would be kept changes -- not when a step is only selected
watch(() => JSON.stringify(definition()), saving.changed);

// ---- steps: added, set up, joined, removed ----

const {
    screenToFlowCoordinate,
    fitView,
    onConnect,
    addSelectedNodes,
    addEdges,
    addNodes,
    removeNodes,
    findNode,
    flowToScreenCoordinate,
    getViewport,
    setCenter,
    removeEdges,
} = useVueFlow();

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

const canvas = ref<HTMLElement | null>(null);
const selectedId = ref<string | null>(null);
const selected = computed(() =>
    nodes.value.find((node) => node.id === selectedId.value),
);

// The settings opening beside the canvas narrows it: the step being set up
// is brought back into sight if that hid it
watch(selectedId, async (id) => {
    await nextTick();
    const node = id ? findNode(id) : undefined;
    const box = canvas.value?.getBoundingClientRect();

    if (!node || !box) {
        return;
    }

    const { width, height } = node.dimensions;
    const corner = flowToScreenCoordinate(node.position);
    const zoom = getViewport().zoom;
    const hidden =
        corner.x < box.left ||
        corner.y < box.top ||
        corner.x + width * zoom > box.right ||
        corner.y + height * zoom > box.bottom;

    if (hidden) {
        void setCenter(
            node.position.x + width / 2,
            node.position.y + height / 2,
            { zoom, duration: 300 },
        );
    }
});

const keys = () => new Set(nodes.value.map((node) => node.data!.step.key));

function place(type: NodeType, position: { x: number; y: number }) {
    const step = newStep(type, position, keys());

    addNodes([toNode(step)]);
    selectedId.value = step.id;
}

// Clicked in the palette: to the right of the step chosen (or the one added
// last), where the next step usually goes -- or, when that is off screen,
// in the middle of what is. Never on top of a step already there.
function addInView(type: NodeType) {
    const box = canvas.value?.getBoundingClientRect();

    if (!box) {
        return;
    }

    const topLeft = screenToFlowCoordinate({ x: box.left, y: box.top });
    const bottomRight = screenToFlowCoordinate({ x: box.right, y: box.bottom });
    const middle = screenToFlowCoordinate({
        x: box.left + box.width / 2,
        y: box.top + box.height / 2,
    });
    const anchor = selected.value ?? nodes.value.at(-1);
    const beside = anchor && {
        x: anchor.position.x + 320,
        y: anchor.position.y,
    };
    const inView = (at: { x: number; y: number }) =>
        at.x >= topLeft.x &&
        at.y >= topLeft.y &&
        at.x + 240 <= bottomRight.x &&
        at.y + 60 <= bottomRight.y;

    let spot =
        beside && inView(beside)
            ? beside
            : { x: middle.x - 120, y: middle.y - 28 };

    // A step is 240 by about 60: clear of each one already there
    while (
        nodes.value.some(
            (node) =>
                Math.abs(node.position.x - spot.x) < 250 &&
                Math.abs(node.position.y - spot.y) < 80,
        )
    ) {
        spot = { x: spot.x, y: spot.y + 90 };
    }

    place(type, spot);
}

function drop(event: DragEvent) {
    const type =
        types[event.dataTransfer?.getData('application/zyrenn-step') ?? ''];

    if (!type || !editable) {
        return;
    }

    const at = screenToFlowCoordinate({ x: event.clientX, y: event.clientY });
    // Dropped by its middle, not its corner
    place(type, { x: at.x - 120, y: at.y - 28 });
}

function updateStep(patch: Partial<Step>) {
    const node = selected.value;

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

function duplicate() {
    const node = selected.value;
    const type = node && types[node.data!.step.type];

    if (!node || !type) {
        return;
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
    selectedId.value = copy.id;
}

function remove() {
    if (selectedId.value) {
        // Takes its connections with it
        removeNodes([selectedId.value]);
    }

    selectedId.value = null;
}

// A way in takes any number of connections; nothing goes into a trigger or back into itself
const canConnect = (connection: Connection) => {
    const target = nodes.value.find((node) => node.id === connection.target);

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

// ---- what stands in the way of publishing ----

const problemsOf = (id: string) =>
    problems.value.filter((p) => p.node === id).map((p) => p.message);

// A step removed on the canvas (Delete) is no longer the one being set up
function nodesChanged(changes: NodeChange[]) {
    if (changes.some((c) => c.type === 'remove' && c.id === selectedId.value)) {
        selectedId.value = null;
    }
}

function focusStep(id?: string) {
    if (!id) {
        return;
    }

    selectedId.value = id;
    const node = findNode(id);

    if (node) {
        addSelectedNodes([node]);
        void fitView({ nodes: [id], duration: 400, maxZoom: 1.2 });
    }
}

// What is before a step, so its settings can say what it may read
const beforeSteps = computed(() => {
    const id = selectedId.value;
    const found = new Set<string>();
    const queue = edges.value
        .filter((e) => e.target === id)
        .map((e) => e.source);

    while (queue.length) {
        const next = queue.shift()!;

        if (!found.has(next)) {
            found.add(next);
            queue.push(
                ...edges.value
                    .filter((e) => e.target === next)
                    .map((e) => e.source),
            );
        }
    }

    return nodes.value
        .filter((node) => found.has(node.id))
        .map((node) => ({ id: node.id, key: node.data!.step.key }));
});
const before = computed(() => beforeSteps.value.map((step) => step.key));

// ---- publishing ----

const publishingNow = ref(false);

function refused(found: Problem[], of = 'publishing') {
    toast.error(
        `${found.length} ${found.length === 1 ? 'thing stands' : 'things stand'} in the way of ${of}.`,
    );
    focusStep(found.find((p) => p.node)?.node);
}

async function publish() {
    publishingNow.value = true;

    try {
        // What the last save found is known here: go to it rather than ask
        await saving.flush();

        if (problems.value.length) {
            refused(problems.value);

            return;
        }

        const answer = await saving.publish();

        if (answer === null) {
            toast.success(
                `Published as version ${publishing.value.published?.number}.`,
            );
        } else if (typeof answer === 'string') {
            toast.error(answer);
        } else {
            refused(answer);
        }
    } finally {
        publishingNow.value = false;
    }
}

// ---- switched on: its own triggers start runs ----

const enabled = ref(props.workflow.enabled);
const switching = ref(false);

const kindsOf = () => nodes.value.map((node) => types[node.data!.step.type]);
// "Run" runs the draft from its Run by hand trigger; the others start by themselves
const hasManual = computed(() =>
    nodes.value.some((node) => node.data!.step.type === 'trigger.manual'),
);
const hasOwnTrigger = computed(() =>
    kindsOf().some((type) => type?.trigger && type.type !== 'trigger.manual'),
);

async function switchOn(on: boolean) {
    switching.value = true;

    try {
        const response = await fetch(enableRoute(props.workflow.ref_id).url, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ enabled: on }),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            toast.error(body.message ?? 'It could not be switched.');

            return;
        }

        enabled.value = body.enabled;
        toast.success(
            body.enabled
                ? 'Switched on: its triggers start runs by themselves.'
                : 'Switched off.',
        );
    } finally {
        switching.value = false;
    }
}

// ---- running it ----

// After a break in live updates, what was missed is asked for again
const { live } = useSocketState(() => {
    if (runs.run.value) {
        void runs.load(runs.run.value.ref_id);
    }

    void runs.loadHistory();
    reloadDraft();
});

const runs = useWorkflowRuns(props.workflow.ref_id, live);

// The draft as saved now, in place of this one -- when nothing is unsaved here
function reloadDraft(theirs?: number) {
    if (theirs !== undefined && !saving.canCatchUp(theirs)) {
        return;
    }

    router.reload({
        only: ['workflow'],
        onSuccess: () =>
            saving.catchUp(props.workflow, (copy) => {
                title.value = copy.title;
                void replaceSteps(copy.definition);
                problems.value = props.workflow.problems;
                publishing.value = {
                    published: props.workflow.published,
                    unpublished_changes: props.workflow.unpublished_changes,
                };
            }),
    });
}

const { others } = usePresence(() => `workflows.${props.workflow.ref_id}`, {
    'run.changed': (news: RunHeard) => runs.heard(news),
    'run.text': (news: TextHeard) => runs.heardText(news),
    changed: (news: { change: string; revision: number }) => {
        if (news.change === 'deleted') {
            toast.error('Someone deleted this workflow.');
        } else {
            reloadDraft(news.revision);
        }
    },
});
const panelOpen = ref(false);
const panelTab = ref<'run' | 'history' | 'activity'>('run');

// The activity is asked for again each time it is looked at
watch(panelTab, (tab) => {
    if (tab === 'activity') {
        router.reload({ only: ['activity'] });
    }
});

// ---- test data, beside a step's settings ----

const testData = useTestData(runs.run, runs.history);
const DATA_SHOWN = 'workflows.test-data';
const dataShown = ref(remembered());
const dragging = ref(false);

function remembered() {
    try {
        return localStorage.getItem(DATA_SHOWN) !== 'hidden';
    } catch {
        return true;
    }
}

function showData(shown: boolean) {
    dataShown.value = shown;

    try {
        localStorage.setItem(DATA_SHOWN, shown ? 'shown' : 'hidden');
    } catch {
        // Only a convenience
    }
}

// The runs to choose from, once a step is opened
let historyAsked = false;
watch(selectedId, (id) => {
    if (id && !historyAsked) {
        historyAsked = true;
        void runs.loadHistory();
    }
});

const samples = computed(() =>
    selected.value
        ? testData.samples(
              selected.value.id,
              !!types[selected.value.data!.step.type]?.trigger,
              beforeSteps.value,
              edges.value
                  .filter((edge) => edge.target === selected.value!.id)
                  .map((edge) => edge.source),
          )
        : [],
);

const running = computed(
    () =>
        !!runs.run.value &&
        // A run that only waits doesn't stop another from starting
        ['queued', 'running'].includes(runs.run.value.status),
);

// Where each step stands in the run on show, for the canvas
const statusOf = (id: string) =>
    panelOpen.value
        ? runs.run.value?.steps.find((step) => step.node_id === id)?.status
        : undefined;

const stepNames = computed(() =>
    Object.fromEntries(
        nodes.value.map((node) => [
            node.id,
            { label: node.data!.step.label, key: node.data!.step.key },
        ]),
    ),
);

async function runNow() {
    await saving.flush();

    if (saving.status.value !== 'saved') {
        toast.error('Save the workflow first.');

        return;
    }

    if (problems.value.length) {
        refused(problems.value, 'running');

        return;
    }

    const answer = await runs.start(saving.revision.value);

    if (answer === null) {
        panelOpen.value = true;
        panelTab.value = 'run';
    } else if (typeof answer === 'string') {
        toast.error(answer);
    } else {
        refused(answer, 'running');
    }
}

function togglePanel() {
    panelOpen.value = !panelOpen.value;

    if (panelOpen.value) {
        void runs.loadHistory();
        panelTab.value = runs.run.value ? panelTab.value : 'history';
    }
}

async function answer(nodeId: string, approved: boolean, note: string) {
    const why = await runs.answer(nodeId, approved, note);

    if (why) {
        toast.error(why);
    }
}

async function stop() {
    const why = await runs.cancel();

    if (why) {
        toast.error(why);
    }
}

async function openRun(ref: string) {
    await runs.load(ref);
    panelTab.value = 'run';
}

// ---- someone else saved first ----

const conflictOpen = computed({
    get: () => saving.conflict.value !== null,
    set: (open) => {
        if (!open) {
            saving.keepMine();
        }
    },
});

function takeTheirs() {
    saving.takeTheirs((copy: SavedCopy) => {
        title.value = copy.title;
        void replaceSteps(copy.definition);
        selectedId.value = null;
    });
}

const statusText = computed(
    () =>
        ({
            saved: 'Saved',
            saving: 'Saving…',
            unsaved: 'Unsaved',
            error: saving.saveError.value || 'Not saved',
            conflict: 'Someone else saved',
        })[saving.status.value],
);
</script>

<template>
    <Head :title="title || 'Untitled workflow'" />

    <div class="flex h-[calc(100svh-4rem)] flex-col overflow-hidden">
        <!-- What it is called, whether it is saved, and what runs -->
        <header
            class="bg-background flex flex-wrap items-center gap-3 border-b px-4 py-2"
        >
            <input
                v-model="title"
                class="hover:bg-muted/50 focus:bg-muted/50 min-w-0 flex-1 rounded-md bg-transparent px-2 py-1 text-base font-semibold outline-none"
                placeholder="Untitled workflow"
                aria-label="Workflow title"
                maxlength="255"
                :readonly="!editable"
            />

            <PresenceAvatars :others="others" />

            <span
                class="text-muted-foreground flex items-center gap-1.5 text-xs"
                :class="{
                    'text-destructive': ['error', 'conflict'].includes(
                        saving.status.value,
                    ),
                }"
                data-save-status
            >
                <Loader2
                    v-if="saving.status.value === 'saving'"
                    class="size-3.5 animate-spin"
                />
                <CloudUpload v-else class="size-3.5" />
                {{ statusText }}
                <Button
                    v-if="saving.status.value === 'error'"
                    size="sm"
                    variant="link"
                    class="h-auto p-0 text-xs"
                    @click="saving.retry()"
                    >Try again</Button
                >
            </span>

            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        size="sm"
                        variant="ghost"
                        :class="
                            problems.length
                                ? 'text-destructive hover:text-destructive'
                                : 'text-emerald-600 dark:text-emerald-400'
                        "
                        data-problems
                    >
                        <CircleAlert v-if="problems.length" />
                        <CircleCheck v-else />
                        {{
                            problems.length
                                ? `${problems.length} to fix`
                                : 'Ready'
                        }}
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" class="w-80 p-0">
                    <p class="border-b px-3 py-2 text-sm font-medium">
                        {{
                            problems.length
                                ? 'Before it can be published'
                                : 'Nothing stands in the way of publishing.'
                        }}
                    </p>
                    <ul v-if="problems.length" class="max-h-72 overflow-y-auto">
                        <li v-for="(problem, at) in problems" :key="at">
                            <button
                                type="button"
                                class="hover:bg-muted/60 flex w-full gap-2 px-3 py-2 text-left text-xs"
                                :disabled="!problem.node"
                                @click="focusStep(problem.node)"
                            >
                                <CircleAlert
                                    class="text-destructive mt-px size-3.5 shrink-0"
                                />
                                <span>
                                    <span
                                        v-if="problem.node"
                                        class="font-medium"
                                        >{{
                                            nodes.find(
                                                (n) => n.id === problem.node,
                                            )?.data?.step.label
                                        }}:
                                    </span>
                                    {{ problem.message }}
                                </span>
                            </button>
                        </li>
                    </ul>
                </PopoverContent>
            </Popover>

            <Badge
                v-if="publishing.published"
                variant="secondary"
                class="font-normal"
                data-published
            >
                v{{ publishing.published.number }}
                {{
                    publishing.unpublished_changes
                        ? '· changed since'
                        : '· published'
                }}
            </Badge>
            <Badge v-else variant="outline" class="font-normal"
                >Not published</Badge
            >

            <Button
                size="sm"
                variant="ghost"
                :class="{ 'bg-muted': panelOpen }"
                data-runs-toggle
                @click="togglePanel"
            >
                <History /> Runs
            </Button>

            <label
                v-if="editable && hasOwnTrigger"
                class="flex items-center gap-2 text-xs"
                :title="
                    publishing.published
                        ? 'Its triggers start runs by themselves, as published'
                        : 'Publish it first: what is switched on is what is published'
                "
                data-enabled
            >
                <Switch
                    :model-value="enabled"
                    :disabled="!publishing.published || switching"
                    @update:model-value="switchOn"
                />
                {{ enabled ? 'On' : 'Off' }}
            </label>

            <Button
                v-if="editable && hasManual"
                size="sm"
                variant="outline"
                :disabled="runs.starting.value || running"
                data-run
                @click="runNow"
            >
                <Loader2
                    v-if="runs.starting.value || running"
                    class="animate-spin"
                />
                <Play v-else />
                Run
            </Button>

            <Button
                v-if="editable"
                size="sm"
                :disabled="
                    publishingNow ||
                    (!!publishing.published && !publishing.unpublished_changes)
                "
                data-publish
                @click="publish"
            >
                <Loader2 v-if="publishingNow" class="animate-spin" />
                <Rocket v-else />
                Publish
            </Button>
        </header>

        <div class="flex min-h-0 flex-1">
            <StepPalette
                v-if="editable"
                :types="nodeTypes"
                :groups="groups"
                @add="addInView"
            />

            <div class="flex min-w-0 flex-1 flex-col">
                <div
                    class="relative min-h-0 min-w-0 flex-1"
                    ref="canvas"
                    data-canvas
                    @drop.prevent="drop"
                    @dragover.prevent
                >
                    <VueFlow
                        v-model:nodes="nodes"
                        v-model:edges="edges"
                        :default-edge-options="{
                            type: 'smoothstep',
                            markerEnd: MarkerType.ArrowClosed,
                        }"
                        :is-valid-connection="canConnect"
                        :nodes-draggable="editable"
                        :nodes-connectable="editable"
                        :edges-updatable="false"
                        :delete-key-code="
                            editable ? ['Backspace', 'Delete'] : null
                        "
                        :min-zoom="0.2"
                        :max-zoom="2"
                        @pane-ready="fitView({ padding: 0.3, maxZoom: 1 })"
                        class="workflow-canvas"
                        @node-click="selectedId = $event.node.id"
                        @pane-click="selectedId = null"
                        @nodes-change="nodesChanged"
                    >
                        <Background :gap="20" :size="1.2" />
                        <Controls :show-interactive="false" />
                        <!-- Under the run panel the canvas is short: it would cover the steps -->
                        <MiniMap v-if="!panelOpen" pannable zoomable />

                        <template #node-step="step">
                            <StepNode
                                :id="step.id"
                                :data="{
                                    step: step.data.step,
                                    type: types[step.data.step.type],
                                    problems: problemsOf(step.id),
                                }"
                                :selected="step.id === selectedId"
                                :status="statusOf(step.id)"
                            />
                        </template>
                    </VueFlow>

                    <Button
                        size="icon-sm"
                        variant="outline"
                        class="bg-background/90 absolute top-3 right-3 z-10"
                        aria-label="Fit the workflow in view"
                        @click="fitView({ padding: 0.2, duration: 400 })"
                    >
                        <Target />
                    </Button>
                </div>

                <RunPanel
                    v-if="panelOpen"
                    v-model:tab="panelTab"
                    :run="runs.run.value"
                    :history="runs.history.value"
                    :writing="runs.writing.value"
                    :activity="activity"
                    :steps="stepNames"
                    :selected-id="selectedId"
                    @select="focusStep($event ?? undefined)"
                    @open="openRun"
                    @close="panelOpen = false"
                    @answer="answer"
                    @cancel="stop"
                />
            </div>

            <TestData
                v-if="selected && dataShown"
                :samples="samples"
                :runs="runs.history.value"
                :run="testData.which.value"
                :loading="testData.loading.value"
                :editable="editable"
                @choose="testData.chosen.value = $event"
                @dragging="dragging = $event"
                @close="showData(false)"
            />

            <StepSettings
                v-if="selected"
                :key="selected.id"
                :step="selected.data!.step"
                :type="types[selected.data!.step.type]"
                :problems="problemsOf(selected.id)"
                :editable="editable"
                :taken-keys="
                    new Set(
                        nodes
                            .filter((n) => n.id !== selected!.id)
                            .map((n) => n.data!.step.key),
                    )
                "
                :before="before"
                :options="options"
                :data-shown="dataShown"
                :dragging="dragging"
                @show-data="showData(true)"
                @update="updateStep"
                @duplicate="duplicate"
                @remove="remove"
                @close="selectedId = null"
            />
        </div>
    </div>

    <Dialog v-model:open="conflictOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Someone else changed this workflow</DialogTitle>
                <DialogDescription>
                    {{ saving.conflict.value?.edited_by ?? 'Someone' }} saved it
                    after you opened it. Keep your copy over theirs, or take
                    theirs and lose the changes made here.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="takeTheirs"
                    >Take theirs</Button
                >
                <Button @click="saving.keepMine()">Keep mine</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style>
.workflow-canvas {
    background: var(--muted);
}

.workflow-canvas .vue-flow__controls,
.workflow-canvas .vue-flow__minimap {
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    overflow: hidden;
    background: var(--card);
}

.workflow-canvas .vue-flow__controls-button {
    background: var(--card);
    border-bottom: 1px solid var(--border);
    fill: var(--foreground);
}

.workflow-canvas .vue-flow__edge-path {
    stroke: var(--muted-foreground);
    stroke-width: 1.5;
}

.workflow-canvas .vue-flow__edge.edge-true .vue-flow__edge-path,
.workflow-canvas .vue-flow__edge.edge-approved .vue-flow__edge-path {
    stroke: var(--color-emerald-500);
}

.workflow-canvas .vue-flow__edge.edge-false .vue-flow__edge-path,
.workflow-canvas .vue-flow__edge.edge-rejected .vue-flow__edge-path {
    stroke: var(--color-rose-500);
}

.workflow-canvas .vue-flow__edge.selected .vue-flow__edge-path {
    stroke: var(--primary);
}
</style>
