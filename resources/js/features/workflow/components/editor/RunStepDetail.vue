<script setup lang="ts">
import {
    Braces,
    ChevronRight,
    CircleAlert,
    Maximize2,
    Timer,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import DataTree from '@/features/workflow/components/editor/DataTree.vue';
import type { RunLog, RunStep } from '@/features/workflow/types';
import Tip from '@/components/common/Tip.vue';

// One step of a run, opened: what it was given and what it gave, side by
// side, part by part; what its settings came to, there to open; and what it
// wrote in the log. Under its line in the run panel, and larger on its own.

const props = defineProps<{
    step: RunStep;
    // The lines it wrote
    logs: RunLog[];
    // On its own, with the room to show each side whole
    large?: boolean;
}>();

const emit = defineEmits<{ expand: [] }>();

// What it was given, then what it gave: whichever there is
const sides = computed(() =>
    [
        {
            side: 'input' as const,
            title: 'Input',
            value: props.step.input?.input,
            has: !!props.step.input,
        },
        {
            side: 'output' as const,
            title: 'Output',
            value: props.step.output,
            has: props.step.status === 'succeeded',
        },
    ].filter((one) => one.has),
);

const settings = computed(() =>
    props.step.input && Object.keys(props.step.input.settings).length
        ? props.step.input.settings
        : null,
);
const settingsOpen = ref(false);

// As it is kept, for copying out, rather than part by part
const raw = ref(false);

const pretty = (value: unknown) =>
    value === undefined ? '' : JSON.stringify(value, null, 2);

const at = (time: string | null) =>
    time ? new Date(time).toLocaleTimeString() : '';
</script>

<template>
    <div class="space-y-3" data-run-detail>
        <div
            v-if="step.error"
            class="border-destructive/30 bg-destructive/5 text-destructive flex gap-2 rounded-lg border p-2.5 text-xs"
            data-run-error
        >
            <CircleAlert class="mt-px size-3.5 shrink-0" />
            <span class="break-words whitespace-pre-wrap">{{
                step.error.message
            }}</span>
        </div>

        <p
            v-if="step.status === 'waiting' && step.waiting_for === 'delay'"
            class="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400"
        >
            <Timer class="size-3.5" />
            Waiting until
            {{ new Date(step.resume_at!).toLocaleString() }}
        </p>
        <p
            v-if="step.status === 'waiting' && step.waiting_for === 'children'"
            class="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400"
            data-run-children
        >
            <Timer class="size-3.5" />
            Waiting for the runs it started, one for each of
            {{
                Array.isArray(step.wait?.items) ? step.wait.items.length : 'its'
            }}
            items
        </p>
        <p v-if="step.wait?.answer" class="text-muted-foreground text-xs">
            {{ step.wait.answer.approved ? 'Approved' : 'Rejected' }}
            by {{ step.wait.answer.by
            }}<template v-if="step.wait.answer.note"
                >: “{{ step.wait.answer.note }}”</template
            >
        </p>

        <!-- In beside out, where there is the width for two -->
        <div
            v-if="sides.length"
            class="grid grid-cols-[repeat(auto-fit,minmax(15rem,1fr))] gap-3"
        >
            <div
                v-for="(one, index) in sides"
                :key="one.side"
                class="flex min-w-0 flex-col"
            >
                <div class="mb-1 flex h-6 items-center gap-1">
                    <p
                        class="text-muted-foreground flex-1 text-[11px] font-medium uppercase"
                    >
                        {{ one.title }}
                    </p>
                    <template v-if="index === sides.length - 1">
                        <Tip text="As it is kept, to copy out">
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-muted-foreground h-6 px-1.5 text-[11px]"
                                :class="{ 'bg-muted': raw }"
                                data-run-raw
                                @click="raw = !raw"
                            >
                                <Braces class="size-3" /> JSON
                            </Button>
                        </Tip>
                        <Tip v-if="!large" text="Open it larger">
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-muted-foreground h-6 px-1.5"
                                aria-label="Open it larger"
                                data-run-expand
                                @click="emit('expand')"
                            >
                                <Maximize2 class="size-3" />
                            </Button>
                        </Tip>
                    </template>
                </div>
                <div
                    class="bg-background overflow-auto rounded-md border"
                    :class="
                        large
                            ? 'max-h-[62svh] flex-1 md:min-h-[40svh]'
                            : 'max-h-56'
                    "
                    :data-run-input="one.side === 'input' ? '' : undefined"
                    :data-run-output="one.side === 'output' ? '' : undefined"
                >
                    <pre v-if="raw" class="p-2 font-mono text-[11px]">{{
                        pretty(one.value) || 'nothing'
                    }}</pre>
                    <DataTree
                        v-else
                        class="py-1.5"
                        :roots="[{ name: one.side, value: one.value }]"
                        bare
                        full
                    />
                </div>
            </div>
        </div>

        <p
            v-else-if="!step.error && step.status !== 'waiting'"
            class="text-muted-foreground text-xs"
        >
            {{
                step.status === 'skipped'
                    ? 'It was on a way the run did not take.'
                    : 'It has not run.'
            }}
        </p>

        <div v-if="settings">
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground flex items-center gap-1 text-[11px] font-medium uppercase"
                :aria-expanded="settingsOpen"
                data-run-settings
                @click="settingsOpen = !settingsOpen"
            >
                <ChevronRight
                    class="size-3.5 transition-transform"
                    :class="{ 'rotate-90': settingsOpen }"
                />
                Settings worked out
            </button>
            <div
                v-if="settingsOpen"
                class="bg-background mt-1 max-h-56 overflow-auto rounded-md border"
            >
                <pre v-if="raw" class="p-2 font-mono text-[11px]">{{
                    pretty(settings)
                }}</pre>
                <DataTree
                    v-else
                    class="py-1.5"
                    :roots="[{ name: 'settings', value: settings }]"
                    bare
                    full
                />
            </div>
        </div>

        <ul
            v-if="logs.length"
            class="bg-background divide-y rounded-md border font-mono text-[11px]"
            data-run-logs
        >
            <li
                v-for="(log, index) in logs"
                :key="index"
                class="flex gap-2 px-2 py-1"
                :class="{
                    'text-amber-700 dark:text-amber-400':
                        log.level === 'warning',
                    'text-destructive': log.level === 'error',
                }"
            >
                <span class="text-muted-foreground shrink-0">{{
                    at(log.created_at)
                }}</span>
                <span class="break-all">{{ log.message }}</span>
            </li>
        </ul>
    </div>
</template>
