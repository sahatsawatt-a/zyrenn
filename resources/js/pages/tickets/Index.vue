<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Columns3,
    List,
    Plus,
    Search,
    Ticket as TicketIcon,
} from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import MediaViewer from '@/components/media/MediaViewer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import TicketBoard from '@/features/tickets/components/TicketBoard.vue';
import TicketDialog from '@/features/tickets/components/TicketDialog.vue';
import TicketList from '@/features/tickets/components/TicketList.vue';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { TYPES, UNITS } from '@/features/tickets/tickets';
import type {
    Ticket,
    TicketDraft,
    TicketPerson,
    TicketStatus,
    TicketType,
} from '@/features/tickets/tickets';
import { canChange } from '@/lib/projects';
import { chat, destroy, store, update } from '@/routes/projects/tickets';

const props = defineProps<{
    tickets: Ticket[];
    // Everyone in the project: who a ticket can be given to
    people: TicketPerson[];
}>();

const page = usePage();
const project = computed(() => page.props.project!);
const changes = computed(() => canChange());

// ------------------------------------------------------------------ Looking
// A list or a board: how a person likes to read them, so it is remembered
const view = useLocalStorage<'list' | 'board'>('tickets.view', 'list');

const kind = ref<'all' | TicketType>('all');
const unit = ref('');
const search = ref('');

const kinds = computed(() => {
    const left = (type?: TicketType) =>
        props.tickets.filter(
            (ticket) =>
                (!type || ticket.type === type) && ticket.closed_at === null,
        ).length;

    return [
        { value: 'all' as const, label: 'All', count: left() },
        { value: 'bug' as const, label: 'Bugs', count: left('bug') },
        {
            value: 'feature' as const,
            label: 'Features',
            count: left('feature'),
        },
    ];
});

// The parts tickets here are about already
const unitsUsed = computed(() =>
    [
        ...new Set(props.tickets.map((ticket) => ticket.unit).filter(Boolean)),
    ].sort((a, b) => a.localeCompare(b)),
);

// "Every part" is a choice like the rest, under a name no part would have
const EVERY = '__every__';

const units = computed(() => [
    { value: EVERY, label: 'Every part' },
    ...unitsUsed.value.map((one) => ({ value: one, label: one })),
]);

const shown = computed(() => {
    const words = search.value.trim().toLowerCase();

    return props.tickets.filter(
        (ticket) =>
            (kind.value === 'all' || ticket.type === kind.value) &&
            (!unit.value || ticket.unit === unit.value) &&
            (!words ||
                `#${ticket.number} ${ticket.title} ${ticket.body} ${ticket.unit}`
                    .toLowerCase()
                    .includes(words)),
    );
});

// A unit nobody uses any more is not a filter to be stuck on
watch(unitsUsed, (units) => {
    if (unit.value && !units.includes(unit.value)) {
        unit.value = '';
    }
});

// ------------------------------------------------------------------ The form
const editing = ref(false);
const openRef = ref<string | null>(null);
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const openTicket = computed(
    () =>
        props.tickets.find((ticket) => ticket.ref_id === openRef.value) ?? null,
);

// The page's address names the ticket that is open, so it can be passed on
const address = (number: number | null) => {
    const url = new URL(window.location.href);

    if (number === null) {
        url.searchParams.delete('ticket');
    } else {
        url.searchParams.set('ticket', String(number));
    }

    window.history.replaceState(window.history.state, '', url);
};

const start = () => {
    openRef.value = null;
    errors.value = {};
    editing.value = true;
};

const show = (ticket: Ticket) => {
    openRef.value = ticket.ref_id;
    errors.value = {};
    editing.value = true;
    address(ticket.number);
};

watch(editing, (now) => {
    if (!now) {
        address(null);
    }
});

onMounted(() => {
    const asked = Number(
        new URL(window.location.href).searchParams.get('ticket'),
    );
    const ticket = props.tickets.find((one) => one.number === asked);

    if (ticket) {
        show(ticket);
    }
});

const sending = {
    preserveScroll: true,
    onStart: () => (busy.value = true),
    onFinish: () => (busy.value = false),
    onError: (problems: Record<string, string>) => (errors.value = problems),
};

const save = (draft: TicketDraft) => {
    const ticket = openTicket.value;

    if (ticket) {
        router.patch(update([project.value.ref_id, ticket.ref_id]).url, draft, {
            ...sending,
            onSuccess: () => (editing.value = false),
        });

        return;
    }

    // A new ticket is open: its status is not the form's to say
    const { status: _status, ...filed } = draft;

    router.post(store(project.value.ref_id).url, filed, {
        ...sending,
        onSuccess: () => (editing.value = false),
    });
};

const setStatus = (ticket: Ticket, status: TicketStatus) =>
    router.patch(
        update([project.value.ref_id, ticket.ref_id]).url,
        { status },
        { preserveScroll: true },
    );

const discuss = () => {
    const ticket = openTicket.value;

    if (ticket) {
        router.post(chat([project.value.ref_id, ticket.ref_id]).url);
    }
};

// ------------------------------------------------------------------ Deleting
const deleting = ref(false);

const remove = () => {
    const ticket = openTicket.value;

    if (!ticket) {
        return;
    }

    router.delete(destroy([project.value.ref_id, ticket.ref_id]).url, {
        ...sending,
        onSuccess: () => {
            deleting.value = false;
            editing.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`Tickets · ${project.name}`" />

    <div class="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
        <PageHeader
            :icon="TicketIcon"
            title="Tickets"
            :description="`What ${project.name} has been asked to fix, or to add.`"
        >
            <Button v-if="changes" data-test="new-ticket" @click="start">
                <Plus /> New ticket
            </Button>
        </PageHeader>

        <template v-if="tickets.length">
            <div class="flex flex-wrap items-center gap-2">
                <div class="ticket-tabs" role="tablist">
                    <button
                        v-for="option in kinds"
                        :key="option.value"
                        type="button"
                        role="tab"
                        :aria-selected="kind === option.value"
                        :class="{ 'is-on': kind === option.value }"
                        :data-test="`tickets-${option.value}`"
                        @click="kind = option.value"
                    >
                        <component
                            :is="TYPES[option.value].icon"
                            v-if="option.value !== 'all'"
                            class="size-3.5"
                            :class="TYPES[option.value].tone"
                        />
                        {{ option.label }}
                        <span class="text-muted-foreground tabular-nums">{{
                            option.count
                        }}</span>
                    </button>
                </div>

                <TicketPick
                    v-if="unitsUsed.length > 1"
                    :model-value="unit || EVERY"
                    :options="units"
                    label="Part"
                    class="w-auto max-w-40"
                    data-test="tickets-unit"
                    @update:model-value="unit = $event === EVERY ? '' : $event"
                />

                <div class="relative min-w-32 flex-1">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="pl-8"
                        placeholder="Search tickets"
                        data-test="tickets-search"
                    />
                </div>

                <div class="ticket-tabs" role="tablist" aria-label="View">
                    <button
                        type="button"
                        role="tab"
                        title="As a list"
                        :aria-selected="view === 'list'"
                        :class="{ 'is-on': view === 'list' }"
                        data-test="tickets-as-list"
                        @click="view = 'list'"
                    >
                        <List class="size-4" />
                    </button>
                    <button
                        type="button"
                        role="tab"
                        title="As a board"
                        :aria-selected="view === 'board'"
                        :class="{ 'is-on': view === 'board' }"
                        data-test="tickets-as-board"
                        @click="view = 'board'"
                    >
                        <Columns3 class="size-4" />
                    </button>
                </div>
            </div>

            <p
                v-if="!shown.length"
                class="text-muted-foreground py-10 text-center text-sm"
            >
                No ticket matches that.
            </p>
            <TicketBoard
                v-else-if="view === 'board'"
                :tickets="shown"
                :can-change="changes"
                @open="show"
                @status="setStatus"
            />
            <TicketList
                v-else
                :tickets="shown"
                :can-change="changes"
                @open="show"
                @status="setStatus"
            />
        </template>

        <EmptyState v-else :icon="TicketIcon" title="No tickets yet">
            <p class="text-muted-foreground text-sm">
                A bug to fix or a feature to add: file it here, and follow it
                until it is done.
            </p>
        </EmptyState>

        <TicketDialog
            v-model:open="editing"
            :ticket="openTicket"
            :type="kind === 'all' ? 'bug' : kind"
            :people="people"
            :units="[...new Set([...unitsUsed, ...UNITS])]"
            :can-change="changes"
            :errors="errors"
            :busy="busy"
            @save="save"
            @remove="deleting = true"
            @discuss="discuss"
        />

        <DeleteDialog
            v-if="openTicket"
            v-model:open="deleting"
            :name="`#${openTicket.number} ${openTicket.title}`"
            description="The ticket is deleted for everyone in the project. A chat it was discussed in stays."
            :busy="busy"
            @confirm="remove"
        />

        <MediaViewer />
    </div>
</template>

<style scoped>
.ticket-tabs {
    display: inline-flex;
    padding: 2px;
    gap: 2px;
    background-color: var(--muted);
    border-radius: var(--radius-md);
}
.ticket-tabs button {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    height: 2rem;
    padding: 0 0.625rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--muted-foreground);
    border-radius: calc(var(--radius-md) - 2px);
    cursor: pointer;
}
.ticket-tabs button.is-on {
    color: var(--foreground);
    background-color: var(--background);
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.12);
}
</style>
