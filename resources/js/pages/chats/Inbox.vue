<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import {
    Inbox,
    MessageCircle,
    Radio,
    Search,
    UserRound,
    UserRoundCheck,
    Users,
    Workflow,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref, watchEffect } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { inboxChanges } from '@/composables/useUnreadChats';
import RoomList from '@/features/chat/components/RoomList.vue';
import { SERVICES, STATUSES } from '@/features/chat/desk';
import type { Desk, DeskPerson, DeskStatus } from '@/features/chat/desk';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { inbox } from '@/routes/projects';
import { index as workflowsIndex } from '@/routes/projects/workflows';

type Room = Desk & {
    ref_id: string;
    title: string;
    updated_at: string;
    unread: number;
    last: string | null;
    picture: string | null;
    linked: string | null;
    // Their other chats' services
    also: string[];
};

type Filters = {
    status: DeskStatus | 'all';
    channel: string | null;
    assigned: string;
    q: string;
};

const props = defineProps<{
    rooms: Room[];
    // There are more than are listed: narrow them down
    more: boolean;
    // How many stand where, of those the other filters leave
    counts: Partial<Record<DeskStatus, number>>;
    filters: Filters;
    // The services its chats came by
    channels: string[];
    // Who a chat can be given to
    people: DeskPerson[];
    // Whether the project has a workflow at all: chats come in through one
    workflows: boolean;
}>();

const page = usePage();
const project = computed(() => page.props.project!);

// Its name in the bar at the top, as every page has
watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            { title: 'Inbox', href: inbox(project.value.ref_id).url },
        ],
    }),
);
const me = page.props.auth.user.id;

const LISTED = ['rooms', 'more', 'counts', 'channels'];

// What is chosen, kept here as it is chosen: a second choice made before the first has been
// answered is added to it, where one read from what the page was last given would undo it
const chosen = reactive<Filters>({ ...props.filters });

// Looked at another way: the address says how, so it can be come back to
const look = (changes: Partial<Filters>) => {
    const next = Object.assign(chosen, changes);

    router.get(
        inbox(project.value.ref_id).url,
        {
            ...(next.status !== 'open' ? { status: next.status } : {}),
            ...(next.channel ? { channel: next.channel } : {}),
            ...(next.assigned !== 'anyone' ? { assigned: next.assigned } : {}),
            ...(next.q ? { q: next.q } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: LISTED,
        },
    );
};

const held = computed(() =>
    Object.values(props.counts).reduce((sum, count) => sum + (count ?? 0), 0),
);

const tabs = computed(() => [
    ...(Object.keys(STATUSES) as DeskStatus[]).map((value) => ({
        value,
        label: STATUSES[value].label,
        title: STATUSES[value].hint,
        icon: STATUSES[value].icon,
        tone: STATUSES[value].tone,
        count: props.counts[value] ?? 0,
        test: `inbox-${value}`,
    })),
    {
        value: 'all' as const,
        label: 'All',
        count: held.value,
        test: 'inbox-all',
    },
]);

const EVERY = 'every';

const channels = computed(() => [
    { value: EVERY, label: 'Every channel', icon: Radio },
    ...props.channels.map((service) => ({
        value: service,
        label: SERVICES[service] ?? service,
        icon: MessageCircle,
    })),
]);

const assignees = computed(() => [
    { value: 'anyone', label: 'Anyone', icon: Users },
    { value: 'me', label: 'Mine', icon: UserRoundCheck },
    { value: 'nobody', label: 'Unassigned', icon: UserRound },
    ...props.people
        .filter((person) => person.id !== me)
        .map((person) => ({
            value: String(person.id),
            label: person.name,
            icon: UserRoundCheck,
        })),
]);

const search = ref(chosen.q);

watchDebounced(search, (q) => look({ q: q.trim() }), { debounce: 300 });

// Something was said in one, or one was moved: the list is asked for again
watchDebounced(inboxChanges, () => router.reload({ only: LISTED }), {
    debounce: 400,
});

const listed = computed(() =>
    props.rooms.map((room) => ({
        ...room,
        linked: room.linked ? (SERVICES[room.linked] ?? room.linked) : null,
        also: room.also.map((service) => SERVICES[service] ?? service),
        detail: STATUSES[room.status].hint,
    })),
);

// Nothing has ever come in, as against nothing matching what is asked for
const narrowed = computed(
    () =>
        chosen.channel !== null ||
        chosen.assigned !== 'anyone' ||
        chosen.q !== '',
);
</script>

<template>
    <Head :title="`Inbox · ${project.name}`" />

    <div class="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
        <PageHeader
            :icon="Inbox"
            title="Inbox"
            :description="`${project.name}'s chats with customers, from every service they write on.`"
        />

        <template v-if="held || narrowed">
            <div class="flex flex-wrap items-center gap-2">
                <div class="max-w-full overflow-x-auto">
                    <SegmentTabs
                        :model-value="chosen.status"
                        :options="tabs"
                        label="Where they stand"
                        @update:model-value="look({ status: $event })"
                    />
                </div>

                <TicketPick
                    v-if="channels.length > 2"
                    :model-value="chosen.channel ?? EVERY"
                    :options="channels"
                    label="Channel"
                    class="w-auto max-w-44"
                    data-test="inbox-channel"
                    @update:model-value="
                        look({ channel: $event === EVERY ? null : $event })
                    "
                />
                <TicketPick
                    :model-value="chosen.assigned"
                    :options="assignees"
                    label="Assigned to"
                    class="w-auto max-w-44"
                    data-test="inbox-assigned"
                    @update:model-value="look({ assigned: $event })"
                />

                <div class="relative min-w-32 flex-1">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="pl-8"
                        placeholder="Search by name"
                        data-test="inbox-search"
                    />
                </div>
            </div>

            <RoomList
                v-if="listed.length"
                :icon="MessageCircle"
                :rooms="listed"
            />
            <p
                v-else
                class="text-muted-foreground py-10 text-center text-sm"
                data-test="inbox-none"
            >
                {{
                    chosen.status === 'open' && !narrowed
                        ? 'Nothing is waiting for an answer.'
                        : 'No chat matches that.'
                }}
            </p>
            <p v-if="more" class="text-muted-foreground text-center text-xs">
                Only the latest {{ rooms.length }} are listed: narrow them down
                to find an older one.
            </p>
        </template>

        <EmptyState v-else :icon="Inbox" title="No chats with customers yet">
            <p class="text-muted-foreground max-w-md text-sm">
                A workflow brings them in: each person who writes to your LINE
                channel, Telegram bot, WhatsApp number or Instagram account gets
                a chat here, and what you answer goes back to them.
            </p>
            <Button as-child class="mt-2" data-test="inbox-workflows">
                <Link :href="workflowsIndex(project.ref_id)">
                    <Workflow />
                    {{ workflows ? 'Open workflows' : 'Start from a template' }}
                </Link>
            </Button>
        </EmptyState>
    </div>
</template>
