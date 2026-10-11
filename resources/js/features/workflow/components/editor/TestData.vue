<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import DataTree from '@/features/workflow/components/editor/DataTree.vue';
import PickOne from '@/features/workflow/components/editor/PickOne.vue';
import type { Sample } from '@/features/workflow/composables/useTestData';
import { statusLook } from '@/features/workflow/steps';
import type { RunSummary } from '@/features/workflow/types';

// What a step can read, as a real run had it, beside its settings: each part
// is dragged into a setting, or clicked to go where the cursor was -- as
// {{ trigger.chat.id }} into a template, or bare into a formula. As in n8n
// and Activepieces, set up from what came.

const props = defineProps<{
    samples: Sample[];
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

const reached = computed(() =>
    props.samples.filter((sample) => sample.reached),
);

const unreached = computed(() =>
    props.samples.filter((sample) => !sample.reached),
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
        class="bg-muted/20 flex min-h-0 flex-col"
        :class="compact ? 'flex-1' : 'w-64 shrink-0 border-l'"
        data-test-data
    >
        <!-- On a narrow screen its tab says what it is, and closes it -->
        <div
            v-if="!compact"
            class="flex items-center gap-2 border-b px-3 py-2.5"
        >
            <p class="flex-1 text-sm font-medium">Test data</p>
            <Button
                size="icon-sm"
                variant="ghost"
                aria-label="Hide the test data"
                @click="emit('close')"
            >
                <X />
            </Button>
        </div>

        <div class="border-b p-3">
            <PickOne
                :model-value="run"
                :options="runOptions"
                placeholder="No runs yet"
                :disabled="!runs.length"
                @update:model-value="$event && emit('choose', $event)"
            />
            <p class="text-muted-foreground mt-1.5 text-xs leading-relaxed">
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

        <div class="flex-1 overflow-y-auto py-1.5 font-mono text-[11px]">
            <p
                v-if="loading && !reached.length"
                class="text-muted-foreground px-3 py-2 font-sans text-xs"
            >
                Loading…
            </p>
            <DataTree
                :roots="reached"
                :pick="editable"
                :roomy="compact"
                :folded-at-first="foldedAtFirst"
                @dragging="emit('dragging', $event)"
                @pick="emit('insert', $event)"
            />

            <div
                v-for="sample in unreached"
                :key="sample.name"
                class="mt-1.5 flex items-center gap-1 px-2 py-0.5"
            >
                <span class="w-2.5 shrink-0" />
                <span class="bg-background shrink-0 rounded border px-1">{{
                    sample.name
                }}</span>
                <span
                    class="text-muted-foreground truncate font-sans text-xs italic"
                    >{{ runs.length ? 'not reached in this run' : '' }}</span
                >
            </div>
        </div>
    </aside>
</template>
