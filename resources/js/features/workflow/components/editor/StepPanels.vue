<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { keep, kept } from '@/features/workflow/composables/useEditorSpace';
import { useDataInsert } from '@/features/workflow/composables/useDataInsert';
import type { Sample } from '@/features/workflow/composables/useTestData';
import type {
    NodeType,
    Options,
    RunSummary,
    StepNode as Step,
} from '@/features/workflow/types';
import type { Root } from './DataTree.vue';
import StepSettings from './StepSettings.vue';
import TestData from './TestData.vue';
import Tip from '@/components/common/Tip.vue';

// One step, set up: its settings, with the test data from a run beside them
// to drag or click into a setting -- beside the canvas, or over it in two
// tabs on a narrow screen

const props = defineProps<{
    step: Step;
    type: NodeType | undefined;
    problems: string[];
    editable: boolean;
    compact: boolean;
    // The other steps' keys, which this one cannot take
    takenKeys: Set<string>;
    options: Options;
    // Each with the name, icon and colour of the step it came from
    samples: (Sample & Pick<Root, 'title' | 'icon' | 'tile'>)[];
    runs: RunSummary[];
    // The run whose data is shown, and whether it is still coming
    run: string | null;
    loading: boolean;
}>();

const emit = defineEmits<{
    choose: [run: string];
    update: [patch: Partial<Step>];
    duplicate: [];
    remove: [];
    close: [];
}>();

const DATA_SHOWN = 'workflows.test-data';
const dataShown = ref(kept(DATA_SHOWN));
const dragging = ref(false);

function showData(shown: boolean) {
    dataShown.value = shown;
    keep(DATA_SHOWN, shown);
}

// On a narrow screen a step's settings and its test data take turns
const stepTab = ref<'settings' | 'data'>('settings');
watch(
    () => props.step.id,
    () => (stepTab.value = 'settings'),
);

const dataInsert = useDataInsert();

function insertData(path: string) {
    if (!dataInsert.insert(path)) {
        toast.info('Click the setting it goes in first, then the part.');
    }

    stepTab.value = 'settings';
}
</script>

<template>
    <div
        :ref="(el) => (dataInsert.panels.value = el as HTMLElement)"
        class="flex"
        :class="{
            'bg-background absolute inset-0 z-20 flex-col': compact,
        }"
        data-step-panels
        @focusin="dataInsert.focused"
    >
        <div v-if="compact" class="flex shrink-0 items-center gap-2 p-2">
            <div class="bg-muted/50 flex flex-1 gap-1 rounded-lg p-0.5">
                <button
                    v-for="(text, key) in {
                        settings: 'Settings',
                        data: 'Test data',
                    }"
                    :key="key"
                    type="button"
                    class="flex-1 rounded-md py-1.5 text-xs font-medium transition-colors"
                    :class="
                        stepTab === key
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground'
                    "
                    :data-step-tab="key"
                    @click="stepTab = key"
                >
                    {{ text }}
                </button>
            </div>
            <Tip text="Back to the canvas">
                <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Back to the canvas"
                    data-step-panels-close
                    @click="emit('close')"
                >
                    <X />
                </Button>
            </Tip>
        </div>

        <TestData
            v-if="dataShown || compact"
            v-show="!compact || stepTab === 'data'"
            :samples="samples"
            :runs="runs"
            :run="run"
            :loading="loading"
            :editable="editable"
            :compact="compact"
            @choose="emit('choose', $event)"
            @dragging="dragging = $event"
            @insert="insertData"
            @close="showData(false)"
        />

        <!-- Kept while its test data is on show: a part chosen there goes in here -->
        <StepSettings
            v-show="!compact || stepTab === 'settings'"
            :key="step.id"
            :step="step"
            :type="type"
            :problems="problems"
            :editable="editable"
            :taken-keys="takenKeys"
            :options="options"
            :data-shown="dataShown || compact"
            :dragging="dragging"
            :compact="compact"
            @show-data="showData(true)"
            @update="emit('update', $event)"
            @duplicate="emit('duplicate')"
            @remove="emit('remove')"
            @close="emit('close')"
        />
    </div>
</template>
