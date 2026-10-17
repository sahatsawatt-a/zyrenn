<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { History, Loader2, Play, Rocket } from '@lucide/vue';
import { useVueFlow } from '@vue-flow/core';
import type { NodeChange } from '@vue-flow/core';
import { computed, nextTick, onMounted, ref, watch, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
import PresenceAvatars from '@/components/common/PresenceAvatars.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import type { Activity } from '@/features/audit/ActivityList.vue';
import ConflictDialog from '@/features/workflow/components/editor/ConflictDialog.vue';
import ProblemsButton from '@/features/workflow/components/editor/ProblemsButton.vue';
import PublishedBadge from '@/features/workflow/components/editor/PublishedBadge.vue';
import RunPanel from '@/features/workflow/components/editor/RunPanel.vue';
import SaveStatus from '@/features/workflow/components/editor/SaveStatus.vue';
import StepPalette from '@/features/workflow/components/editor/StepPalette.vue';
import StepPanels from '@/features/workflow/components/editor/StepPanels.vue';
import WorkflowCanvas from '@/features/workflow/components/editor/WorkflowCanvas.vue';
import { usePresence } from '@/composables/usePresence';
import { useSocketState } from '@/composables/useSocketState';
import { useEditorSpace } from '@/features/workflow/composables/useEditorSpace';
import { useStepPalette } from '@/features/workflow/composables/useStepPalette';
import { useStepSamples } from '@/features/workflow/composables/useStepSamples';
import { useTestData } from '@/features/workflow/composables/useTestData';
import { useWorkflowCanvas } from '@/features/workflow/composables/useWorkflowCanvas';
import { useWorkflowRuns } from '@/features/workflow/composables/useWorkflowRuns';
import type {
    RunHeard,
    TextHeard,
} from '@/features/workflow/composables/useWorkflowRuns';
import { useWorkflowSave } from '@/features/workflow/composables/useWorkflowSave';
import type { SavedCopy } from '@/features/workflow/composables/useWorkflowSave';
import { useWorkflowSwitch } from '@/features/workflow/composables/useWorkflowSwitch';
import type {
    Definition,
    NodeType,
    Options,
    Problem,
    Publishing,
} from '@/features/workflow/types';
import { owned } from '@/lib/projects';
import { index as projectIndex } from '@/routes/projects/workflows';
import { index as ownIndex, show } from '@/routes/workflows';
import Tip from '@/components/common/Tip.vue';

// A workflow's draft on a canvas: steps from the palette, joined by dragging
// from a dot on one to the next -- or added already joined, from a step's
// "+"; each step set up beside it. It saves itself; what runs on its own
// changes only when it is published. On a narrow screen the canvas has the
// width to itself, and the palette and a step's settings open over it.

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
    // A group with many kinds, in parts: each part's name and its kinds, in order
    sections: Record<string, Record<string, string[]>>;
    // The heading each group is listed under; any other is a service
    headings: Record<string, 'general' | 'zyrenn'>;
    breadcrumbs: { ref_id: string; name: string }[];
}>();

const editable = props.workflow.can.update;
const types = Object.fromEntries(props.nodeTypes.map((t) => [t.type, t]));

const editor = ref<HTMLElement | null>(null);
const { compact, tight } = useEditorSpace(editor);

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

const graph = useWorkflowCanvas({ first: props.workflow.definition, types });
const { nodes, edges } = graph;

const saving = useWorkflowSave({
    refId: props.workflow.ref_id,
    firstRevision: props.workflow.revision,
    title,
    definition: graph.definition,
    editable,
    publishing,
    problems,
});

// Saved whenever what would be kept changes -- not when a step is only selected
watch(() => JSON.stringify(graph.definition()), saving.changed);

// ---- the step being set up ----

const {
    fitView,
    addSelectedNodes,
    findNode,
    flowToScreenCoordinate,
    getViewport,
    setCenter,
} = useVueFlow();

const canvasView = ref<InstanceType<typeof WorkflowCanvas> | null>(null);
const canvas = computed(() => canvasView.value?.el ?? null);
const selectedId = ref<string | null>(null);
const selected = computed(() => graph.nodeOf(selectedId.value));

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

function focusStep(id?: string) {
    if (!id) {
        return;
    }

    selectedId.value = id;
    const node = findNode(id);

    if (node) {
        addSelectedNodes([node]);
        void fitView({
            nodes: [id],
            duration: 400,
            // Not so close, on a narrow screen, that only the one step fits
            maxZoom: compact.value ? 0.7 : 1.2,
        });
    }
}

const panelOpen = ref(false);
const panelTab = ref<'run' | 'history' | 'activity'>('run');

// While a run is looked at on a narrow screen, a step chosen shows how it
// went instead
const settingUp = computed(
    () => !!selected.value && !(compact.value && panelOpen.value),
);

const palette = useStepPalette({
    graph,
    types,
    editable,
    compact,
    tight,
    settingUp: () => settingUp.value,
    selectedId,
    canvas,
});
const { nextFrom, paletteShown } = palette;

function updateStep(patch: Parameters<typeof graph.updateStep>[1]) {
    if (selectedId.value) {
        graph.updateStep(selectedId.value, patch);
    }
}

function duplicate() {
    const copy = selectedId.value && graph.duplicate(selectedId.value);

    if (copy) {
        selectedId.value = copy;
    }
}

function remove() {
    if (selectedId.value) {
        graph.removeStep(selectedId.value);
    }

    selectedId.value = null;
}

// A step removed on the canvas (Delete) is no longer the one being set up
function nodesChanged(changes: NodeChange[]) {
    if (changes.some((c) => c.type === 'remove' && c.id === selectedId.value)) {
        selectedId.value = null;
    }

    if (
        changes.some((c) => c.type === 'remove' && c.id === nextFrom.value?.id)
    ) {
        nextFrom.value = null;
    }
}

// ---- what stands in the way of publishing ----

const problemsOf = (id: string) =>
    problems.value.filter((p) => p.node === id).map((p) => p.message);

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

const { enabled, switching, switchOn } = useWorkflowSwitch(
    props.workflow.ref_id,
    props.workflow.enabled,
);

const kindsOf = () => nodes.value.map((node) => types[node.data!.step.type]);
// "Run" runs the draft from its Run by hand trigger; the others start by themselves
const hasManual = computed(() =>
    nodes.value.some((node) => node.data!.step.type === 'trigger.manual'),
);
const hasOwnTrigger = computed(() =>
    kindsOf().some((type) => type?.trigger && type.type !== 'trigger.manual'),
);

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

// A run named in the address -- from the dashboard, waiting for approval or
// failed -- opened in the panel as the page opens
onMounted(() => {
    const asked = new URLSearchParams(window.location.search).get('run');

    if (asked) {
        panelOpen.value = true;
        panelTab.value = 'run';
        void runs.load(asked);
    }
});

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
                void graph.replaceSteps(copy.definition);
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

// The activity is asked for again each time it is looked at
watch(panelTab, (tab) => {
    if (tab === 'activity') {
        router.reload({ only: ['activity'] });
    }
});

// ---- test data, beside a step's settings ----

const testData = useTestData(runs.run, runs.history);
const samples = useStepSamples({ testData, graph, types, selectedId });

// The runs to choose from, once a step is opened
let historyAsked = false;
watch(selectedId, (id) => {
    if (id && !historyAsked) {
        historyAsked = true;
        void runs.loadHistory();
    }
});

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
    if (panelOpen.value) {
        closePanel();

        return;
    }

    panelOpen.value = true;
    void runs.loadHistory();
    panelTab.value = runs.run.value ? panelTab.value : 'history';
}

function closePanel() {
    panelOpen.value = false;

    // Where a step chosen showed how it went, not its settings
    if (compact.value) {
        selectedId.value = null;
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

function takeTheirs() {
    saving.takeTheirs((copy: SavedCopy) => {
        title.value = copy.title;
        void graph.replaceSteps(copy.definition);
        selectedId.value = null;
    });
}
</script>

<template>
    <Head :title="title || 'Untitled workflow'" />

    <div
        ref="editor"
        class="flex h-[calc(100svh-4rem)] flex-col overflow-hidden"
        :data-compact="compact || undefined"
    >
        <!-- What it is called, whether it is saved, and what runs: in two
             rows where one would not hold it -->
        <header
            class="bg-background flex flex-wrap items-center justify-end gap-x-3 gap-y-1.5 border-b py-2"
            :class="compact ? 'px-2' : 'px-4'"
        >
            <input
                v-model="title"
                class="hover:bg-muted/50 focus:bg-muted/50 min-w-0 flex-1 rounded-md bg-transparent px-2 py-1 text-base font-semibold outline-none"
                :class="{ 'basis-40': compact }"
                placeholder="Untitled workflow"
                aria-label="Workflow title"
                maxlength="255"
                :readonly="!editable"
            />

            <PresenceAvatars :others="others" />

            <SaveStatus
                :status="saving.status.value"
                :error="saving.saveError.value"
                :compact="compact"
                @retry="saving.retry()"
            />

            <ProblemsButton
                :problems="problems"
                :label-of="(id) => graph.nodeOf(id)?.data?.step.label"
                @focus="focusStep"
            />

            <PublishedBadge :publishing="publishing" :compact="compact" />

            <Button
                :size="compact ? 'icon-sm' : 'sm'"
                variant="ghost"
                :class="{ 'bg-muted': panelOpen }"
                aria-label="Runs"
                data-runs-toggle
                @click="togglePanel"
            >
                <History />
                <template v-if="!compact">Runs</template>
            </Button>

            <Tip
                v-if="editable && hasOwnTrigger"
                :text="
                    publishing.published
                        ? 'Its triggers start runs by themselves, as published'
                        : 'Publish it first: what is switched on is what is published'
                "
            >
                <label class="flex items-center gap-2 text-xs" data-enabled>
                    <Switch
                        :model-value="enabled"
                        :disabled="!publishing.published || switching"
                        @update:model-value="switchOn"
                    />
                    {{ enabled ? 'On' : 'Off' }}
                </label>
            </Tip>

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

        <div class="flex min-h-0 flex-1 flex-col">
            <div class="relative flex min-h-0 flex-1 overflow-hidden">
                <StepPalette
                    v-if="paletteShown"
                    :types="nodeTypes"
                    :groups="groups"
                    :sections="sections"
                    :headings="headings"
                    :after="palette.nextAfter.value"
                    :compact="compact"
                    :started="kindsOf().some((type) => type?.trigger)"
                    @add="palette.addInView"
                    @close="palette.showPalette(false)"
                    @forget="nextFrom = null"
                />

                <div class="flex min-w-0 flex-1 flex-col">
                    <WorkflowCanvas
                        ref="canvasView"
                        v-model:nodes="nodes"
                        v-model:edges="edges"
                        :types="types"
                        :editable="editable"
                        :compact="compact"
                        :panel-open="panelOpen"
                        :palette-shown="paletteShown"
                        :selected-id="selectedId"
                        :can-connect="graph.canConnect"
                        :problems-of="problemsOf"
                        :joined-of="graph.joinedOf"
                        :status-of="statusOf"
                        @select="selectedId = $event"
                        @nodes-change="nodesChanged"
                        @next="palette.addAfter"
                        @add-step="palette.showPalette(true)"
                        @drop="palette.drop"
                        @unlink="graph.unlink"
                    />
                </div>

                <StepPanels
                    v-if="settingUp && selected"
                    :step="selected.data!.step"
                    :type="types[selected.data!.step.type]"
                    :problems="problemsOf(selected.id)"
                    :editable="editable"
                    :compact="compact"
                    :taken-keys="
                        new Set(
                            nodes
                                .filter((n) => n.id !== selected!.id)
                                .map((n) => n.data!.step.key),
                        )
                    "
                    :options="options"
                    :samples="samples"
                    :runs="runs.history.value"
                    :run="testData.which.value"
                    :loading="testData.loading.value"
                    @choose="testData.chosen.value = $event"
                    @update="updateStep"
                    @duplicate="duplicate"
                    @remove="remove"
                    @close="selectedId = null"
                />
            </div>

            <!-- How a run went, under all of it: as wide as the editor -->
            <RunPanel
                v-if="panelOpen"
                v-model:tab="panelTab"
                :run="runs.run.value"
                :history="runs.history.value"
                :writing="runs.writing.value"
                :activity="activity"
                :steps="graph.stepNames.value"
                :selected-id="selectedId"
                :compact="compact"
                @select="focusStep($event ?? undefined)"
                @open="openRun"
                @close="closePanel"
                @answer="answer"
                @cancel="stop"
            />
        </div>
    </div>

    <ConflictDialog
        :open="saving.conflict.value !== null"
        :edited-by="saving.conflict.value?.edited_by"
        @keep="saving.keepMine()"
        @take="takeTheirs"
    />
</template>
