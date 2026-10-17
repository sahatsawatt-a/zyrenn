<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Bot,
    Plus,
    Ticket as TicketIcon,
    UserRound,
    UserRoundCheck,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { STATUSES } from '@/features/chat/desk';
import { SERVICES } from '@/features/chat/desk';
import type {
    Desk,
    DeskContact,
    DeskPerson,
    DeskStatus,
} from '@/features/chat/desk';
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { socketHeaders } from '@/lib/live';
import { show, update } from '@/routes/chats';
import Tip from '@/components/common/Tip.vue';

// Under the header of a project's chat with a customer: where it stands,
// who has taken it, and its tags -- each changed where it is shown.
const props = defineProps<{
    room: string;
    desk: Desk & { people: DeskPerson[] };
    // Owners and editors of the project; a viewer only reads
    canChange: boolean;
    // Who it is with: their other chats are a click away
    contact?: DeskContact | null;
}>();

const elsewhere = computed(() =>
    (props.contact?.rooms ?? []).filter((chat) => chat.ref_id !== props.room),
);

const NOBODY = 'nobody';
const MAX_TAGS = 12;

const statuses = (Object.keys(STATUSES) as DeskStatus[]).map((value) => ({
    value,
    ...STATUSES[value],
}));

const people = computed(() => [
    { value: NOBODY, label: 'Unassigned', icon: UserRound },
    ...props.desk.people.map((person) => ({
        value: String(person.id),
        label: person.name,
        icon: UserRoundCheck,
    })),
]);

// The room and the sidebar's count are asked for again; what is being written stays as it is
const save = (changes: {
    ai_answers?: boolean;
    status?: string;
    assigned_to?: number | null;
    tags?: string[];
}) =>
    router.patch(update(props.room).url, changes, {
        preserveScroll: true,
        preserveState: true,
        only: ['room', 'project'],
        headers: socketHeaders(),
    });

const adding = ref(false);
const tag = ref('');

function addTag() {
    const word = tag.value.trim().toLowerCase();

    if (word && !props.desk.tags.includes(word)) {
        save({ tags: [...props.desk.tags, word] });
    }

    tag.value = '';
    adding.value = false;
}

const dropTag = (word: string) =>
    save({ tags: props.desk.tags.filter((one) => one !== word) });
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5 border-b px-4 py-1.5"
        data-chat-desk
    >
        <TicketPick
            compact
            :model-value="desk.status"
            :options="statuses"
            label="Status"
            :disabled="!canChange"
            data-test="desk-status"
            @update:model-value="save({ status: $event })"
        />
        <Tip
            :text="
                desk.assignee
                    ? 'AI keeps out of a chat someone has taken'
                    : 'Whether AI answers them here'
            "
        >
            <label
                class="text-muted-foreground flex h-7 items-center gap-1.5 rounded-md border px-2 text-xs"
            >
                <Bot class="size-3.5" />
                AI
                <Switch
                    :model-value="desk.ai"
                    :disabled="!canChange"
                    data-test="desk-ai"
                    @update:model-value="save({ ai_answers: !!$event })"
                />
            </label>
        </Tip>
        <TicketPick
            compact
            :model-value="desk.assignee ? String(desk.assignee.id) : NOBODY"
            :options="people"
            label="Assigned to"
            class="max-w-44"
            :disabled="!canChange"
            data-test="desk-assignee"
            @update:model-value="
                save({ assigned_to: $event === NOBODY ? null : Number($event) })
            "
        />
        <span
            v-for="word in desk.tags"
            :key="word"
            class="bg-muted flex h-6 items-center gap-0.5 rounded-full pr-1 pl-2 text-xs"
            data-test="desk-tag"
        >
            {{ word }}
            <Tip v-if="canChange" :text="`Take the tag ${word} off`">
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground rounded-full p-0.5"
                    :aria-label="`Take the tag ${word} off`"
                    @click="dropTag(word)"
                >
                    <X class="size-3" />
                </button>
            </Tip>
        </span>
        <Tip
            v-for="ticket in desk.tickets ?? []"
            :key="ticket.number"
            :text="ticket.title"
        >
            <Link
                :href="ticket.url"
                class="hover:bg-accent flex h-6 items-center gap-1 rounded-full border px-2 text-xs"
                :class="{
                    'text-muted-foreground line-through':
                        ticket.status === 'done' || ticket.status === 'dropped',
                }"
                data-test="desk-ticket"
            >
                <TicketIcon class="size-3" />#{{ ticket.number }}
            </Link>
        </Tip>
        <template v-if="elsewhere.length">
            <span class="text-muted-foreground ml-1 text-xs">Also on</span>
            <Tip
                v-for="chat in elsewhere"
                :key="chat.ref_id"
                :text="chat.title"
            >
                <Link
                    :href="show(chat.ref_id)"
                    class="flex h-6 items-center rounded-full bg-sky-500/10 px-2 text-xs text-sky-700 hover:bg-sky-500/20 dark:text-sky-400"
                    data-test="desk-also"
                    >{{
                        chat.service
                            ? (SERVICES[chat.service] ?? chat.service)
                            : chat.title
                    }}</Link
                >
            </Tip>
        </template>
        <Popover
            v-if="canChange && desk.tags.length < MAX_TAGS"
            v-model:open="adding"
        >
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground hover:bg-accent flex h-6 items-center gap-0.5 rounded-full border border-dashed px-2 text-xs"
                    data-test="desk-add-tag"
                >
                    <Plus class="size-3" /> Tag
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-56 p-2">
                <form @submit.prevent="addTag">
                    <Input
                        v-model="tag"
                        maxlength="30"
                        placeholder="refund, vip…"
                        aria-label="A tag"
                        autocomplete="off"
                        data-test="desk-tag-input"
                    />
                </form>
            </PopoverContent>
        </Popover>
    </div>
</template>
