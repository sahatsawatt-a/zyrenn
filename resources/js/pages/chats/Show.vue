<script setup lang="ts">
import { Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { StickyNote } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import { Button } from '@/components/ui/button';
import AgentSettingsDialog from '@/features/chat/components/AgentSettingsDialog.vue';
import FaceViewer from '@/features/chat/components/FaceViewer.vue';
import LeaveRoomDialog from '@/features/chat/components/LeaveRoomDialog.vue';
import MessageComposer from '@/features/chat/components/MessageComposer.vue';
import MessageItem from '@/features/chat/components/MessageItem.vue';
import PromoteDialog from '@/features/chat/components/PromoteDialog.vue';
import RoomAbout from '@/features/chat/components/RoomAbout.vue';
import RoomDesk from '@/features/chat/components/RoomDesk.vue';
import RoomHeader from '@/features/chat/components/RoomHeader.vue';
import type { Suggested } from '@/features/chat/components/SuggestReply.vue';
import TeamNotes from '@/features/chat/components/TeamNotes.vue';
import { useChatScroll } from '@/features/chat/composables/useChatScroll';
import { useChatSend } from '@/features/chat/composables/useChatSend';
import { useEarlierMessages } from '@/features/chat/composables/useEarlierMessages';
import { useFaces } from '@/features/chat/composables/useFaces';
import { useRoomLive } from '@/features/chat/composables/useRoomLive';
import { layOut } from '@/features/chat/conversation';
import type { Connection, Message, Room } from '@/features/chat/types';
import { destroy, index } from '@/routes/chats';
import { store as keepAsRequest } from '@/routes/chats/requests';
import { chat as projectChat, inbox as projectInbox } from '@/routes/projects';

// A chat room: what is said in it, and writing in it. In a customer's chat,
// what the customer sees stands apart from what only the project sees.

const props = defineProps<{
    room: Room;
    messages: Message[];
    // There is more before the first shown
    hasEarlier: boolean;
    // Who is in a room shared with others
    people: { id: number; name: string }[];
    connections: Connection[];
    // Whether one's own room may have an AI agent (CHAT_AGENTS); off, AI comes from workflows
    agents: boolean;
}>();

const me = usePage().props.auth.user.id;
const shared = props.room.kind !== 'personal';

// Back to where the room is listed: one's AI chats, one's messages, or the project's groups
setLayoutProps({
    breadcrumbs: [
        props.room.kind === 'customer' && props.room.project
            ? { title: 'Inbox', href: projectInbox(props.room.project.ref_id) }
            : props.room.kind === 'group' && props.room.project
              ? {
                    title: 'Groups',
                    href: projectChat(props.room.project.ref_id),
                }
              : { title: 'Chat', href: index() },
        { title: props.room.title || 'New chat', href: '' },
    ],
});

const list = ref<Message[]>([...props.messages]);
const hasEarlier = ref(props.hasEarlier);
const title = ref(props.room.title);

// One's own words sit on the right; in one's own room, every person's words are
const isMine = (message: Message) =>
    message.role === 'user' && (!shared || message.author?.id === me);

const add = (message: Message) => {
    if (!list.value.some((item) => item.id === message.id)) {
        list.value.push(message);
    }
};

// The messages as the server keeps them, in place of these
const reloadMessages = () =>
    router.reload({
        only: ['messages'],
        onSuccess: () => (list.value = [...props.messages]),
    });

// Where a customer's chat stands is asked for again, with the count beside the inbox
const deskMoved = () =>
    props.room.desk && router.reload({ only: ['room', 'project'] });

const customerChat = !!props.room.desk;
const teamNotes = computed(() =>
    customerChat ? list.value.filter((message) => message.internal) : [],
);
const shown = computed(() =>
    layOut(
        customerChat
            ? list.value.filter((message) => !message.internal)
            : list.value,
    ),
);

const { others } = useRoomLive(() => props.room, {
    heard: add,
    moved: deskMoved,
});
const { broken, roomFace, faceOf } = useFaces(() => props.room, list);

// --- writing ---

// A note for the project, in a customer's chat, in place of an answer to them
const noting = ref(false);
const composer = ref<InstanceType<typeof MessageComposer> | null>(null);

const { draft, attached, placed, streaming, failure, sending, send, stop } =
    useChatSend({
        room: () => props.room,
        noting,
        title,
        add,
        moved: deskMoved,
        reload: reloadMessages,
        settled: () => nextTick(() => composer.value?.focus()),
    });

const { scroller, atLatest, fresh, toBottom, onScroll, settle } = useChatScroll(
    { list, streaming, isMine },
);
const { loadingEarlier, loadEarlier } = useEarlierMessages({
    room: () => props.room,
    list,
    hasEarlier,
    scroller,
});

// A reply an agent suggests: put in what is being written, to read and change before it is
// sent -- with what it answered from, to check it by
const suggestion = ref<Suggested | null>(null);
// Its sources go with it: gone once what it was suggested for is
watch(draft, (now) => now.trim() || (suggestion.value = null));

function suggested(text: string, about: Suggested) {
    failure.value = null;
    draft.value = text;
    suggestion.value = about;
    nextTick(() => composer.value?.focus());
}

// --- what a customer asked ---

// Being made a ticket of
const promoting = ref<Message | null>(null);

// Kept word for word as a request of the project's product; the chat notes it for the team
const keepRequest = (message: Message) =>
    router.post(
        keepAsRequest(props.room.ref_id).url,
        { message: message.id },
        {
            preserveScroll: true,
            only: ['messages'],
            onSuccess: () => (list.value = [...props.messages]),
        },
    );

// The two parts, on a phone: what the customer sees, and what only the team does
const PARTS = computed(() => [
    { value: 'reply' as const, label: 'Customer', test: 'chat-part-customer' },
    {
        value: 'note' as const,
        label: 'Team only',
        icon: StickyNote,
        count: teamNotes.value.length,
        test: 'chat-part-team',
    },
]);

// --- the room itself ---

// What is known about the room, opened beside it
const about = ref(false);
// A face being looked at larger
const viewing = ref<{ src: string; name: string } | null>(null);
const settingsOpen = ref(false);
// Asked in the app's own way before anything that can't be taken back lightly
const leaving = ref(false);
const removing = ref(false);

function remove() {
    router.delete(destroy(props.room.ref_id).url);
}
</script>

<template>
    <Head :title="title || 'New chat'" />

    <div
        class="mx-auto flex h-[calc(100svh-4rem)] w-full flex-col"
        :class="customerChat ? 'max-w-6xl' : 'max-w-3xl'"
    >
        <RoomHeader
            v-model:title="title"
            v-model:about="about"
            :room="room"
            :people="people"
            :others="others"
            :agents="agents"
            :face="roomFace"
            @view="viewing = { src: $event, name: room.title }"
            @broken="broken.add($event)"
            @settings="settingsOpen = true"
            @leave="leaving = true"
            @remove="removing = true"
        />

        <RoomDesk
            v-if="room.desk"
            :room="room.ref_id"
            :desk="room.desk"
            :contact="room.contact"
            :can-change="room.manage"
        />

        <RoomAbout v-model:open="about" :room="room" :face="roomFace" />

        <PromoteDialog
            v-if="room.desk"
            :open="promoting !== null"
            :room="room.ref_id"
            :message="promoting"
            :customer="room.contact?.name ?? room.title"
            :service="room.link?.service ?? null"
            :parts="room.desk.parts"
            @update:open="$event || (promoting = null)"
        />

        <!-- A face, larger: the room's, or whoever wrote -->
        <FaceViewer
            :open="viewing !== null"
            :src="viewing?.src ?? null"
            :name="viewing?.name ?? ''"
            @update:open="$event || (viewing = null)"
        />

        <!-- On a phone, one part at a time: the conversation, or the team's -->
        <div
            v-if="customerChat"
            class="flex justify-center border-b px-4 py-1.5 lg:hidden"
        >
            <SegmentTabs
                :model-value="noting ? 'note' : 'reply'"
                :options="PARTS"
                label="Which part"
                @update:model-value="noting = $event === 'note'"
            />
        </div>

        <div class="flex min-h-0 flex-1">
            <div
                ref="scroller"
                class="min-h-0 flex-1 overflow-y-auto px-4 pb-4"
                :class="{ 'hidden lg:block': customerChat && noting }"
                data-chat-messages
                @scroll.passive="onScroll"
                @load.capture="settle"
                @loadedmetadata.capture="settle"
            >
                <div v-if="hasEarlier" class="flex justify-center">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="loadingEarlier"
                        data-chat-earlier
                        @click="loadEarlier"
                    >
                        {{
                            loadingEarlier
                                ? 'Loading…'
                                : 'Show earlier messages'
                        }}
                    </Button>
                </div>

                <p
                    v-if="room.mine && agents && !room.ready"
                    class="bg-muted/40 rounded-lg border px-4 py-3 text-sm"
                    data-chat-no-agent
                >
                    No agent answers here, so this is only a log of what you
                    write.
                    <button
                        class="text-primary underline underline-offset-4"
                        @click="settingsOpen = true"
                    >
                        Choose a connection and model
                    </button>
                    to have it answer.
                </p>

                <p
                    v-else-if="!list.length"
                    class="text-muted-foreground py-16 text-center text-sm"
                >
                    Say something to begin.
                </p>

                <template
                    v-for="{ message, album, day, first, last } in shown"
                    :key="message.id"
                >
                    <p
                        v-if="day"
                        class="text-muted-foreground flex items-center gap-3 pt-5 pb-1 text-xs select-none"
                        data-chat-day
                    >
                        <span class="bg-border h-px flex-1" />
                        {{ day }}
                        <span class="bg-border h-px flex-1" />
                    </p>

                    <MessageItem
                        :message="message"
                        :album="album"
                        :first="first"
                        :last="last"
                        :mine="isMine(message)"
                        :kind="room.kind"
                        :face="faceOf(message)"
                        :can-file="!!room.desk && room.manage"
                        @view="
                            viewing = {
                                src: $event,
                                name: message.sender?.name ?? room.title,
                            }
                        "
                        @broken="broken.add($event)"
                        @promote="promoting = $event"
                        @request="keepRequest"
                    />
                </template>

                <!-- Still being written: plain until it is whole, then drawn as a note -->
                <div v-if="streaming !== null" class="mt-3" data-chat-streaming>
                    <p v-if="streaming" class="text-sm whitespace-pre-wrap">
                        {{ streaming }}
                    </p>
                    <p v-else class="text-muted-foreground text-sm">
                        Thinking…
                    </p>
                </div>

                <p
                    v-if="failure"
                    class="text-destructive mt-3 text-sm"
                    role="alert"
                    data-chat-error
                >
                    {{ failure }}
                </p>
            </div>
            <TeamNotes
                v-if="customerChat"
                :notes="teamNotes"
                :customer="room.contact?.name ?? room.title"
                class="w-full border-l lg:w-80"
                :class="noting ? 'flex' : 'hidden lg:flex'"
            />
        </div>

        <p
            v-if="!room.post"
            class="text-muted-foreground border-t px-4 py-3 text-center text-sm"
            data-chat-read-only
        >
            You can read this chat. Owners and editors of
            {{ room.project?.name }} answer in it.
        </p>
        <MessageComposer
            v-else
            ref="composer"
            v-model:draft="draft"
            v-model:attached="attached"
            v-model:placed="placed"
            v-model:noting="noting"
            :room="room"
            :sending="sending"
            :suggestion="suggestion"
            :at-latest="atLatest"
            :fresh="fresh"
            @send="send"
            @stop="stop"
            @latest="toBottom"
            @suggested="suggested"
            @failed="failure = $event"
        />

        <DeleteDialog
            v-model:open="removing"
            :name="room.title || 'this chat'"
            description="Everything said in it goes with it. Files sent in it stay in the Drive."
            @confirm="remove"
        />

        <LeaveRoomDialog v-model:open="leaving" :room="room" />

        <AgentSettingsDialog
            v-if="agents"
            v-model:open="settingsOpen"
            :room="room"
            :connections="connections"
        />
    </div>
</template>
