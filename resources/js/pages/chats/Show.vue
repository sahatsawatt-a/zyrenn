<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import type { JSONContent } from '@tiptap/vue-3';
import {
    ArrowDown,
    Link2,
    HardDrive,
    Info,
    LogOut,
    MapPin,
    Paperclip,
    Send,
    Settings2,
    Square,
    StickyNote,
    Ticket as TicketIcon,
    Trash2,
    Upload,
    X,
    Zap,
} from '@lucide/vue';
import { useNow } from '@vueuse/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    shallowRef,
    watch,
} from 'vue';
import EmojiPicker from '@/features/chat/components/EmojiPicker.vue';
import FaceViewer from '@/features/chat/components/FaceViewer.vue';
import MessageAlbum from '@/features/chat/components/MessageAlbum.vue';
import MessageFile from '@/features/chat/components/MessageFile.vue';
import MessagePlace from '@/features/chat/components/MessagePlace.vue';
import type { ChatPlace } from '@/features/chat/components/MessagePlace.vue';
import PlaceDialog from '@/features/chat/components/PlaceDialog.vue';
import RoomAbout from '@/features/chat/components/RoomAbout.vue';
import PromoteDialog from '@/features/chat/components/PromoteDialog.vue';
import RoomDesk from '@/features/chat/components/RoomDesk.vue';
import TeamNotes from '@/features/chat/components/TeamNotes.vue';
import SavedReplies from '@/features/chat/components/SavedReplies.vue';
import SuggestReply from '@/features/chat/components/SuggestReply.vue';
import type { Suggested } from '@/features/chat/components/SuggestReply.vue';
import AnswerActions from '@/features/chat/components/AnswerActions.vue';
import AnswerSources from '@/features/chat/components/AnswerSources.vue';
import type { AnswerSource } from '@/features/chat/components/AnswerSources.vue';
import type { Desk, DeskContact, DeskPerson } from '@/features/chat/desk';
import SegmentTabs from '@/components/common/SegmentTabs.vue';
import MessageText from '@/features/chat/components/MessageText.vue';
import ModelPicker from '@/features/chat/components/ModelPicker.vue';
import PresenceAvatars from '@/components/common/PresenceAvatars.vue';
import TiptapEditor from '@/features/notes/components/TiptapEditor.vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getInitials } from '@/composables/useInitials';
import { setUnreadChats } from '@/composables/useUnreadChats';
import { usePresence } from '@/composables/usePresence';
import { colourFor, socketHeaders } from '@/lib/live';
import type { Member } from '@/lib/live';
import { xsrfToken } from '@/lib/utils';
import { index as connectionsIndex, models } from '@/routes/connections';
import {
    destroy,
    index,
    leave as leaveRoom,
    read as markRead,
    update,
} from '@/routes/chats';
import DeleteDialog from '@/components/common/DeleteDialog.vue';
import DriveFileDialog from '@/features/chat/components/DriveFileDialog.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { DriveFile } from '@/lib/drive';
import {
    index as earlierMessages,
    store as sendMessage,
} from '@/routes/chats/messages';
import { index as chatsIndex } from '@/routes/chats';
import { chat as projectChat, inbox as projectInbox } from '@/routes/projects';

type Message = {
    id: number;
    role: 'user' | 'assistant' | 'workflow';
    content: string;
    created_at: string | null;
    // An agent's words as the note editor's document, so they are drawn as a note is
    doc: JSONContent | null;
    // Why an answer stopped short, when it did
    error: string | null;
    // Who said it, when a person did; id is null for someone since gone
    author: { id: number | null; name: string } | null;
    // A file a workflow put under its words, from the Drive
    file?: {
        ref_id: string;
        name: string;
        url: string;
        is_image: boolean;
        is_video: boolean;
    } | null;
    // A place shown on a small map under the words
    place?: ChatPlace | null;
    // Drawn as a sticker is: small, with nothing round it
    sticker?: boolean;
    // Pictures that stand for some of the words: an outside chat's own emoji
    emojis?: { index: number; length: number; url: string }[];
    // A note among the project, in a customer's chat: never sent out
    internal?: boolean;
    // What an agent answered from, when it is an agent's answer
    sources?: AnswerSource[] | null;
    // A workflow's: under its name, or the outside sender's it carried over
    sender?: {
        name: string;
        outside: boolean;
        // Their face: a picture's address, when a workflow looked one up
        picture?: string | null;
        by: string | null;
        workflow: string | null;
    } | null;
};

type Room = {
    ref_id: string;
    // personal: one's own, maybe with an agent; direct: two people; group: a project's;
    // customer: a project's chat with someone outside, in its inbox
    kind: 'personal' | 'direct' | 'group' | 'customer';
    title: string;
    // One's own room, whose agent can be set up
    mine: boolean;
    // May rename and delete it
    manage: boolean;
    // May leave it: a group one is in
    leave: boolean;
    // May delete it, and may write in it
    remove: boolean;
    post: boolean;
    // A customer's chat: where it stands, who has taken it, its tags, and who it can be given to
    desk:
        | (Desk & {
              people: DeskPerson[];
              // Until when its service takes an answer; null where it sets no such time
              answer_until: string | null;
          })
        | null;
    // Who a customer's chat is with, on every service
    contact: DeskContact | null;
    project: { ref_id: string; name: string } | null;
    model: string | null;
    system_prompt: string | null;
    connection: string | null;
    ready: boolean;
    // Linked to a chat outside: a workflow may send what is written here there
    link: { service: string; through: string | null } | null;
    // Its face, and what is known about it by name: kept by a workflow, or noted by hand
    picture?: string | null;
    details?: Record<string, string>;
    // The names among the details a workflow keeps, which are not changed by hand
    kept?: string[];
    // How many messages it holds in all
    messages?: number;
    created_at?: string | null;
};

type Connection = {
    ref_id: string;
    name: string;
    kind: string;
    label: string;
    default_model: string | null;
};

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

const page = usePage();
const me = page.props.auth.user.id;
const shared = props.room.kind !== 'personal';

// Back to where the room is listed: one's AI chats, one's messages, or the project's groups
setLayoutProps({
    breadcrumbs: [
        props.room.kind === 'personal'
            ? { title: 'Chat', href: index() }
            : props.room.kind === 'customer' && props.room.project
              ? {
                    title: 'Inbox',
                    href: projectInbox(props.room.project.ref_id),
                }
              : props.room.kind === 'group' && props.room.project
                ? {
                      title: 'Groups',
                      href: projectChat(props.room.project.ref_id),
                  }
                : { title: 'Chat', href: chatsIndex() },
        { title: props.room.title || 'New chat', href: '' },
    ],
});

const list = ref<Message[]>([...props.messages]);
const hasEarlier = ref(props.hasEarlier);

// One's own words sit on the right; in one's own room, every person's words are
const isMine = (message: Message) =>
    message.role === 'user' && (!shared || message.author?.id === me);

// Only emoji, and few: drawn large, with nothing round them, as a sticker is
const EMOJI_ONLY =
    /^(?:\p{Extended_Pictographic}|\p{Emoji_Modifier}|\p{Regional_Indicator}|\u200d|\ufe0f|\s)+$/u;
const segmenter =
    typeof Intl.Segmenter === 'function'
        ? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
        : null;

const onlyEmoji = (message: Message) => {
    const said = message.content.trim();

    return (
        said !== '' &&
        !message.emojis?.length &&
        /\p{Extended_Pictographic}|\p{Regional_Indicator}/u.test(said) &&
        EMOJI_ONLY.test(said) &&
        [...(segmenter?.segment(said.replace(/\s+/g, '')) ?? said)].length <= 3
    );
};

// --- how the conversation is laid out ---
// Who said a message, so several from one person in a row read as one turn
const speakerOf = (message: Message) =>
    message.role === 'user'
        ? `person:${message.author?.id}`
        : message.role === 'workflow'
          ? `workflow:${message.sender?.outside ? 'outside' : 'own'}:${message.sender?.name}`
          : 'agent';

const dayOf = (message: Message) =>
    message.created_at ? new Date(message.created_at).toDateString() : '';

// A turn goes on while the same one speaks, on the same day, without a long gap
const TURN_MINUTES = 10;

const sameTurn = (a: Message | undefined, b: Message | undefined) =>
    !!a &&
    !!b &&
    speakerOf(a) === speakerOf(b) &&
    dayOf(a) === dayOf(b) &&
    Math.abs(
        new Date(b.created_at ?? 0).getTime() -
            new Date(a.created_at ?? 0).getTime(),
    ) <=
        TURN_MINUTES * 60_000;

const clock = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
});
const dayThisYear = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const dayAnyYear = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});
const whole = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'full',
    timeStyle: 'short',
});

const timeOf = (message: Message) =>
    message.created_at ? clock.format(new Date(message.created_at)) : '';
const whenOf = (message: Message) =>
    message.created_at ? whole.format(new Date(message.created_at)) : '';

function dayLabel(message: Message) {
    const day = new Date(message.created_at ?? Date.now());
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (day.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (day.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return (
        day.getFullYear() === today.getFullYear() ? dayThisYear : dayAnyYear
    ).format(day);
}

// Pictures sent together are drawn as one: by the same speaker, one after
// another, each within this long of the one before
const ALBUM_SECONDS = 60;

// A picture that is not a sticker
const pictureOf = (message: Message) =>
    message.file?.is_image && !message.sticker ? message.file : null;

// Whether a message is the next picture of those before it: a picture with
// nothing said, from the same speaker, straight after
const joins = (before: Message, message: Message) =>
    !!pictureOf(before) &&
    !!pictureOf(message) &&
    !message.content &&
    !message.place &&
    speakerOf(before) === speakerOf(message) &&
    dayOf(before) === dayOf(message) &&
    Math.abs(
        new Date(message.created_at ?? 0).getTime() -
            new Date(before.created_at ?? 0).getTime(),
    ) <=
        ALBUM_SECONDS * 1000;

// Each message with where it stands: the first of a day, and the first and
// last of its speaker's turn -- a name goes over the first, a face and the
// time by the last. Pictures sent together stand as one, under the first
// In a customer's chat, what they see stands apart from what only the project sees
const customerChat = !!props.room.desk;
const teamNotes = computed(() =>
    customerChat ? list.value.filter((message) => message.internal) : [],
);

const shown = computed(() => {
    const all = customerChat
        ? list.value.filter((message) => !message.internal)
        : list.value;
    const entries: {
        message: Message;
        album: NonNullable<Message['file']>[] | null;
        day: string | null;
        first: boolean;
        last: boolean;
    }[] = [];

    for (let at = 0; at < all.length; at++) {
        const message = all[at];
        let end = at;

        while (end + 1 < all.length && joins(all[end], all[end + 1])) {
            end++;
        }

        entries.push({
            message,
            album:
                end > at
                    ? all
                          .slice(at, end + 1)
                          .map(
                              (one) => one.file as NonNullable<Message['file']>,
                          )
                    : null,
            day:
                dayOf(all[at - 1] ?? message) !== dayOf(message) || at === 0
                    ? dayLabel(message)
                    : null,
            first: !sameTurn(all[at - 1], message),
            last: !sameTurn(all[end], all[end + 1]),
        });
        at = end;
    }

    return entries;
});

// Someone outside whose words a workflow carried in is drawn as a person;
// a workflow speaking for itself, as a workflow
const fromOutside = (message: Message) =>
    message.role === 'workflow' && !!message.sender?.outside;

// The name over a turn: always for what a workflow wrote, and in a group
// for the others in it
const nameOver = (message: Message) =>
    message.role === 'workflow'
        ? (message.sender?.name ?? 'Workflow')
        : props.room.kind === 'group' || props.room.kind === 'customer'
          ? (message.author?.name ?? '')
          : '';

// Faces that would not load -- an address gone stale -- fall back to initials
const broken = reactive(new Set<string>());

// The face of whoever a linked chat is with, for the top of the room: the
// latest one a message came with
const face = computed(() => {
    for (let at = list.value.length - 1; at >= 0; at--) {
        const picture = list.value[at].sender?.picture;

        if (picture && !broken.has(picture)) {
            return picture;
        }
    }

    return null;
});

// The room's own face, when a workflow keeps one with it; else that of
// whoever wrote last
const roomFace = computed(() =>
    props.room.picture && !broken.has(props.room.picture)
        ? props.room.picture
        : props.room.link
          ? face.value
          : null,
);

// The face beside what someone outside wrote: the room's own -- kept once
// with the room, by the workflow that looks them up -- or one an older
// message came with
const faceOf = (message: Message) => {
    const picture = fromOutside(message)
        ? message.sender?.picture || props.room.picture
        : null;

    return picture && !broken.has(picture) ? picture : null;
};

// What is known about the room, opened beside it
const about = ref(false);

// A face being looked at larger
const viewing = ref<{ src: string; name: string } | null>(null);

// "Ada (LINE)" is Ada: what is in brackets says where from, not who
const initialsOf = (name: string) =>
    getInitials(name.replace(/\s*\(.*\)\s*$/, '') || name);

const colourOf = (message: Message) =>
    message.role === 'user'
        ? message.author?.id != null
            ? colourFor(message.author.id)
            : '#9ca3af'
        : colourFor(
              [...(message.sender?.name ?? '')].reduce(
                  (sum, letter) => (sum * 31 + letter.charCodeAt(0)) | 0,
                  7,
              ),
          );

// A note among the project: drawn apart from what was said to the customer
const NOTE =
    'border border-amber-500/40 bg-amber-100 text-amber-950 dark:bg-amber-500/15 dark:text-amber-100';

const add = (message: Message) => {
    if (!list.value.some((item) => item.id === message.id)) {
        list.value.push(message);
    }
};

// --- what others say, as they say it, in a room shared with them ---
// Read as it arrives, while the page is open. Sent at once and kept alive past
// the page, so leaving the room straight after does not leave it unread.
async function readUpTo(id: number) {
    try {
        const response = await fetch(markRead(props.room.ref_id).url, {
            method: 'POST',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ message: id }),
        });

        if (response.ok) {
            setUnreadChats((await response.json()).unread);
        }
    } catch {
        // Read again on the next visit
    }
}

// Live in one's own chats too: a workflow can write in them
const presence = usePresence(() => `chats.${props.room.ref_id}`, {
    'chat.message': (event: { message: Message }) => {
        add(event.message);
        void readUpTo(event.message.id);
        // What a customer sends opens their chat again
        deskMoved();
    },
    // Someone else moved it, took it or tagged it -- or a workflow did
    'chat.room': () => deskMoved(),
});

// Where a customer's chat stands is asked for again, with the count beside the inbox
const deskMoved = () =>
    props.room.desk && router.reload({ only: ['room', 'project'] });

// WhatsApp and Instagram take an answer only for a day after the customer last wrote: said
// before it is written, since the service would refuse it
const answerUntil = computed(() =>
    props.room.desk?.answer_until
        ? new Date(props.room.desk.answer_until)
        : null,
);
const now = useNow({ interval: 30_000 });
const tooLate = computed(
    () => answerUntil.value !== null && answerUntil.value < now.value,
);
const until = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    hour: 'numeric',
    minute: '2-digit',
});

// A note for the project, in a customer's chat, in place of an answer to them
const noting = ref(false);

// What a customer asked, being made a ticket of
const promoting = ref<Message | null>(null);

// A reply an agent suggests: put in what is being written, to read and change before it is
// sent -- with what it answered from, to check it by
const suggestion = ref<Suggested | null>(null);

function suggested(text: string, about: Suggested) {
    failure.value = null;
    draft.value = text;
    suggestion.value = about;
    nextTick(() => input.value?.focus());
}

const WRITES = [
    {
        value: 'reply' as const,
        label: 'Reply to them',
        test: 'chat-as-reply',
    },
    {
        value: 'note' as const,
        label: 'Note for the team',
        title: 'For your project only: it is not sent to them',
        icon: StickyNote,
        test: 'chat-as-note',
    },
];

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
const others = shared ? presence.others : ref<Member[]>([]);

// --- scrolling back ---
const loadingEarlier = ref(false);

async function loadEarlier() {
    const first = list.value[0];

    if (!first || loadingEarlier.value) {
        return;
    }

    loadingEarlier.value = true;
    const before = scroller.value?.scrollHeight ?? 0;

    try {
        const response = await fetch(
            earlierMessages(props.room.ref_id, {
                query: { before: first.id },
            }).url,
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json();

        list.value = [...body.messages, ...list.value];
        hasEarlier.value = body.hasEarlier;

        // Stay on what was being read, not jump to the top of what came in
        await nextTick();

        if (scroller.value) {
            scroller.value.scrollTop += scroller.value.scrollHeight - before;
        }
    } finally {
        loadingEarlier.value = false;
    }
}

// Asked in the app's own way before anything that can't be taken back lightly
const leaving = ref(false);

function leave() {
    router.post(leaveRoom(props.room.ref_id).url);
}
const title = ref(props.room.title);
const draft = ref('');
// A suggested reply's sources go with it: gone once what it was suggested for is
watch(draft, (now) => now.trim() || (suggestion.value = null));
// What the agent has said so far in the answer being written
const streaming = ref<string | null>(null);
const failure = ref<string | null>(null);
const sending = ref(false);
let abort: AbortController | null = null;

const scroller = ref<HTMLElement | null>(null);
const input = ref<HTMLTextAreaElement | null>(null);

const toBottom = () =>
    nextTick(() => {
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }

        // Taken there on purpose: there, whatever a scroll says a moment later
        atLatest.value = true;
        fresh.value = false;
    });

// Whether the latest is in view: while it is, the page stays with it as
// messages come and pictures find their size; scrolled back, it stays put
const atLatest = ref(true);
// Something came while scrolled back
const fresh = ref(false);

// How large the room was when last looked at: a scroll that comes of it
// changing size -- the window, a phone's keyboard -- is not someone scrolling
let box = { width: 0, height: 0 };

function onScroll() {
    const here = scroller.value;

    if (
        here &&
        here.clientWidth === box.width &&
        here.clientHeight === box.height
    ) {
        atLatest.value =
            here.scrollHeight - here.scrollTop - here.clientHeight < 120;

        if (atLatest.value) {
            fresh.value = false;
        }
    }
}

// A picture or a film that has just found its size pushes what is under it down
function settle() {
    if (atLatest.value) {
        void toBottom();
    }
}

// The window, or the keyboard on a phone, changing the room's height
let sized: ResizeObserver | null = null;

onMounted(() => {
    void toBottom();

    if (scroller.value && typeof ResizeObserver !== 'undefined') {
        sized = new ResizeObserver(() => {
            const here = scroller.value;

            if (here) {
                box = { width: here.clientWidth, height: here.clientHeight };
            }

            settle();
        });
        sized.observe(scroller.value);
    }
});
onBeforeUnmount(() => sized?.disconnect());
// Down to what is new as it comes -- one's own always, others' only when the
// latest was in view -- and never when earlier messages come in at the top
watch(
    () => [list.value[list.value.length - 1]?.id, streaming.value],
    () => {
        const latest = list.value[list.value.length - 1];

        if (
            atLatest.value ||
            streaming.value !== null ||
            (latest && isMine(latest))
        ) {
            void toBottom();
        } else {
            fresh.value = true;
        }
    },
);

// The room's own title is saved as it is left
function saveTitle() {
    if (title.value !== props.room.title) {
        router.patch(
            update(props.room.ref_id).url,
            { title: title.value },
            { preserveScroll: true, only: ['room'] },
        );
    }
}

// A file to go with the next message: kept in the Drive of whoever's chat
// this is. Two people's own chat has no Drive both can open.
const attachable = props.room.kind !== 'direct';
const picker = ref<HTMLInputElement | null>(null);
// Chosen from this device, to be uploaded -- or one in the Drive already
const attached = shallowRef<File | DriveFile | null>(null);
const choosing = ref(false);

// Or a place, in place of a file: chosen from the saved ones, a search, or where one is
const placed = shallowRef<ChatPlace | null>(null);
const placing = ref(false);

function attachPlace(place: ChatPlace) {
    placed.value = place;
    attached.value = null;
    input.value?.focus();
}

function attach(event: Event) {
    const chosen = (event.target as HTMLInputElement).files?.[0] ?? null;

    attached.value = chosen;
    placed.value = null;
    (event.target as HTMLInputElement).value = '';
    input.value?.focus();
}

function attachFromDrive(file: DriveFile) {
    attached.value = file;
    placed.value = null;
    input.value?.focus();
}

// Pasted from the clipboard or dropped on the box: the first file is attached
function take(files: FileList | undefined | null, event: Event) {
    if (attachable && files?.length) {
        event.preventDefault();
        attached.value = files[0];
        placed.value = null;
        input.value?.focus();
    }
}

const dropping = ref(false);

// A look at a picture before it is sent
const preview = ref<string | null>(null);

watch(attached, (file, before) => {
    if (before instanceof File && preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = !file
        ? null
        : file instanceof File
          ? file.type.startsWith('image/')
              ? URL.createObjectURL(file)
              : null
          : file.is_image
            ? file.url
            : null;
});

async function send() {
    const content = draft.value.trim();
    const file = attached.value;
    const place = placed.value;

    if ((!content && !file && !place) || sending.value) {
        return;
    }

    // A file from this device goes with the words as a form; one in the
    // Drive already is only named
    const form = file instanceof File ? new FormData() : null;
    form?.append('content', content);
    form?.append('upload', file as File);

    if (noting.value) {
        form?.append('internal', '1');
    }

    const named = file && 'ref_id' in file ? file.ref_id : undefined;

    draft.value = '';
    attached.value = null;
    placed.value = null;
    failure.value = null;
    sending.value = true;
    abort = new AbortController();

    try {
        const response = await fetch(sendMessage(props.room.ref_id).url, {
            method: 'POST',
            signal: abort.signal,
            headers: {
                ...(form ? {} : { 'Content-Type': 'application/json' }),
                Accept: 'text/event-stream',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                // So the others hear it, and this page does not hear it twice
                ...socketHeaders(),
            },
            body:
                form ??
                JSON.stringify({
                    content,
                    file: named,
                    place: place ?? undefined,
                    internal: noting.value || undefined,
                }),
        });

        if (!response.ok || !response.body) {
            draft.value = content;
            attached.value = file;
            placed.value = place;
            failure.value =
                response.status === 422
                    ? 'That message cannot be sent: it is empty or too long, or its file is too large.'
                    : response.status === 413
                      ? 'That file is too large to send.'
                      : 'Could not send that.';

            return;
        }

        await read(response.body);
    } catch (error) {
        // Stopping is not a failure; the page just keeps what it had
        if ((error as Error).name !== 'AbortError') {
            failure.value = 'The connection was lost.';
        }
    } finally {
        // The server keeps whatever was written, so the page asks for it rather than guess
        if (streaming.value !== null || failure.value) {
            router.reload({
                only: ['messages'],
                onSuccess: () => {
                    list.value = [...props.messages];
                },
            });
        }

        streaming.value = null;
        sending.value = false;
        abort = null;
        nextTick(() => input.value?.focus());
    }
}

// Server-sent events: "event: name", "data: json", and a blank line after each
async function read(body: ReadableStream<Uint8Array>) {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            return;
        }

        buffer += decoder.decode(value, { stream: true });

        let end: number;

        while ((end = buffer.indexOf('\n\n')) !== -1) {
            const block = buffer.slice(0, end);
            buffer = buffer.slice(end + 2);

            const name = /^event: (\w+)/m.exec(block)?.[1];
            const data = /^data: (.*)$/m.exec(block)?.[1];

            if (name && data) {
                handle(name, JSON.parse(data));
            }
        }
    }
}

function handle(
    name: string,
    data: { message?: Message | null; text?: string; title?: string },
) {
    if (name === 'user' && data.message) {
        add(data.message);
        title.value = data.title ?? title.value;
        streaming.value = props.room.ready ? '' : null;
        // An answer leaves a customer's chat waiting on them
        deskMoved();
    } else if (name === 'delta') {
        streaming.value = (streaming.value ?? '') + (data.text ?? '');
    } else if (name === 'done') {
        if (data.message) {
            add(data.message);
        }

        streaming.value = null;
    } else if (name === 'error') {
        failure.value = (data as { message?: string }).message as string;
    }
}

const stop = () => abort?.abort();

// An emoji goes where the cursor was when the message was left for the
// picker, and the next after it; the picker closed, writing carries on there
const caret = ref<number | null>(null);

function addEmoji(emoji: string) {
    const at = Math.min(caret.value ?? draft.value.length, draft.value.length);

    draft.value = draft.value.slice(0, at) + emoji + draft.value.slice(at);
    caret.value = at + emoji.length;
}

function backToWriting() {
    const at = caret.value;

    nextTick(() => {
        input.value?.focus();

        if (at !== null) {
            input.value?.setSelectionRange(at, at);
        }
    });
}

function onKey(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void send();
    }
}

const removing = ref(false);

function remove() {
    router.delete(destroy(props.room.ref_id).url);
}

// --- the room's agent: a connection, a model, and instructions ---
const settingsOpen = ref(false);
const connection = ref(props.room.connection ?? '');
const model = ref(props.room.model ?? '');
const prompt = ref(props.room.system_prompt ?? '');
const offered = ref<string[]>([]);
const offeredFree = ref<string[] | null>(null);
const offeredFailure = ref<string | null>(null);
const loadingModels = ref(false);

// Reka's Select can't hold an empty value, so "no connection" has a name of its own
const NONE = '__none';
const chosenConnection = computed(() =>
    props.connections.find((c) => c.ref_id === connection.value),
);

// A model of the old connection means nothing to the new one, so a change starts the choice over
function chooseConnection(value: unknown) {
    connection.value = value === NONE ? '' : String(value);
    model.value = '';
    void loadModels();
}

async function loadModels() {
    offered.value = [];
    offeredFree.value = null;
    offeredFailure.value = null;

    if (!connection.value) {
        return;
    }

    loadingModels.value = true;

    try {
        const response = await fetch(models(connection.value).url, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        if (response.ok) {
            offered.value = body.models;
            offeredFree.value = body.free;
        } else {
            offeredFailure.value = body.message;
        }
    } catch {
        offeredFailure.value = 'Could not ask for its models.';
    } finally {
        loadingModels.value = false;
    }
}

watch(settingsOpen, (open) => open && void loadModels());

const usual = () =>
    props.connections.find((c) => c.ref_id === connection.value)
        ?.default_model ?? '';

function saveSettings() {
    router.patch(
        update(props.room.ref_id).url,
        {
            connection: connection.value || null,
            model: model.value || null,
            system_prompt: prompt.value || null,
        },
        {
            preserveScroll: true,
            only: ['room', 'connections'],
            onSuccess: () => (settingsOpen.value = false),
        },
    );
}
</script>

<template>
    <Head :title="title || 'New chat'" />

    <div
        class="mx-auto flex h-[calc(100svh-4rem)] w-full flex-col"
        :class="customerChat ? 'max-w-6xl' : 'max-w-3xl'"
    >
        <header class="flex items-center gap-2 border-b px-4 py-2.5">
            <button
                v-if="roomFace"
                type="button"
                class="shrink-0 cursor-zoom-in rounded-full"
                aria-label="Open the picture"
                @click="viewing = { src: roomFace, name: room.title }"
            >
                <img
                    :src="roomFace"
                    alt=""
                    class="size-8 rounded-full object-cover"
                    referrerpolicy="no-referrer"
                    data-chat-room-face
                    @error="broken.add(roomFace)"
                />
            </button>
            <Input
                v-if="room.manage"
                v-model="title"
                class="h-9 flex-1 border-transparent bg-transparent text-base font-medium shadow-none"
                :placeholder="
                    room.kind === 'group'
                        ? 'Group name'
                        : room.kind === 'customer'
                          ? 'Who it is with'
                          : 'New chat'
                "
                :maxlength="room.kind === 'personal' ? 255 : 80"
                aria-label="Chat title"
                @blur="saveTitle"
                @keydown.enter.prevent="
                    ($event.target as HTMLInputElement).blur()
                "
            />
            <div v-else class="min-w-0 flex-1 px-3">
                <h1 class="truncate text-base font-medium" data-chat-title>
                    {{ title }}
                </h1>
                <p
                    v-if="room.kind === 'group'"
                    class="text-muted-foreground truncate text-xs"
                >
                    {{ room.project?.name }} ·
                    {{ people.map((person) => person.name).join(', ') }}
                </p>
            </div>
            <span
                v-if="room.link"
                class="flex shrink-0 items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-xs text-sky-700 dark:text-sky-400"
                :title="`What you write here can be sent on through ${room.link.service}, by a workflow`"
                data-chat-link
            >
                <Link2 class="size-3" />
                {{ room.link.service
                }}<template v-if="room.link.through">
                    · {{ room.link.through }}</template
                >
            </span>
            <p
                v-if="room.manage && room.kind === 'group'"
                class="text-muted-foreground hidden max-w-48 truncate text-xs sm:block"
            >
                {{ people.length }} people
            </p>
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="About this chat"
                title="About this chat"
                :aria-expanded="about"
                data-chat-about-toggle
                @click="about = !about"
            >
                <Info />
            </Button>
            <PresenceAvatars :others="others" />
            <Button
                v-if="room.mine && agents"
                variant="ghost"
                size="sm"
                @click="settingsOpen = true"
            >
                <Settings2 />
                <span class="hidden sm:inline">
                    {{ room.ready ? 'Agent' : 'Set up agent' }}
                </span>
            </Button>
            <Button
                v-if="room.leave"
                variant="ghost"
                size="sm"
                data-leave
                @click="leaving = true"
            >
                <LogOut />
                <span class="hidden sm:inline">Leave</span>
            </Button>
            <Button
                v-if="room.remove"
                variant="ghost"
                size="icon-sm"
                aria-label="Delete chat"
                @click="removing = true"
            >
                <Trash2 />
            </Button>
        </header>

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

                    <!-- One's own, on the right -->
                    <div
                        v-if="isMine(message)"
                        class="flex flex-col items-end"
                        :class="first ? 'mt-3' : 'mt-0.5'"
                        data-chat-user
                        data-chat-mine
                    >
                        <div
                            class="flex max-w-[85%] min-w-0 flex-col items-end sm:max-w-[70%]"
                        >
                            <p
                                v-if="message.internal && first"
                                class="mb-0.5 flex items-center gap-1 px-1 text-[11px] text-amber-700 dark:text-amber-400"
                                data-chat-note
                            >
                                <StickyNote class="size-3" /> Note · not sent to
                                them
                            </p>
                            <p
                                v-if="message.content && onlyEmoji(message)"
                                class="px-1 text-5xl leading-tight"
                                data-chat-emoji-only
                            >
                                {{ message.content.trim() }}
                            </p>
                            <p
                                v-else-if="message.content"
                                class="rounded-2xl px-3.5 py-2 text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
                                :class="[
                                    message.internal
                                        ? NOTE
                                        : 'bg-primary text-primary-foreground',
                                    { 'rounded-br-md': last },
                                ]"
                            >
                                <MessageText
                                    :content="message.content"
                                    :emojis="message.emojis"
                                />
                            </p>
                            <MessageAlbum v-if="album" :files="album" />
                            <MessageFile
                                v-else-if="message.file"
                                :file="message.file"
                                :sticker="message.sticker"
                            />
                            <MessagePlace
                                v-if="message.place"
                                :place="message.place"
                            />
                        </div>
                        <time
                            v-if="last && message.created_at"
                            class="text-muted-foreground mt-0.5 px-1 text-[11px] tabular-nums"
                            :datetime="message.created_at"
                            :title="whenOf(message)"
                            data-chat-time
                        >
                            {{ timeOf(message) }}
                        </time>
                    </div>

                    <!-- Someone else's: a person here, someone outside whose words
                     a workflow carried in, or a workflow speaking for itself -->
                    <div
                        v-else-if="message.role !== 'assistant'"
                        class="group flex items-end gap-2"
                        :class="first ? 'mt-3' : 'mt-0.5'"
                        :data-chat-user="
                            message.role === 'user' ? '' : undefined
                        "
                        :data-chat-theirs="
                            message.role === 'user' ? '' : undefined
                        "
                        :data-chat-workflow="
                            message.role === 'workflow' ? '' : undefined
                        "
                    >
                        <span
                            v-if="
                                message.role === 'workflow' &&
                                !fromOutside(message)
                            "
                            class="mb-4 flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-700 dark:text-amber-400"
                            :class="{ invisible: !last }"
                            :title="`${nameOver(message)}, a workflow`"
                        >
                            <Zap class="size-3.5" />
                        </span>
                        <img
                            v-else-if="faceOf(message)"
                            :src="faceOf(message) as string"
                            :alt="message.sender?.name"
                            :title="`${message.sender?.name}: written outside, carried in by a workflow`"
                            class="mb-4 size-7 shrink-0 cursor-zoom-in rounded-full object-cover"
                            :class="{ invisible: !last }"
                            referrerpolicy="no-referrer"
                            loading="lazy"
                            data-chat-face
                            @error="broken.add(faceOf(message) as string)"
                            @click="
                                viewing = {
                                    src: faceOf(message) as string,
                                    name: message.sender?.name ?? room.title,
                                }
                            "
                        />
                        <span
                            v-else
                            class="mb-4 flex size-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white"
                            :class="{ invisible: !last }"
                            :style="{ backgroundColor: colourOf(message) }"
                            :title="
                                message.role === 'user'
                                    ? message.author?.name
                                    : `${message.sender?.name}: written outside, carried in by a workflow`
                            "
                        >
                            {{
                                initialsOf(
                                    message.author?.name ??
                                        message.sender?.name ??
                                        '?',
                                )
                            }}
                        </span>
                        <div class="max-w-[85%] min-w-0 sm:max-w-[70%]">
                            <p
                                v-if="first && nameOver(message)"
                                class="text-muted-foreground mb-0.5 px-1 text-xs"
                                data-chat-author
                            >
                                {{ nameOver(message)
                                }}<template v-if="message.internal">
                                    · note, not sent to them</template
                                ><template
                                    v-if="
                                        message.role === 'workflow' &&
                                        !fromOutside(message) &&
                                        message.sender?.by
                                    "
                                >
                                    · for {{ message.sender.by }}</template
                                >
                            </p>
                            <p
                                v-if="message.content && onlyEmoji(message)"
                                class="px-1 text-5xl leading-tight"
                                data-chat-emoji-only
                            >
                                {{ message.content.trim() }}
                            </p>
                            <p
                                v-else-if="message.content"
                                class="w-fit rounded-2xl px-3.5 py-2 text-sm [overflow-wrap:anywhere] whitespace-pre-wrap"
                                :class="[
                                    message.internal
                                        ? NOTE
                                        : message.role === 'workflow' &&
                                            !fromOutside(message)
                                          ? 'border border-amber-500/20 bg-amber-500/5'
                                          : 'bg-muted',
                                    { 'rounded-bl-md': last },
                                ]"
                            >
                                <MessageText
                                    :content="message.content"
                                    :emojis="message.emojis"
                                />
                            </p>
                            <MessageAlbum v-if="album" :files="album" />
                            <MessageFile
                                v-else-if="message.file"
                                :file="message.file"
                                :sticker="message.sticker"
                            />
                            <MessagePlace
                                v-if="message.place"
                                :place="message.place"
                            />
                            <!-- An agent's answer: what it answered from, for the project to check it by -->
                            <AnswerSources
                                v-if="message.sources"
                                :sources="message.sources"
                                class="mt-1 px-1"
                            />
                            <time
                                v-if="last && message.created_at"
                                class="text-muted-foreground mt-0.5 block h-4 px-1 text-[11px] tabular-nums"
                                :datetime="message.created_at"
                                :title="whenOf(message)"
                                data-chat-time
                            >
                                {{ timeOf(message) }}
                            </time>
                        </div>
                        <button
                            v-if="
                                room.desk && room.manage && fromOutside(message)
                            "
                            type="button"
                            class="text-muted-foreground hover:text-foreground mb-5 shrink-0 rounded-md p-1 opacity-50 focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                            title="Make a ticket of this"
                            aria-label="Make a ticket of this"
                            data-test="chat-promote"
                            @click="promoting = message"
                        >
                            <TicketIcon class="size-4" />
                        </button>
                    </div>
                    <div
                        v-else
                        class="max-w-full"
                        :class="first ? 'mt-3' : 'mt-1'"
                        data-chat-assistant
                    >
                        <!-- The same editor and Markdown as a note, only for reading -->
                        <TiptapEditor
                            :content="message.doc"
                            :editable="false"
                            wide
                            class="chat-answer min-h-0! pt-0!"
                        />
                        <p
                            v-if="message.error"
                            class="text-destructive mt-1 text-xs"
                        >
                            Stopped early: {{ message.error }}
                        </p>
                    </div>
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
        <form v-else class="relative px-4 pt-2 pb-3" @submit.prevent="send">
            <!-- Scrolled back: a way down to the latest, saying when there is more -->
            <button
                v-if="!atLatest"
                type="button"
                class="bg-background hover:bg-accent absolute -top-10 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs shadow-md"
                data-chat-latest
                @click="toBottom"
            >
                <ArrowDown class="size-3.5" />
                {{ fresh ? 'New messages' : 'Latest' }}
            </button>
            <div
                v-if="placed"
                class="bg-muted/50 mb-2 flex w-fit max-w-full items-center gap-2.5 rounded-xl border p-1.5 pr-3"
                data-chat-placed
            >
                <span
                    class="bg-background flex size-10 shrink-0 items-center justify-center rounded-lg border text-red-500"
                >
                    <MapPin class="size-4" />
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm">{{
                        placed.title || 'A place'
                    }}</span>
                    <span
                        v-if="placed.address"
                        class="text-muted-foreground block truncate text-xs"
                        >{{ placed.address }}</span
                    >
                </span>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground shrink-0 rounded-full p-0.5"
                    aria-label="Remove the place"
                    @click="placed = null"
                >
                    <X class="size-4" />
                </button>
            </div>
            <!-- What goes with the next message, to look at or take back -->
            <div
                v-if="attached"
                class="bg-muted/50 mb-2 flex w-fit max-w-full items-center gap-2.5 rounded-xl border p-1.5 pr-3"
                data-chat-attached
            >
                <img
                    v-if="preview"
                    :src="preview"
                    alt=""
                    class="size-10 shrink-0 rounded-lg object-cover"
                />
                <span
                    v-else
                    class="bg-background text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-lg border"
                >
                    <Paperclip class="size-4" />
                </span>
                <span class="min-w-0 truncate text-sm">{{
                    attached.name
                }}</span>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground shrink-0 rounded-full p-0.5"
                    aria-label="Remove the file"
                    @click="attached = null"
                >
                    <X class="size-4" />
                </button>
            </div>
            <p
                v-if="answerUntil && !noting"
                class="mb-1.5 px-1 text-xs"
                :class="
                    tooLate
                        ? 'text-amber-700 dark:text-amber-400'
                        : 'text-muted-foreground'
                "
                data-chat-window
            >
                <template v-if="tooLate">
                    More than a day since they last wrote:
                    {{ room.link?.service }} will not take an answer until they
                    write again. A note still works.
                </template>
                <template v-else>
                    {{ room.link?.service }} takes an answer until
                    {{ until.format(answerUntil) }}.
                </template>
            </p>
            <div
                v-if="suggestion && draft.trim()"
                class="bg-muted/40 mb-2 grid gap-1 rounded-lg border px-3 py-2"
                data-suggestion
            >
                <p class="text-muted-foreground text-[11px]">
                    Suggested by {{ suggestion.agent }}, version
                    {{ suggestion.version }}: read it, and its sources, before
                    you send it.
                </p>
                <AnswerSources
                    :sources="suggestion.sources"
                    :knows="suggestion.knows"
                    :looked="suggestion.actions.length > 0"
                />
                <AnswerActions :actions="suggestion.actions" />
            </div>
            <SegmentTabs
                v-if="room.desk"
                :model-value="noting ? 'note' : 'reply'"
                :options="WRITES"
                label="What is written"
                class="mb-1.5"
                @update:model-value="noting = $event === 'note'"
            />
            <div
                class="focus-within:ring-ring/40 flex items-end gap-1.5 rounded-2xl border p-1.5 shadow-xs transition-shadow focus-within:ring-[3px]"
                :class="[
                    noting
                        ? 'border-amber-500/50 bg-amber-50 dark:bg-amber-500/10'
                        : 'bg-background',
                    { 'ring-primary/40 ring-[3px]': dropping },
                ]"
                @dragover.prevent="dropping = attachable"
                @dragleave="dropping = false"
                @drop="
                    dropping = false;
                    take($event.dataTransfer?.files, $event);
                "
            >
                <template v-if="attachable">
                    <input
                        ref="picker"
                        type="file"
                        class="hidden"
                        data-chat-file-input
                        @change="attach"
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                aria-label="Attach a file or a place"
                                title="Attach a file or a place"
                                :disabled="sending"
                                data-chat-attach
                            >
                                <Paperclip />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" side="top">
                            <DropdownMenuItem @select="picker?.click()">
                                <Upload />
                                From this device
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="choosing = true">
                                <HardDrive />
                                From the Drive
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="placing = true">
                                <MapPin />
                                A place
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <DriveFileDialog
                        v-model:open="choosing"
                        :room="room.ref_id"
                        @pick="attachFromDrive"
                    />
                    <PlaceDialog v-model:open="placing" @pick="attachPlace" />
                </template>
                <EmojiPicker
                    :disabled="sending"
                    @pick="addEmoji"
                    @closed="backToWriting"
                />
                <SuggestReply
                    v-if="room.desk && !noting"
                    :room="room.ref_id"
                    :agents="room.desk.agents ?? []"
                    :disabled="sending"
                    @pick="suggested"
                    @failed="failure = $event"
                />
                <SavedReplies
                    v-if="room.desk && room.project"
                    :project="room.project.ref_id"
                    :name="room.contact?.name ?? room.title"
                    :me="page.props.auth.user.name"
                    :draft="draft"
                    :can-manage="room.manage"
                    :disabled="sending"
                    @pick="addEmoji"
                    @closed="backToWriting"
                />
                <textarea
                    ref="input"
                    v-model="draft"
                    rows="1"
                    class="field-sizing-content max-h-48 min-h-9 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none"
                    :placeholder="
                        noting
                            ? 'A note for your project: not sent to them…'
                            : 'Write a message…'
                    "
                    data-chat-input
                    @blur="caret = input?.selectionStart ?? null"
                    @keydown="onKey"
                    @paste="take($event.clipboardData?.files, $event)"
                />
                <Button
                    v-if="sending"
                    type="button"
                    size="icon-sm"
                    aria-label="Stop"
                    @click="stop"
                >
                    <Square />
                </Button>
                <Button
                    v-else
                    type="submit"
                    size="icon-sm"
                    aria-label="Send"
                    :disabled="!draft.trim() && !attached && !placed"
                >
                    <Send />
                </Button>
            </div>
            <p
                class="text-muted-foreground mt-1.5 hidden px-2 text-[11px] sm:block"
            >
                Enter sends · Shift+Enter starts a new line<template
                    v-if="attachable"
                >
                    · a file can be pasted or dropped here</template
                >
            </p>
        </form>

        <DeleteDialog
            v-model:open="removing"
            :name="room.title || 'this chat'"
            description="Everything said in it goes with it. Files sent in it stay in the Drive."
            @confirm="remove"
        />

        <Dialog v-model:open="leaving">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Leave “{{ room.title }}”?</DialogTitle>
                    <DialogDescription>
                        You can join it again from the project.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="leaving = false"
                        >Stay</Button
                    >
                    <Button type="button" @click="leave">Leave</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-if="agents" v-model:open="settingsOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Agent</DialogTitle>
                    <DialogDescription>
                        Who answers in this chat, and how. Leave the connection
                        empty for a chat that only keeps what you write.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="saveSettings">
                    <div class="grid gap-2">
                        <Label for="chat-connection">Connection</Label>
                        <Select
                            :model-value="connection || NONE"
                            @update:model-value="chooseConnection"
                        >
                            <SelectTrigger
                                id="chat-connection"
                                class="w-full"
                                data-connection-select
                            >
                                <SelectValue>
                                    <template v-if="chosenConnection">
                                        {{ chosenConnection.name }}
                                        <span
                                            class="text-muted-foreground text-xs"
                                            >{{ chosenConnection.label }}</span
                                        >
                                    </template>
                                    <template v-else
                                        >None: only keep what I write</template
                                    >
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE">
                                    None: only keep what I write
                                </SelectItem>
                                <SelectItem
                                    v-for="c in connections"
                                    :key="c.ref_id"
                                    :value="c.ref_id"
                                    :data-connection-option="c.name"
                                >
                                    {{ c.name }}
                                    <span
                                        class="text-muted-foreground text-xs"
                                        >{{ c.label }}</span
                                    >
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="!connections.length"
                            class="text-muted-foreground text-xs"
                        >
                            You have none yet.
                            <Link
                                :href="connectionsIndex()"
                                class="text-primary underline underline-offset-4"
                            >
                                Add one in settings.
                            </Link>
                        </p>
                    </div>

                    <div v-if="connection" class="grid gap-2">
                        <Label>Model</Label>
                        <ModelPicker
                            v-model="model"
                            :models="offered"
                            :free="offeredFree"
                            :loading="loadingModels"
                            :error="offeredFailure"
                            :usual="usual()"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="chat-prompt">Instructions</Label>
                        <textarea
                            id="chat-prompt"
                            v-model="prompt"
                            rows="4"
                            maxlength="10000"
                            class="border-input dark:bg-input/30 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            placeholder="e.g. Answer briefly, in the language I write in."
                        />
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="settingsOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
