<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';

// What an MCP key may do, a part of the app at a time -- nothing, read, or
// read and change -- and, a click away, the tools each choice offers a
// client using it.

export type Level = 'off' | 'read' | 'change';
export type KeyTool = { name: string; changes: boolean };

const props = defineProps<{
    parts: Record<string, string>;
    tools: Record<string, KeyTool[]>;
}>();
const levels = defineModel<Record<string, Level>>({ required: true });

const LEVELS = [
    { value: 'off' as const, label: 'Off' },
    { value: 'read' as const, label: 'Read' },
    { value: 'change' as const, label: 'Change' },
];

const offered = (part: string) =>
    (props.tools[part] ?? []).filter(
        (tool) =>
            levels.value[part] === 'change' ||
            (levels.value[part] === 'read' && !tool.changes),
    );

const showing = ref(false);

function setAll(level: Level) {
    levels.value = Object.fromEntries(
        Object.keys(props.parts).map((part) => [part, level]),
    );
}
</script>

<template>
    <div class="space-y-2" data-test="key-access">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
            <span class="text-muted-foreground">All:</span>
            <button
                v-for="one in LEVELS"
                :key="one.value"
                type="button"
                class="text-muted-foreground hover:text-foreground underline-offset-2 hover:underline"
                :data-test="`key-all-${one.value}`"
                @click="setAll(one.value)"
            >
                {{ one.label }}
            </button>
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground ml-auto inline-flex items-center gap-1"
                :aria-expanded="showing"
                data-test="key-show-tools"
                @click="showing = !showing"
            >
                {{ showing ? 'Hide tools' : 'Show tools' }}
                <ChevronDown
                    class="size-3.5 transition-transform"
                    :class="{ 'rotate-180': showing }"
                />
            </button>
        </div>
        <ul class="divide-y rounded-lg border">
            <li
                v-for="(label, part) in parts"
                :key="part"
                class="px-3 py-2"
                :data-test="`key-part-${part}`"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="min-w-0 truncate text-sm">{{ label }}</span>
                    <SegmentTabs
                        v-model="levels[part]"
                        :options="
                            LEVELS.map((one) => ({
                                ...one,
                                test: `key-${part}-${one.value}`,
                            }))
                        "
                        :label="`What it may do with ${label}`"
                    />
                </div>
                <p
                    v-if="showing"
                    class="text-muted-foreground mt-1 text-xs break-words"
                    data-test="key-tools"
                >
                    {{
                        offered(part)
                            .map((tool) => tool.name)
                            .join(', ') || 'No tools'
                    }}
                </p>
            </li>
        </ul>
    </div>
</template>
