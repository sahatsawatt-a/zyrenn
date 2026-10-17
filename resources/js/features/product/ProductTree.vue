<script setup lang="ts">
import { Box, Bug, ChevronRight, Plus, Share2, Sparkle } from '@lucide/vue';
import { computed, ref } from 'vue';
import { ELEMENT_STATUSES } from '@/features/product/product';
import type { Element, ElementStatus } from '@/features/product/product';
import Tip from '@/components/common/Tip.vue';

// The product as a tree of any depth: components, the capabilities under
// them, and how each stands -- a component by its capabilities counted, not
// as a percentage. Shared parts are marked, and also listed on their own.

const props = defineProps<{ elements: Element[]; canChange: boolean }>();

const emit = defineEmits<{
    open: [ref: string];
    add: [parent: string | null, kind: Element['kind']];
}>();

const shut = ref(new Set<string>());

const childrenOf = computed(() => {
    const map = new Map<string | null, Element[]>();

    for (const element of props.elements) {
        map.set(element.parent, [...(map.get(element.parent) ?? []), element]);
    }

    return map;
});

// Drawn in order, each with how deep it is, what is shut left out
const rows = computed(() => {
    const out: {
        element: Element;
        depth: number;
        open: boolean;
        has: boolean;
    }[] = [];
    const walk = (parent: string | null, depth: number) => {
        for (const element of childrenOf.value.get(parent) ?? []) {
            const has = (childrenOf.value.get(element.ref_id) ?? []).length > 0;
            const open = !shut.value.has(element.ref_id);

            out.push({ element, depth, open, has });

            if (has && open) {
                walk(element.ref_id, depth + 1);
            }
        }
    };

    walk(null, 0);

    return out;
});

const toggle = (ref: string) => {
    const next = new Set(shut.value);
    if (next.has(ref)) {
        next.delete(ref);
    } else {
        next.add(ref);
    }
    shut.value = next;
};

const counted = (counts: Element['counts']) =>
    (Object.keys(ELEMENT_STATUSES) as ElementStatus[])
        .filter((status) => (counts?.[status] ?? 0) > 0)
        .map((status) => ({ status, n: counts![status]! }));

const shared = computed(() =>
    props.elements.filter((element) => element.shared),
);
</script>

<template>
    <div class="grid gap-4">
        <ul class="divide-y rounded-xl border" data-test="product-tree">
            <li
                v-for="{ element, depth, open, has } in rows"
                :key="element.ref_id"
                class="group flex items-center gap-1.5 py-1.5 pr-2"
                :style="{ paddingLeft: `${0.5 + depth * 1.25}rem` }"
                :data-element="element.code"
            >
                <button
                    type="button"
                    class="text-muted-foreground flex size-5 shrink-0 items-center justify-center rounded"
                    :class="{ invisible: !has }"
                    :aria-label="
                        open ? `Fold ${element.name}` : `Open ${element.name}`
                    "
                    :aria-expanded="open"
                    @click="toggle(element.ref_id)"
                >
                    <ChevronRight
                        class="size-4 transition-transform"
                        :class="{ 'rotate-90': open }"
                    />
                </button>
                <button
                    type="button"
                    class="hover:bg-accent/60 flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1 text-left"
                    data-test="product-node"
                    @click="emit('open', element.ref_id)"
                >
                    <Box
                        v-if="element.kind === 'component'"
                        class="text-muted-foreground size-4 shrink-0"
                    />
                    <Sparkle v-else class="size-3.5 shrink-0 text-violet-500" />
                    <span
                        class="text-muted-foreground shrink-0 font-mono text-[11px]"
                        >{{ element.code }}</span
                    >
                    <span
                        class="truncate text-sm"
                        :class="{ 'font-medium': element.kind === 'component' }"
                        >{{ element.name }}</span
                    >
                    <Share2
                        v-if="element.shared"
                        class="size-3.5 shrink-0 text-sky-600"
                        aria-label="Shared"
                    />
                    <span
                        class="ml-auto flex shrink-0 items-center gap-2 text-xs"
                    >
                        <Tip
                            v-if="element.open_bugs"
                            :text="`${element.open_bugs} open bug tickets`"
                        >
                            <span
                                class="flex items-center gap-0.5 text-red-600 dark:text-red-400"
                            >
                                <Bug class="size-3" />{{ element.open_bugs }}
                            </span>
                        </Tip>
                        <!-- A component: its capabilities counted by how they stand -->
                        <template v-if="element.kind === 'component'">
                            <Tip
                                v-for="{ status, n } in counted(element.counts)"
                                :key="status"
                                :text="`${n} ${ELEMENT_STATUSES[status].label}`"
                            >
                                <span class="hidden items-center gap-1 sm:flex">
                                    <span
                                        class="size-2 rounded-full"
                                        :class="ELEMENT_STATUSES[status].dot"
                                    />
                                    <span class="tabular-nums">{{ n }}</span>
                                </span>
                            </Tip>
                        </template>
                        <span
                            v-else
                            class="flex items-center gap-1.5"
                            data-test="product-status"
                        >
                            <span
                                class="size-2 rounded-full"
                                :class="ELEMENT_STATUSES[element.status].dot"
                            />
                            <span
                                class="text-muted-foreground hidden sm:inline"
                                >{{
                                    ELEMENT_STATUSES[element.status].label
                                }}</span
                            >
                        </span>
                    </span>
                </button>
                <template v-if="canChange && element.kind === 'component'">
                    <Tip :text="`A capability under ${element.name}`">
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground rounded p-1 opacity-60 group-hover:opacity-100"
                            :aria-label="`A capability under ${element.name}`"
                            :data-test="`product-add-${element.code}`"
                            @click="emit('add', element.ref_id, 'capability')"
                        >
                            <Plus class="size-3.5" />
                        </button>
                    </Tip>
                </template>
            </li>
            <li
                v-if="!rows.length"
                class="text-muted-foreground px-4 py-8 text-center text-sm"
            >
                Nothing in the tree yet: start with the main parts of the
                product.
            </li>
        </ul>

        <section
            v-if="shared.length"
            class="grid gap-2"
            data-test="product-shared"
        >
            <h3
                class="text-muted-foreground flex items-center gap-1.5 text-xs font-medium tracking-wide uppercase"
            >
                <Share2 class="size-3.5" /> Shared, built once
            </h3>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="element in shared"
                    :key="element.ref_id"
                    type="button"
                    class="hover:bg-accent flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-xs"
                    @click="emit('open', element.ref_id)"
                >
                    <span class="text-muted-foreground font-mono text-[10px]">{{
                        element.code
                    }}</span>
                    {{ element.name }}
                </button>
            </div>
        </section>
    </div>
</template>
