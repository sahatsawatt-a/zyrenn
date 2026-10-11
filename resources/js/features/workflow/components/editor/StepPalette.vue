<script setup lang="ts">
import { PanelLeftClose, Search, X, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { iconOf, toneOf } from '@/features/workflow/steps';
import type { NodeType } from '@/features/workflow/types';

// The kinds of step, by group: dragged onto the canvas, or clicked to add
// one beside the last -- or after the step whose "+" was pressed. Each is
// its name alone; what it does is said when it is pointed at, and beside
// the canvas once it is there.

const props = defineProps<{
    types: NodeType[];
    groups: Record<string, string>;
    // The step the next one is joined to, and by which way out
    after?: { label: string; way: string } | null;
    // Over the canvas rather than beside it, on a narrow screen
    compact?: boolean;
}>();

const emit = defineEmits<{
    add: [type: NodeType];
    close: [];
    forget: [];
}>();

const query = ref('');

const shown = computed(() =>
    Object.entries(props.groups)
        .map(([key, title]) => ({
            key,
            title,
            // What starts a run first, then what it can do
            types: props.types
                .filter(
                    (type) =>
                        type.group === key &&
                        // Nothing leads into what starts a run
                        !(props.after && type.trigger) &&
                        `${type.label} ${type.description} ${title}`
                            .toLowerCase()
                            .includes(query.value.trim().toLowerCase()),
                )
                .sort((a, b) => Number(b.trigger) - Number(a.trigger)),
        }))
        .filter((group) => group.types.length),
);

function drag(event: DragEvent, type: NodeType) {
    event.dataTransfer?.setData('application/zyrenn-step', type.type);

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}
</script>

<template>
    <aside
        class="bg-background flex flex-col"
        :class="compact ? 'absolute inset-0 z-20' : 'w-52 shrink-0 border-r'"
        data-step-palette
    >
        <div class="flex items-center gap-1.5 border-b p-2">
            <div class="relative min-w-0 flex-1">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
                />
                <input
                    v-model="query"
                    type="search"
                    placeholder="Find a step"
                    aria-label="Find a step"
                    class="bg-muted/40 focus-visible:ring-ring/50 h-8 w-full rounded-md border pr-2 pl-8 text-sm outline-none focus-visible:ring-[3px]"
                />
            </div>
            <Button
                size="icon-sm"
                variant="ghost"
                aria-label="Hide the steps"
                data-palette-close
                @click="emit('close')"
            >
                <X v-if="compact" />
                <PanelLeftClose v-else />
            </Button>
        </div>

        <p
            v-if="after"
            class="bg-primary/5 flex items-center gap-1.5 border-b py-1.5 pr-1.5 pl-3 text-xs"
            data-palette-after
        >
            <span class="min-w-0 flex-1 truncate">
                Next after <b class="font-medium">{{ after.label }}</b>
                <template v-if="after.way"> · {{ after.way }}</template>
            </span>
            <Button
                size="icon-sm"
                variant="ghost"
                class="size-6"
                aria-label="Add it on its own instead"
                @click="emit('forget')"
            >
                <X />
            </Button>
        </p>

        <div class="flex-1 space-y-3 overflow-y-auto p-2">
            <section v-for="group in shown" :key="group.key">
                <h3
                    class="text-muted-foreground mb-1 px-1.5 text-[11px] font-medium tracking-wide uppercase"
                >
                    {{ group.title }}
                </h3>
                <button
                    v-for="type in group.types"
                    :key="type.type"
                    type="button"
                    draggable="true"
                    class="hover:bg-muted/60 flex w-full cursor-grab items-center gap-2 rounded-md px-1.5 text-left transition-colors active:cursor-grabbing"
                    :class="compact ? 'py-2' : 'py-1'"
                    :title="type.description"
                    :data-palette-step="type.type"
                    @dragstart="drag($event, type)"
                    @click="emit('add', type)"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-md"
                        :class="toneOf(type).tile"
                    >
                        <component :is="iconOf(type)" class="size-3.5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm leading-tight">{{
                            type.label
                        }}</span>
                        <!-- Nothing to point with on a phone: what it does, in a line -->
                        <span
                            v-if="compact"
                            class="text-muted-foreground line-clamp-1 text-xs"
                            >{{ type.description }}</span
                        >
                    </span>
                    <Zap
                        v-if="type.trigger"
                        class="size-3 shrink-0 text-emerald-600 dark:text-emerald-400"
                        aria-label="Starts a run"
                    />
                </button>
            </section>
            <p v-if="!shown.length" class="text-muted-foreground px-1 text-sm">
                No step matches.
            </p>
        </div>
    </aside>
</template>
