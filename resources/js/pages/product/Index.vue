<script setup lang="ts">
import {
    Head,
    Link as InertiaLink,
    router,
    setLayoutProps,
    usePage,
} from '@inertiajs/vue3';
import { Check, ListTree, Minus, Plus, Quote } from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import { computed, ref, watchEffect } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import ElementSheet from '@/features/product/ElementSheet.vue';
import ProductTree from '@/features/product/ProductTree.vue';
import RequirementSheet from '@/features/product/RequirementSheet.vue';
import WorkOut from '@/features/product/WorkOut.vue';
import {
    REQUEST_STATUSES,
    REQUIREMENT_STATUSES,
    options,
} from '@/features/product/product';
import type {
    Coverage,
    Element,
    Link,
    ProductRequest,
    Requirement,
    TicketRef,
} from '@/features/product/product';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { formatRelativeTime } from '@/lib/utils';
import { show as showChat } from '@/routes/chats';
import { update as updateRequest } from '@/routes/product/requests';
import { store as storeElement } from '@/routes/projects/product/elements';
import { store as storeRequest } from '@/routes/projects/product/requests';
import { store as storeRequirement } from '@/routes/projects/product/requirements';
import { index as projectProduct } from '@/routes/projects/product';

const props = defineProps<{
    elements: Element[];
    links: Link[];
    requests: ProductRequest[];
    requirements: Requirement[];
    coverage: Coverage;
    tickets: TicketRef[];
    people: { id: number; name: string }[];
    agents: { ref_id: string; name: string }[];
    can: { change: boolean };
}>();

const page = usePage();
const project = computed(() => page.props.project!);

// Its name in the bar at the top, as every page has
watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            {
                title: 'Product',
                href: projectProduct(project.value.ref_id).url,
            },
        ],
    }),
);

const RELOADED = ['elements', 'links', 'requests', 'requirements', 'coverage'];

// ------------------------------------------------------------ Which part
const tab = useLocalStorage<'tree' | 'requests' | 'requirements' | 'coverage'>(
    'product.tab',
    'tree',
);

const TABS = computed(() => [
    { value: 'tree' as const, label: 'Tree', test: 'product-tab-tree' },
    {
        value: 'requests' as const,
        label: 'Requests',
        count:
            props.requests.filter((one) =>
                ['new', 'analysing', 'clarifying'].includes(one.status),
            ).length || undefined,
        test: 'product-tab-requests',
    },
    {
        value: 'requirements' as const,
        label: 'Requirements',
        test: 'product-tab-requirements',
    },
    {
        value: 'coverage' as const,
        label: 'Coverage',
        count: props.coverage.gaps.length || undefined,
        test: 'product-tab-coverage',
    },
]);

// ------------------------------------------------------ Opened beside it
const openElement = ref<string | null>(null);
const openRequirement = ref<string | null>(null);
const openRequest = ref<string | null>(null);

const element = computed(
    () =>
        props.elements.find((one) => one.ref_id === openElement.value) ?? null,
);
const requirement = computed(
    () =>
        props.requirements.find(
            (one) => one.ref_id === openRequirement.value,
        ) ?? null,
);
const request = computed(
    () =>
        props.requests.find((one) => one.ref_id === openRequest.value) ?? null,
);

const showRequirement = (ref: string) => {
    openElement.value = null;
    openRequirement.value = ref;
};

const showElement = (ref: string) => {
    openRequirement.value = null;
    openElement.value = ref;
};

// ----------------------------------------------------------- A new part
const making = ref(false);
const draft = ref({
    kind: 'component' as Element['kind'],
    name: '',
    parent: '',
    shared: false,
});
const TOP = '__top__';

const parents = computed(() => [
    { value: TOP, label: 'The top of the tree' },
    ...props.elements
        .filter((e) => e.kind === 'component')
        .map((e) => ({ value: e.ref_id, label: `${e.code} ${e.name}` })),
]);

function startPart(
    parent: string | null = null,
    kind: Element['kind'] = 'component',
) {
    draft.value = { kind, name: '', parent: parent ?? TOP, shared: false };
    making.value = true;
}

function makePart() {
    router.post(
        storeElement(project.value.ref_id).url,
        {
            ...draft.value,
            parent: draft.value.parent === TOP ? null : draft.value.parent,
        },
        {
            preserveScroll: true,
            only: RELOADED,
            onSuccess: () => (making.value = false),
        },
    );
}

// ------------------------------------------------------------- Requests
const asked = ref({ original: '', from_name: '' });

function keepRequest() {
    router.post(storeRequest(project.value.ref_id).url, asked.value, {
        preserveScroll: true,
        only: RELOADED,
        onSuccess: () => (asked.value = { original: '', from_name: '' }),
    });
}

const setRequestStatus = (ref: string, status: string) =>
    router.patch(
        updateRequest(ref).url,
        { status },
        { preserveScroll: true, preserveState: true, only: RELOADED },
    );

const fromRequest = ref({ title: '', need: '' });

function requirementFrom(request: ProductRequest) {
    router.post(
        storeRequirement(project.value.ref_id).url,
        { ...fromRequest.value, request: request.ref_id },
        {
            preserveScroll: true,
            preserveState: true,
            only: RELOADED,
            onSuccess: () => (fromRequest.value = { title: '', need: '' }),
        },
    );
}

const requirementsOf = (request: ProductRequest) =>
    props.requirements.filter((one) => one.request?.ref_id === request.ref_id);

// --------------------------------------------------------- Requirements
const shownRequirements = useLocalStorage<'open' | 'all'>(
    'product.requirements',
    'open',
);

const listedRequirements = computed(() =>
    shownRequirements.value === 'all'
        ? props.requirements
        : props.requirements.filter(
              (one) =>
                  !['verified', 'released', 'dropped'].includes(one.status),
          ),
);

const SOURCES: Record<ProductRequest['source'], string> = {
    typed: 'Typed in',
    chat: 'From a chat',
    ticket: 'From a ticket',
};
</script>

<template>
    <Head :title="`Product · ${project.name}`" />

    <div class="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
        <PageHeader
            :icon="ListTree"
            title="Product"
            :description="`What ${project.name} is, what was asked of it, what it must do, and the work behind it.`"
        >
            <Button
                v-if="can.change && tab === 'tree'"
                data-test="product-new-part"
                @click="startPart()"
            >
                <Plus /> New part
            </Button>
        </PageHeader>

        <div class="max-w-full overflow-x-auto">
            <SegmentTabs
                v-model="tab"
                :options="TABS"
                label="Part of the product"
            />
        </div>

        <!-- The tree -->
        <ProductTree
            v-if="tab === 'tree'"
            :elements="elements"
            :can-change="can.change"
            @open="showElement"
            @add="(parent, kind) => startPart(parent, kind)"
        />

        <!-- Requests: what was asked, word for word -->
        <section v-else-if="tab === 'requests'" class="grid grid-cols-1 gap-4">
            <form
                v-if="can.change"
                class="grid grid-cols-1 gap-2 rounded-xl border p-3"
                @submit.prevent="keepRequest"
            >
                <Label for="request-original"
                    >What was asked, as it was asked</Label
                >
                <textarea
                    id="request-original"
                    v-model="asked.original"
                    rows="3"
                    class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                    placeholder="“Make the colour picking better.”"
                    data-test="request-original"
                />
                <div class="flex flex-wrap gap-2">
                    <Input
                        v-model="asked.from_name"
                        class="h-9 w-56"
                        placeholder="Who asked"
                        maxlength="160"
                    />
                    <Button
                        type="submit"
                        :disabled="!asked.original.trim()"
                        data-test="request-keep"
                        >Keep it</Button
                    >
                </div>
                <p class="text-muted-foreground text-xs">
                    Kept word for word, never changed: what it means goes in the
                    requirements made from it. A customer's chat message or a
                    ticket can be kept as one too.
                </p>
            </form>

            <ul class="divide-y rounded-xl border" data-test="product-requests">
                <li v-for="one in requests" :key="one.ref_id">
                    <button
                        type="button"
                        class="hover:bg-accent/50 flex w-full items-start gap-3 px-4 py-3 text-left"
                        data-test="product-request"
                        @click="openRequest = one.ref_id"
                    >
                        <Quote
                            class="text-muted-foreground mt-0.5 size-4 shrink-0"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="line-clamp-2 text-sm">{{
                                one.original
                            }}</span>
                            <span class="text-muted-foreground block text-xs">
                                {{ one.code }} · {{ SOURCES[one.source]
                                }}<template v-if="one.from_name"
                                    >, {{ one.from_name }}</template
                                >
                                · {{ formatRelativeTime(one.created_at) }} ·
                                {{ one.requirements }}
                                {{
                                    one.requirements === 1
                                        ? 'requirement'
                                        : 'requirements'
                                }}
                            </span>
                        </span>
                        <span class="text-muted-foreground shrink-0 text-xs">{{
                            REQUEST_STATUSES[one.status]
                        }}</span>
                    </button>
                </li>
                <li
                    v-if="!requests.length"
                    class="text-muted-foreground px-4 py-8 text-center text-sm"
                >
                    No requests kept yet.
                </li>
            </ul>
        </section>

        <!-- Requirements -->
        <section
            v-else-if="tab === 'requirements'"
            class="grid grid-cols-1 gap-3"
        >
            <SegmentTabs
                v-model="shownRequirements"
                :options="[
                    { value: 'open', label: 'Still open' },
                    { value: 'all', label: 'All' },
                ]"
                label="Which"
            />
            <ul
                class="divide-y rounded-xl border"
                data-test="product-requirements"
            >
                <li v-for="one in listedRequirements" :key="one.ref_id">
                    <button
                        type="button"
                        class="hover:bg-accent/50 flex w-full items-center gap-3 px-4 py-2.5 text-left"
                        data-test="product-requirement"
                        @click="openRequirement = one.ref_id"
                    >
                        <span
                            class="text-muted-foreground w-14 shrink-0 font-mono text-[11px]"
                            >{{ one.code }}</span
                        >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm">{{
                                one.title
                            }}</span>
                            <span
                                class="text-muted-foreground block truncate text-xs"
                            >
                                {{
                                    one.elements
                                        .map((e) => e.name)
                                        .join(', ') ||
                                    'No part of the product yet'
                                }}
                                <template v-if="one.criteria.length">
                                    ·
                                    {{
                                        one.criteria.filter((c) => c.met)
                                            .length
                                    }}/{{ one.criteria.length }} met</template
                                >
                            </span>
                        </span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px]"
                            :class="REQUIREMENT_STATUSES[one.status].tone"
                            >{{ REQUIREMENT_STATUSES[one.status].label }}</span
                        >
                    </button>
                </li>
                <li
                    v-if="!listedRequirements.length"
                    class="text-muted-foreground px-4 py-8 text-center text-sm"
                >
                    None here. Requirements are made from a request, or from a
                    part of the tree.
                </li>
            </ul>
        </section>

        <!-- Coverage: how far each capability's trail goes -->
        <section
            v-else
            class="grid grid-cols-1 gap-4"
            data-test="product-coverage"
        >
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-muted-foreground text-xs">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">
                                Capability
                            </th>
                            <th class="px-2 py-2 font-medium">Required</th>
                            <th class="px-2 py-2 font-medium">Work</th>
                            <th class="px-2 py-2 font-medium">Verified</th>
                            <th class="px-2 py-2 font-medium">Live</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in coverage.rows"
                            :key="row.ref_id"
                            class="hover:bg-accent/40 cursor-pointer"
                            @click="showElement(row.ref_id)"
                        >
                            <td class="px-3 py-2">
                                <span
                                    class="text-muted-foreground font-mono text-[11px]"
                                    >{{ row.code }}</span
                                >
                                {{ row.name }}
                            </td>
                            <td
                                v-for="column in [
                                    'required',
                                    'worked',
                                    'verified',
                                    'live',
                                ] as const"
                                :key="column"
                                class="px-2 py-2 text-center"
                            >
                                <Check
                                    v-if="row[column]"
                                    class="mx-auto size-4 text-emerald-600"
                                    :aria-label="`${column}: yes`"
                                />
                                <Minus
                                    v-else
                                    class="text-muted-foreground/50 mx-auto size-4"
                                    :aria-label="`${column}: no`"
                                />
                            </td>
                        </tr>
                        <tr v-if="!coverage.rows.length">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-3 py-6 text-center"
                            >
                                No capabilities yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="coverage.gaps.length" class="grid grid-cols-1 gap-2">
                <h3 class="text-sm font-medium">Gaps in the trail</h3>
                <ul class="divide-y rounded-xl border" data-test="product-gaps">
                    <li v-for="(gap, at) in coverage.gaps" :key="at">
                        <button
                            type="button"
                            class="hover:bg-accent/50 flex w-full items-center gap-2 px-4 py-2 text-left text-sm"
                            @click="showElement(gap.ref_id)"
                        >
                            <span
                                class="text-muted-foreground font-mono text-[11px]"
                                >{{ gap.code }}</span
                            >
                            <span class="truncate">{{ gap.name }}</span>
                            <span
                                class="text-muted-foreground ml-auto shrink-0 text-xs"
                                >{{ gap.about }}</span
                            >
                        </button>
                    </li>
                </ul>
            </div>
        </section>

        <ElementSheet
            v-if="element"
            :open="openElement !== null"
            :element="element"
            :elements="elements"
            :tickets="tickets"
            :project="project.ref_id"
            :can-change="can.change"
            @update:open="$event || (openElement = null)"
            @open="showElement"
            @requirement="showRequirement"
        />

        <RequirementSheet
            v-if="requirement"
            :open="openRequirement !== null"
            :requirement="requirement"
            :elements="elements"
            :tickets="tickets"
            :people="people"
            :project="project.ref_id"
            :can-change="can.change"
            @update:open="$event || (openRequirement = null)"
            @element="showElement"
            @requirement="showRequirement"
        />

        <!-- One request: its words as they came, and what was made of them -->
        <Sheet
            v-if="request"
            :open="openRequest !== null"
            @update:open="$event || (openRequest = null)"
        >
            <SheetContent
                class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
                data-test="request-sheet"
            >
                <SheetHeader class="border-b px-5 py-4 text-left">
                    <SheetTitle>Request {{ request.code }}</SheetTitle>
                    <SheetDescription>
                        {{ SOURCES[request.source]
                        }}<template v-if="request.from_name"
                            >, from {{ request.from_name }}</template
                        >, {{ formatRelativeTime(request.created_at) }}. Kept
                        word for word.
                        <InertiaLink
                            v-if="request.chat"
                            :href="showChat(request.chat).url"
                            class="text-foreground underline underline-offset-2"
                            data-test="request-chat"
                            >Open the chat</InertiaLink
                        ><template v-else-if="request.ticket"
                            >Ticket #{{ request.ticket }}.</template
                        >
                    </SheetDescription>
                </SheetHeader>
                <div class="min-w-0 space-y-5 px-5 py-5">
                    <blockquote
                        class="bg-muted/40 rounded-lg border-l-4 px-4 py-3 text-sm whitespace-pre-wrap"
                        data-test="request-words"
                    >
                        {{ request.original }}
                    </blockquote>
                    <div class="grid grid-cols-1 gap-1.5">
                        <Label>How far it has got</Label>
                        <TicketPick
                            :model-value="request.status"
                            :options="options(REQUEST_STATUSES)"
                            label="How far it has got"
                            :disabled="!can.change"
                            @update:model-value="
                                setRequestStatus(request.ref_id, $event)
                            "
                        />
                    </div>
                    <div class="grid grid-cols-1 gap-2">
                        <Label>Requirements made from it</Label>
                        <ul
                            v-if="requirementsOf(request).length"
                            class="divide-y rounded-lg border"
                        >
                            <li
                                v-for="one in requirementsOf(request)"
                                :key="one.ref_id"
                            >
                                <button
                                    type="button"
                                    class="hover:bg-accent/50 flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm"
                                    @click="
                                        ((openRequest = null),
                                        showRequirement(one.ref_id))
                                    "
                                >
                                    <span
                                        class="text-muted-foreground font-mono text-[11px]"
                                        >{{ one.code }}</span
                                    >
                                    <span
                                        class="min-w-0 flex-1 truncate"
                                        :title="one.title"
                                        >{{ one.title }}</span
                                    >
                                </button>
                            </li>
                        </ul>
                        <WorkOut
                            v-if="can.change"
                            :key="request.ref_id"
                            :request="request.ref_id"
                            :project="project.ref_id"
                            :agents="agents"
                            :elements="elements"
                            :reloaded="RELOADED"
                        />
                        <form
                            v-if="can.change"
                            class="grid grid-cols-1 gap-2 rounded-lg border p-3"
                            @submit.prevent="requirementFrom(request)"
                        >
                            <Input
                                v-model="fromRequest.title"
                                maxlength="200"
                                placeholder="What the product must do"
                                data-test="request-requirement-title"
                            />
                            <Input
                                v-model="fromRequest.need"
                                placeholder="The need behind it (optional)"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                class="justify-self-start"
                                :disabled="!fromRequest.title.trim()"
                                data-test="request-requirement-add"
                            >
                                <Plus /> Make a requirement of it
                            </Button>
                        </form>
                    </div>
                </div>
            </SheetContent>
        </Sheet>

        <!-- A new part of the tree -->
        <Dialog v-model:open="making">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New part</DialogTitle>
                    <DialogDescription
                        >A component is an area of the product; a capability,
                        something it can do.</DialogDescription
                    >
                </DialogHeader>
                <form
                    id="part-form"
                    class="grid grid-cols-1 gap-3"
                    @submit.prevent="makePart"
                >
                    <SegmentTabs
                        v-model="draft.kind"
                        :options="[
                            {
                                value: 'component',
                                label: 'Component',
                                test: 'part-component',
                            },
                            {
                                value: 'capability',
                                label: 'Capability',
                                test: 'part-capability',
                            },
                        ]"
                        label="Kind"
                    />
                    <Input
                        v-model="draft.name"
                        maxlength="160"
                        placeholder="e.g. Board, or Change border colour"
                        data-test="part-name"
                    />
                    <div class="grid grid-cols-1 gap-1.5">
                        <Label>Under</Label>
                        <TicketPick
                            v-model="draft.parent"
                            :options="parents"
                            label="Under"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <Switch v-model="draft.shared" /> Shared: built once,
                        used from several places
                    </label>
                </form>
                <DialogFooter>
                    <Button variant="ghost" @click="making = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="part-form"
                        :disabled="!draft.name.trim()"
                        data-test="part-make"
                        >Make it</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
