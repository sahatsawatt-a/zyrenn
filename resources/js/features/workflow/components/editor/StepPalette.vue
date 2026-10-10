<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { iconOf, toneOf } from '@/features/workflow/steps';
import type { NodeType } from '@/features/workflow/types';

// The kinds of step, by group: dragged onto the canvas, or clicked to add
// one beside the last.

const props = defineProps<{
    types: NodeType[];
    groups: Record<string, string>;
}>();

const emit = defineEmits<{ add: [type: NodeType] }>();

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
        class="bg-background flex w-60 shrink-0 flex-col border-r"
        data-step-palette
    >
        <div class="border-b p-3">
            <div class="relative">
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
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto p-3">
            <section v-for="group in shown" :key="group.key">
                <h3
                    class="text-muted-foreground mb-1.5 px-1 text-[11px] font-medium tracking-wide uppercase"
                >
                    {{ group.title }}
                </h3>
                <button
                    v-for="type in group.types"
                    :key="type.type"
                    type="button"
                    draggable="true"
                    class="hover:bg-muted/60 group flex w-full cursor-grab items-start gap-2.5 rounded-lg p-2 text-left transition-colors active:cursor-grabbing"
                    :title="type.description"
                    :data-palette-step="type.type"
                    @dragstart="drag($event, type)"
                    @click="emit('add', type)"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-md"
                        :class="toneOf(type).tile"
                    >
                        <component :is="iconOf(type)" class="size-3.5" />
                    </span>
                    <span class="min-w-0">
                        <span
                            class="flex items-center gap-1.5 text-sm font-medium"
                            >{{ type.label }}
                            <span
                                v-if="type.trigger"
                                class="rounded bg-emerald-500/10 px-1 text-[10px] font-medium text-emerald-700 dark:text-emerald-400"
                                >starts a run</span
                            ></span
                        >
                        <span
                            class="text-muted-foreground line-clamp-2 block text-xs"
                            >{{ type.description }}</span
                        >
                    </span>
                </button>
            </section>
            <p v-if="!shown.length" class="text-muted-foreground px-1 text-sm">
                No step matches.
            </p>
        </div>

        <p class="text-muted-foreground border-t p-3 text-xs">
            Drag a step onto the canvas, or click to add it. More kinds come
            with chat, tables, AI and the outside world.
        </p>
    </aside>
</template>
