<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { History, Plus, Radar, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import { Button } from '@/components/ui/button';
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
import {
    ELEMENT_STATUSES,
    LINK_KINDS,
    REQUIREMENT_STATUSES,
    options,
} from '@/features/product/product';
import type {
    Element,
    ElementDetail,
    LinkKind,
    TicketRef,
} from '@/features/product/product';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { formatRelativeTime } from '@/lib/utils';
import {
    destroy,
    link,
    show,
    ticket as linkTicket,
    update,
} from '@/routes/product/elements';
import { destroy as unlink } from '@/routes/product/links';
import { store as storeRequirement } from '@/routes/projects/product/requirements';

// One part of the product, opened beside the tree: what it is and how it
// stands, what it uses or depends on (kept apart from where it sits), what
// a change to it would reach, the requirements and work about it, and its
// history.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    element: Element;
    elements: Element[];
    tickets: TicketRef[];
    project: string;
    canChange: boolean;
}>();

const emit = defineEmits<{ open: [ref: string]; requirement: [ref: string] }>();

const RELOADED = ['elements', 'links', 'requirements', 'coverage', 'requests'];

const detail = ref<ElementDetail | null>(null);

async function load() {
    const response = await fetch(show(props.element.ref_id).url, {
        headers: { Accept: 'application/json' },
    });
    detail.value = response.ok ? await response.json() : null;
}

watch(
    () => [open.value, props.element.ref_id],
    () => open.value && void load(),
    { immediate: true },
);

const saved = () => void load();

const change = (data: Record<string, unknown>) =>
    router.patch(update(props.element.ref_id).url, data as never, {
        preserveScroll: true,
        preserveState: true,
        only: RELOADED,
        onSuccess: saved,
    });

// ---------------------------------------------------------- What it is
const name = ref('');
const summary = ref('');

watch(
    () => [props.element.ref_id, props.element.name, props.element.summary],
    () => {
        name.value = props.element.name;
        summary.value = props.element.summary ?? '';
    },
    { immediate: true },
);

const TOP = '__top__';

// Where it may go: a component, never itself or under it
const under = (ref: string | null, of: string): boolean => {
    for (
        let at = ref;
        at !== null;
        at = props.elements.find((e) => e.ref_id === at)?.parent ?? null
    ) {
        if (at === of) {
            return true;
        }
    }

    return false;
};

const parents = computed(() => [
    { value: TOP, label: 'The top of the tree' },
    ...props.elements
        .filter(
            (e) =>
                e.kind === 'component' &&
                !under(e.ref_id, props.element.ref_id),
        )
        .map((e) => ({ value: e.ref_id, label: `${e.code} ${e.name}` })),
]);

// ---------------------------------------------------------------- Links
const linkKind = ref<LinkKind>('uses');
const linkTo = ref('');

const others = computed(() =>
    props.elements
        .filter((e) => e.ref_id !== props.element.ref_id)
        .sort((a, b) => Number(b.shared) - Number(a.shared))
        .map((e) => ({
            value: e.ref_id,
            label: `${e.shared ? '★ ' : ''}${e.code} ${e.name}`,
        })),
);

function addLink() {
    if (!linkTo.value) {
        return;
    }

    router.post(
        link(props.element.ref_id).url,
        { to: linkTo.value, kind: linkKind.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: RELOADED,
            onSuccess: () => ((linkTo.value = ''), saved()),
        },
    );
}

const dropLink = (id: number) =>
    router.delete(unlink(id).url, {
        preserveScroll: true,
        preserveState: true,
        only: RELOADED,
        onSuccess: saved,
    });

// ----------------------------------------------------------------- Work
const ticketChoice = ref('');

const unlinkedTickets = computed(() =>
    props.tickets
        .filter(
            (t) =>
                !detail.value?.tickets.some(
                    (linked) => linked.number === t.number,
                ),
        )
        .map((t) => ({
            value: String(t.number),
            label: `#${t.number} ${t.title}`,
        })),
);

const setTicket = (number: number, linked: boolean) =>
    router.post(
        linkTicket(props.element.ref_id).url,
        { number, linked },
        {
            preserveScroll: true,
            preserveState: true,
            only: RELOADED,
            onSuccess: () => ((ticketChoice.value = ''), saved()),
        },
    );

// --------------------------------------------------------- Requirements
const requirementTitle = ref('');

function addRequirement() {
    if (!requirementTitle.value.trim()) {
        return;
    }

    router.post(
        storeRequirement(props.project).url,
        { title: requirementTitle.value, element: props.element.ref_id },
        {
            preserveScroll: true,
            preserveState: true,
            only: RELOADED,
            onSuccess: () => ((requirementTitle.value = ''), saved()),
        },
    );
}

// --------------------------------------------------------------- Delete
const removing = ref(false);

const remove = () =>
    router.delete(destroy(props.element.ref_id).url, {
        preserveScroll: true,
        only: RELOADED,
        onSuccess: () => {
            removing.value = false;
            open.value = false;
        },
    });

const HISTORY: Record<string, string> = {
    made: 'Made',
    renamed: 'Renamed',
    status: 'Status',
    summary: 'What it does',
    shared: 'Shared',
    moved: 'Moved',
    linked: 'Linked',
    unlinked: 'Unlinked',
};

// What it was and became, after what happened
const changed = ({ from, to }: ElementDetail['history'][number]) =>
    ' ' + [from && `${from} →`, to].filter(Boolean).join(' ');
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
            data-test="product-element"
        >
            <SheetHeader class="border-b px-5 py-4 text-left">
                <SheetTitle class="flex items-center gap-2">
                    <span class="text-muted-foreground font-mono text-xs">{{
                        element.code
                    }}</span>
                    <span class="truncate">{{ element.name }}</span>
                </SheetTitle>
                <SheetDescription>
                    {{
                        element.kind === 'component'
                            ? 'A part of the product'
                            : 'Something the product can do'
                    }}. Its code stays, whatever changes.
                </SheetDescription>
            </SheetHeader>

            <div class="space-y-6 px-5 py-5">
                <!-- What it is, and how it stands -->
                <section class="grid grid-cols-1 gap-3">
                    <fieldset
                        :disabled="!canChange"
                        class="grid grid-cols-1 gap-3"
                    >
                        <div class="grid grid-cols-1 gap-1.5">
                            <Label for="element-name">Name</Label>
                            <Input
                                id="element-name"
                                v-model="name"
                                maxlength="160"
                                data-test="element-name"
                                @change="name.trim() && change({ name })"
                            />
                        </div>
                        <div class="grid grid-cols-1 gap-1.5">
                            <Label for="element-summary"
                                >What it does today</Label
                            >
                            <textarea
                                id="element-summary"
                                v-model="summary"
                                rows="3"
                                class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                                data-test="element-summary"
                                @change="change({ summary })"
                            />
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div
                                v-if="element.kind === 'capability'"
                                class="grid grid-cols-1 gap-1.5"
                            >
                                <Label>How it stands</Label>
                                <TicketPick
                                    :model-value="element.status"
                                    :options="options(ELEMENT_STATUSES)"
                                    label="How it stands"
                                    data-test="element-status"
                                    @update:model-value="
                                        change({ status: $event })
                                    "
                                />
                            </div>
                            <div class="grid grid-cols-1 gap-1.5">
                                <Label>Under</Label>
                                <TicketPick
                                    :model-value="element.parent ?? TOP"
                                    :options="parents"
                                    label="Under"
                                    @update:model-value="
                                        change({
                                            parent:
                                                $event === TOP ? null : $event,
                                        })
                                    "
                                />
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <Switch
                                :model-value="element.shared"
                                data-test="element-shared"
                                @update:model-value="
                                    change({ shared: !!$event })
                                "
                            />
                            Shared: built once, used from several places
                        </label>
                    </fieldset>
                </section>

                <!-- What it uses or depends on, apart from where it sits -->
                <section
                    class="grid grid-cols-1 gap-2"
                    data-test="element-links"
                >
                    <Label>Uses and depends on</Label>
                    <ul
                        v-if="detail?.links.length"
                        class="divide-y rounded-lg border"
                    >
                        <li
                            v-for="one in detail.links"
                            :key="one.id"
                            class="flex items-center gap-2 px-3 py-1.5 text-sm"
                        >
                            <span
                                class="text-muted-foreground w-24 shrink-0 text-xs"
                                >{{ LINK_KINDS[one.kind] }}</span
                            >
                            <button
                                type="button"
                                class="min-w-0 flex-1 truncate text-left hover:underline"
                                @click="emit('open', one.ref_id)"
                            >
                                <span
                                    class="text-muted-foreground font-mono text-[11px]"
                                    >{{ one.code }}</span
                                >
                                {{ one.name }}
                            </button>
                            <button
                                v-if="canChange"
                                type="button"
                                class="text-muted-foreground hover:text-foreground"
                                :aria-label="`Take away the link to ${one.name}`"
                                @click="dropLink(one.id)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <div
                        v-if="canChange"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <TicketPick
                            v-model="linkKind"
                            :options="options(LINK_KINDS)"
                            label="How"
                            class="w-36"
                            compact
                        />
                        <TicketPick
                            v-model="linkTo"
                            :options="others"
                            label="To"
                            placeholder="Choose a part"
                            class="min-w-0 flex-1"
                            compact
                            data-test="element-link-to"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!linkTo"
                            data-test="element-link-add"
                            @click="addLink"
                        >
                            <Plus /> Link
                        </Button>
                    </div>
                </section>

                <!-- What a change to it would reach -->
                <section
                    v-if="detail"
                    class="grid grid-cols-1 gap-2 rounded-xl border p-3"
                    data-test="element-impact"
                >
                    <Label class="flex items-center gap-1.5"
                        ><Radar class="size-4" /> If it changes</Label
                    >
                    <p
                        v-if="!detail.impact.direct.length"
                        class="text-muted-foreground text-xs"
                    >
                        Nothing uses or depends on it.
                    </p>
                    <template v-else>
                        <p class="text-xs">
                            <span class="font-medium">{{
                                detail.impact.direct.length
                            }}</span>
                            {{
                                detail.impact.direct.length === 1
                                    ? 'part uses'
                                    : 'parts use'
                            }}
                            it directly<template
                                v-if="detail.impact.further.length"
                                >, and
                                <span class="font-medium">{{
                                    detail.impact.further.length
                                }}</span>
                                more through them</template
                            >:
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="one in [
                                    ...detail.impact.direct,
                                    ...detail.impact.further,
                                ]"
                                :key="one.ref_id"
                                type="button"
                                class="hover:bg-accent rounded-full border px-2 py-0.5 text-xs"
                                data-test="impact-part"
                                @click="emit('open', one.ref_id)"
                            >
                                {{ one.code }} {{ one.name
                                }}<span
                                    v-if="one.under"
                                    class="text-muted-foreground"
                                >
                                    · {{ one.under }}</span
                                >
                            </button>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ detail.impact.requirements.length }} requirements
                            still to settle ·
                            {{ detail.impact.open_tickets.length }} open tickets
                        </p>
                    </template>
                </section>

                <!-- Requirements about it -->
                <section v-if="detail" class="grid grid-cols-1 gap-2">
                    <Label>Requirements</Label>
                    <ul
                        v-if="detail.requirements.length"
                        class="divide-y rounded-lg border"
                    >
                        <li
                            v-for="one in detail.requirements"
                            :key="one.ref_id"
                        >
                            <button
                                type="button"
                                class="hover:bg-accent/50 flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm"
                                @click="emit('requirement', one.ref_id)"
                            >
                                <span
                                    class="text-muted-foreground font-mono text-[11px]"
                                    >{{ one.code }}</span
                                >
                                <span class="min-w-0 flex-1 truncate">{{
                                    one.title
                                }}</span>
                                <span
                                    class="rounded-full px-1.5 py-0.5 text-[10px]"
                                    :class="
                                        REQUIREMENT_STATUSES[one.status].tone
                                    "
                                    >{{
                                        REQUIREMENT_STATUSES[one.status].label
                                    }}</span
                                >
                            </button>
                        </li>
                    </ul>
                    <form
                        v-if="canChange"
                        class="flex gap-2"
                        @submit.prevent="addRequirement"
                    >
                        <Input
                            v-model="requirementTitle"
                            class="h-8 text-sm"
                            maxlength="200"
                            placeholder="A requirement about it"
                            data-test="element-requirement"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            type="submit"
                            :disabled="!requirementTitle.trim()"
                        >
                            <Plus /> Add
                        </Button>
                    </form>
                </section>

                <!-- Work on it: the project's tickets -->
                <section v-if="detail" class="grid grid-cols-1 gap-2">
                    <Label>Work on it</Label>
                    <ul
                        v-if="detail.tickets.length"
                        class="divide-y rounded-lg border"
                    >
                        <li
                            v-for="one in detail.tickets"
                            :key="one.number"
                            class="flex items-center gap-2 px-3 py-1.5 text-sm"
                        >
                            <span
                                class="text-muted-foreground font-mono text-[11px]"
                                >#{{ one.number }}</span
                            >
                            <span class="min-w-0 flex-1 truncate">{{
                                one.title
                            }}</span>
                            <span class="text-muted-foreground text-[11px]"
                                >{{ one.type }} ·
                                {{ one.status.replace('_', ' ') }}</span
                            >
                            <button
                                v-if="canChange"
                                type="button"
                                class="text-muted-foreground hover:text-foreground"
                                :aria-label="`Not about this: #${one.number}`"
                                @click="setTicket(one.number, false)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <p
                        v-if="!detail.tickets.length && !unlinkedTickets.length"
                        class="text-muted-foreground text-xs"
                    >
                        No tickets in this project yet: work is filed under
                        Tickets, then linked here.
                    </p>
                    <TicketPick
                        v-if="canChange && unlinkedTickets.length"
                        :model-value="ticketChoice"
                        :options="unlinkedTickets"
                        label="A ticket about it"
                        placeholder="Link a ticket"
                        compact
                        data-test="element-ticket"
                        @update:model-value="setTicket(Number($event), true)"
                    />
                </section>

                <!-- What happened to it -->
                <section
                    v-if="detail?.history.length"
                    class="grid grid-cols-1 gap-2"
                >
                    <Label class="flex items-center gap-1.5"
                        ><History class="size-4" /> History</Label
                    >
                    <ol class="text-muted-foreground grid gap-1 text-xs">
                        <li v-for="(event, at) in detail.history" :key="at">
                            <span class="text-foreground">{{
                                HISTORY[event.what] ?? event.what
                            }}</span>
                            {{ changed(event) }}
                            · {{ event.by ?? 'someone' }},
                            {{ formatRelativeTime(event.at) }}
                        </li>
                    </ol>
                </section>

                <Button
                    v-if="canChange"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="removing = true"
                >
                    <Trash2 /> Take it away
                </Button>
            </div>
        </SheetContent>
    </Sheet>

    <DeleteDialog
        v-model:open="removing"
        :name="`${element.code} ${element.name}`"
        description="Its history and links go with it; its code is not given again. A part that is no longer offered can be marked Retired instead."
        @confirm="remove"
    />
</template>
