<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import DataTree from '@/features/workflow/components/editor/DataTree.vue';
import type { Root } from '@/features/workflow/components/editor/DataTree.vue';
import PickOne from '@/features/workflow/components/editor/PickOne.vue';
import type { Sample } from '@/features/workflow/composables/useTestData';
import { statusLook } from '@/features/workflow/steps';
import type { RunSummary } from '@/features/workflow/types';

// What a step can read, as a real run had it, beside its settings: a card
// for the trigger, for what came in, and for each step before it, under
// that step's name. Each part is dragged into a setting, or clicked to go
// where the cursor was -- as {{ trigger.chat.id }} into a template, or bare
// into a formula. As in n8n and Activepieces, set up from what came.

const props = defineProps<{
    // Each with the name, icon and colour of the step it came from
    samples: (Sample & Pick<Root, 'title' | 'icon' | 'tile'>)[];
    runs: RunSummary[];
    // The run shown
    run: string | null;
    loading: boolean;
    editable: boolean;
    // One of two tabs over the canvas, on a narrow screen
    compact?: boolean;
}>();

const emit = defineEmits<{
    choose: [ref: string];
    dragging: [on: boolean];
    // A part clicked: into the setting last written in
    insert: [path: string];
    close: [];
}>();

// What comes folded: an earlier step's output, a list, and what came in
// when it is only the trigger over again -- there to open, not to read
// past. The rest comes open.
const same = (a: unknown, b: unknown) =>
    JSON.stringify(a) === JSON.stringify(b);

const foldedAtFirst = (id: string, depth: number, value: unknown) =>
    depth === 0
        ? id.startsWith('node(') ||
          (id === 'input' &&
              same(
                  value,
                  props.samples.find((sample) => sample.name === 'trigger')
                      ?.value,
              ))
        : Array.isArray(value);

// A card each, in the order a formula would reach for them; one the run
// never got to says so in place of its parts -- and before any run, the
// line above has said it for them all
const roots = computed<Root[]>(() =>
    props.samples.map((sample) => ({
        ...sample,
        note: sample.reached
            ? undefined
            : props.runs.length
              ? 'not reached'
              : '',
    })),
);

const runOptions = computed(() =>
    props.runs.map((run) => ({
        value: run.ref_id,
        label: `${statusLook(run.status).label} · ${new Date(run.created_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}`,
        hint: run.version.kind === 'test' ? 'test' : `v${run.version.number}`,
    })),
);
</script>

<template>
    <aside
        class="bg-muted/30 flex min-h-0 flex-col"
        :class="compact ? 'flex-1' : 'w-72 shrink-0 border-l'"
        data-test-data
    >
        <div class="bg-background space-y-2 border-b p-3">
            <!-- On a narrow screen its tab says what it is, and closes it -->
            <div v-if="!compact" class="flex items-center gap-2">
                <p class="flex-1 text-sm font-medium">Test data</p>
                <Button
                    size="icon-sm"
                    variant="ghost"
                    class="-my-1 -mr-1.5"
                    aria-label="Hide the test data"
                    @click="emit('close')"
                >
                    <X />
                </Button>
            </div>
            <PickOne
                :model-value="run"
                :options="runOptions"
                placeholder="No runs yet"
                :disabled="!runs.length"
                @update:model-value="$event && emit('choose', $event)"
            />
            <p class="text-muted-foreground text-[11px] leading-snug">
                <template v-if="!runs.length">
                    Run it once, or let its trigger fire, and what each step
                    gives shows here.
                </template>
                <template v-else-if="editable && compact">
                    Tap a part to put it in the setting you were writing.
                </template>
                <template v-else-if="editable">
                    Drag a part into a setting, or click it.
                </template>
                <template v-else>What this run gave.</template>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto p-2">
            <p
                v-if="loading && !samples.some((sample) => sample.reached)"
                class="text-muted-foreground px-1 pb-2 text-xs"
            >
                Loading…
            </p>
            <DataTree
                :roots="roots"
                :pick="editable"
                :roomy="compact"
                :folded-at-first="foldedAtFirst"
                @dragging="emit('dragging', $event)"
                @pick="emit('insert', $event)"
            />
        </div>
    </aside>
</template>
