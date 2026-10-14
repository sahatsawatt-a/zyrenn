<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Link2,
    Lock,
    MessagesSquare,
    Pencil,
    Plus,
    Trash2,
    UserRound,
    UserRoundMinus,
    UserRoundPlus,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { getInitials } from '@/composables/useInitials';
import { SERVICES } from '@/features/chat/desk';
import type { DeskContact } from '@/features/chat/desk';
import { show, update } from '@/routes/chats';
import {
    leave as notSame,
    update as nameContact,
} from '@/routes/chats/contact';
import FaceViewer from './FaceViewer.vue';
import SameAsDialog from './SameAsDialog.vue';

// About a chat, opened beside it like a contact's card: its face, what it
// is linked to, and what is known about it. A workflow keeps some of that --
// who an outside chat is with: their name, their id there, their language --
// and those are the workflow's to set, shown but not changed here. The rest
// are notes of one's own, added and changed by whoever may change the room.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    room: {
        ref_id: string;
        title: string;
        picture?: string | null;
        details?: Record<string, string>;
        // The names among the details a workflow keeps
        kept?: string[];
        link: { service: string; through: string | null } | null;
        manage: boolean;
        messages?: number;
        created_at?: string | null;
        // A customer's chat: who it is with, on every service
        contact?: DeskContact | null;
    };
    // The face to show when the room keeps none of its own
    face?: string | null;
}>();

// Its picture, larger: opened from in here, so it comes up over the panel
const viewing = ref(false);

const picture = computed(() => props.room.picture || props.face || null);

type Row = { name: string; value: string };

const all = computed<Row[]>(() =>
    Object.entries(props.room.details ?? {}).map(([name, value]) => ({
        name,
        value: String(value),
    })),
);
const isKept = (name: string) => (props.room.kept ?? []).includes(name);
// Who they are first, how to find them in the service's own terms last
const place = (name: string) =>
    name === 'name' ? 0 : name.endsWith('_id') ? 2 : 1;
const kept = computed(() =>
    all.value
        .filter((row) => isKept(row.name))
        .sort(
            (a, b) =>
                place(a.name) - place(b.name) || a.name.localeCompare(b.name),
        ),
);
const own = computed(() => all.value.filter((row) => !isKept(row.name)));

// --- how a detail reads ---

// "line_id" is kept so a workflow can read it; a person reads "Line id"
const labelOf = (name: string) => {
    const words = name.replace(/_/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
};

const languages =
    typeof Intl.DisplayNames === 'function'
        ? new Intl.DisplayNames(undefined, { type: 'language' })
        : null;

// A language is kept as its code: "th" reads as Thai
const shown = (row: Row) => {
    if (
        row.name === 'language' &&
        /^[a-z]{2,3}(-[A-Za-z]+)?$/.test(row.value)
    ) {
        try {
            const named = languages?.of(row.value);

            return named && named !== row.value
                ? `${named} (${row.value})`
                : row.value;
        } catch {
            return row.value;
        }
    }

    return row.value;
};

const isAddress = (value: string) => /^https?:\/\//.test(value);

const copied = ref('');

async function copy(row: Row) {
    await navigator.clipboard.writeText(row.value);
    copied.value = row.name;
    setTimeout(() => {
        if (copied.value === row.name) {
            copied.value = '';
        }
    }, 1500);
}

// --- who a customer is, on every service ---

const contact = computed(() => props.room.contact ?? null);
const naming = ref(false);
const name = ref('');
const joining = ref(false);

function startNaming() {
    name.value = contact.value?.name ?? '';
    naming.value = true;
}

function saveName() {
    const named = name.value.trim();

    if (!named || named === contact.value?.name) {
        naming.value = false;

        return;
    }

    router.patch(
        nameContact(props.room.ref_id).url,
        { name: named },
        {
            preserveScroll: true,
            only: ['room'],
            onSuccess: () => (naming.value = false),
        },
    );
}

// Found not to be the same person: this chat is someone of its own again
const apart = () =>
    router.delete(notSame(props.room.ref_id).url, {
        preserveScroll: true,
        only: ['room'],
    });

const serviceName = (service: string | null) =>
    service ? (SERVICES[service] ?? service) : 'Not linked';

// --- notes of one's own ---

const editing = ref(false);
const rows = ref<Row[]>([]);
const saving = ref(false);
const problem = ref('');

// Whatever is typed for a name is kept as one a workflow can read:
// "VIP tier" as vip_tier
const nameFrom = (typed: string) =>
    typed
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^(\d)/, '_$1')
        .slice(0, 40);

const wrong = computed(() => {
    const names = rows.value
        .filter((row) => row.name.trim() || row.value.trim())
        .map((row) => nameFrom(row.name));

    if (names.some((name) => name === '')) {
        return 'Give each one a name.';
    }

    if (names.some((name) => isKept(name))) {
        return 'A workflow keeps one of that name already.';
    }

    return new Set(names).size !== names.length
        ? 'Two of them have the same name.'
        : '';
});

function edit(add = false) {
    rows.value = own.value.map((row) => ({
        name: labelOf(row.name),
        value: row.value,
    }));

    if (add || !rows.value.length) {
        rows.value.push({ name: '', value: '' });
    }

    problem.value = '';
    editing.value = true;
}

function save() {
    if (wrong.value) {
        return;
    }

    saving.value = true;
    problem.value = '';

    router.patch(
        update(props.room.ref_id).url,
        {
            details: Object.fromEntries(
                rows.value
                    .filter((row) => row.name.trim() && row.value.trim())
                    .map((row) => [nameFrom(row.name), row.value.trim()]),
            ),
        },
        {
            preserveScroll: true,
            only: ['room'],
            onSuccess: () => (editing.value = false),
            onError: () => (problem.value = 'Those could not be kept.'),
            onFinish: () => (saving.value = false),
        },
    );
}

// Closed and opened again, it shows what is kept, not what was half typed
watch(open, (isOpen) => {
    if (!isOpen) {
        editing.value = false;
    }
});

const since = computed(() =>
    props.room.created_at
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              new Date(props.room.created_at),
          )
        : '',
);

const service = computed(() =>
    props.room.link
        ? props.room.link.service === 'line'
            ? 'LINE'
            : labelOf(props.room.link.service)
        : '',
);
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-sm"
            data-chat-about
        >
            <!-- Who or what this chat is -->
            <SheetHeader class="items-center gap-3 px-6 pt-10 pb-6 text-center">
                <button
                    v-if="picture"
                    type="button"
                    class="cursor-zoom-in rounded-full transition-opacity hover:opacity-90"
                    aria-label="Open the picture"
                    data-chat-about-picture
                    @click="viewing = true"
                >
                    <img
                        :src="picture"
                        alt=""
                        class="ring-background size-24 rounded-full object-cover shadow-md ring-4"
                        referrerpolicy="no-referrer"
                    />
                </button>
                <span
                    v-else
                    class="bg-muted text-muted-foreground flex size-24 items-center justify-center rounded-full text-2xl font-semibold"
                >
                    {{ getInitials(room.title || '?') }}
                </span>
                <div class="max-w-full min-w-0">
                    <SheetTitle class="truncate text-lg">{{
                        room.title || 'New chat'
                    }}</SheetTitle>
                    <SheetDescription
                        class="mt-1.5 flex flex-wrap items-center justify-center gap-1.5"
                    >
                        <span
                            v-if="room.link"
                            class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-xs text-sky-700 dark:text-sky-400"
                        >
                            <Link2 class="size-3" />
                            {{ service
                            }}<template v-if="room.link.through">
                                · {{ room.link.through }}</template
                            >
                        </span>
                        <span v-else class="text-xs"
                            >Not linked to a chat outside</span
                        >
                    </SheetDescription>
                </div>
                <p
                    class="text-muted-foreground flex items-center justify-center gap-3 text-xs"
                >
                    <span
                        v-if="room.messages !== undefined"
                        class="inline-flex items-center gap-1"
                    >
                        <MessagesSquare class="size-3.5" />
                        {{ room.messages }}
                        {{ room.messages === 1 ? 'message' : 'messages' }}
                    </span>
                    <span v-if="since">Since {{ since }}</span>
                </p>
            </SheetHeader>

            <div class="flex-1 space-y-6 px-4 pb-6">
                <!-- Who a customer is, and their chats on every service -->
                <section v-if="contact" data-chat-contact>
                    <h3
                        class="text-muted-foreground mb-2 flex items-center gap-1.5 px-2 text-xs font-medium tracking-wide uppercase"
                    >
                        <UserRound class="size-3" />
                        Customer
                    </h3>
                    <div class="bg-card divide-y rounded-xl border">
                        <form
                            v-if="naming"
                            class="flex items-center gap-1.5 p-2"
                            @submit.prevent="saveName"
                        >
                            <Input
                                v-model="name"
                                maxlength="120"
                                class="h-8 flex-1 text-sm"
                                aria-label="Their name"
                                data-test="contact-name-input"
                            />
                            <Button type="submit" size="icon-sm">
                                <Check />
                            </Button>
                        </form>
                        <div v-else class="flex items-center gap-2 px-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-medium"
                                    data-test="contact-name"
                                >
                                    {{ contact.name }}
                                </p>
                                <p
                                    v-if="contact.key"
                                    class="text-muted-foreground truncate text-[11px]"
                                    title="What a workflow knows them by on every service"
                                >
                                    Known as {{ contact.key }}
                                </p>
                            </div>
                            <button
                                v-if="room.manage"
                                type="button"
                                class="text-muted-foreground hover:text-foreground rounded-md p-1.5"
                                aria-label="Rename them"
                                data-test="contact-rename"
                                @click="startNaming"
                            >
                                <Pencil class="size-3.5" />
                            </button>
                        </div>
                        <ul>
                            <li
                                v-for="chat in contact.rooms"
                                :key="chat.ref_id"
                                class="flex items-center gap-2 px-3 py-2"
                                data-test="contact-chat"
                            >
                                <span
                                    class="shrink-0 rounded-full bg-sky-500/10 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:text-sky-400"
                                    >{{ serviceName(chat.service) }}</span
                                >
                                <span
                                    v-if="chat.ref_id === room.ref_id"
                                    class="min-w-0 flex-1 truncate text-sm"
                                    >{{ chat.title }}
                                    <span class="text-muted-foreground text-xs"
                                        >· this chat</span
                                    ></span
                                >
                                <Link
                                    v-else
                                    :href="show(chat.ref_id)"
                                    class="text-primary min-w-0 flex-1 truncate text-sm underline-offset-2 hover:underline"
                                    >{{ chat.title }}</Link
                                >
                            </li>
                        </ul>
                    </div>
                    <div v-if="room.manage" class="mt-2 grid gap-1.5">
                        <Button
                            variant="outline"
                            size="sm"
                            data-test="contact-same"
                            @click="joining = true"
                        >
                            <UserRoundPlus />
                            The same person as another chat…
                        </Button>
                        <Button
                            v-if="contact.rooms.length > 1"
                            variant="ghost"
                            size="sm"
                            data-test="contact-apart"
                            @click="apart"
                        >
                            <UserRoundMinus />
                            This chat is someone else
                        </Button>
                    </div>
                    <SameAsDialog
                        v-model:open="joining"
                        :room="room.ref_id"
                        :name="contact.name"
                    />
                </section>

                <!-- What a workflow keeps: shown, copied, never changed here -->
                <section v-if="kept.length" data-chat-kept>
                    <h3
                        class="text-muted-foreground mb-2 flex items-center gap-1.5 px-2 text-xs font-medium tracking-wide uppercase"
                    >
                        <Lock class="size-3" />
                        {{ service ? `From ${service}` : 'Kept by a workflow' }}
                    </h3>
                    <dl class="bg-card divide-y rounded-xl border">
                        <div
                            v-for="row in kept"
                            :key="row.name"
                            class="group flex items-center gap-3 px-3 py-2.5"
                            :data-detail="row.name"
                        >
                            <div class="min-w-0 flex-1">
                                <dt class="text-muted-foreground text-[11px]">
                                    {{ labelOf(row.name) }}
                                </dt>
                                <dd
                                    class="text-sm [overflow-wrap:anywhere]"
                                    :class="{
                                        'font-mono text-xs':
                                            row.name.endsWith('_id'),
                                    }"
                                >
                                    {{ shown(row) }}
                                </dd>
                            </div>
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground shrink-0 rounded-md p-1.5 opacity-60 group-hover:opacity-100"
                                :aria-label="`Copy ${labelOf(row.name)}`"
                                :title="copied === row.name ? 'Copied' : 'Copy'"
                                @click="copy(row)"
                            >
                                <Check
                                    v-if="copied === row.name"
                                    class="size-3.5 text-emerald-600"
                                />
                                <Copy v-else class="size-3.5" />
                            </button>
                        </div>
                    </dl>
                    <p class="text-muted-foreground mt-1.5 px-2 text-[11px]">
                        Kept up to date by a workflow, so it can't be changed
                        here.
                    </p>
                </section>

                <!-- Notes of one's own -->
                <section data-chat-notes>
                    <div class="mb-2 flex items-center px-2">
                        <h3
                            class="text-muted-foreground flex-1 text-xs font-medium tracking-wide uppercase"
                        >
                            {{
                                contact
                                    ? `Notes about ${contact.name}`
                                    : 'Your notes'
                            }}
                        </h3>
                        <button
                            v-if="room.manage && !editing && own.length"
                            type="button"
                            class="text-primary inline-flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                            data-chat-about-edit
                            @click="edit()"
                        >
                            <Pencil class="size-3" />
                            Edit
                        </button>
                    </div>

                    <template v-if="!editing">
                        <dl
                            v-if="own.length"
                            class="bg-card divide-y rounded-xl border"
                            data-chat-details
                        >
                            <div
                                v-for="row in own"
                                :key="row.name"
                                class="px-3 py-2.5"
                                :data-detail="row.name"
                            >
                                <dt class="text-muted-foreground text-[11px]">
                                    {{ labelOf(row.name) }}
                                </dt>
                                <dd class="text-sm [overflow-wrap:anywhere]">
                                    <a
                                        v-if="isAddress(row.value)"
                                        :href="row.value"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-primary underline-offset-2 hover:underline"
                                        >{{ row.value }}</a
                                    >
                                    <template v-else>{{ row.value }}</template>
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-else
                            class="text-muted-foreground rounded-xl border border-dashed px-3 py-4 text-center text-xs"
                            data-chat-details
                        >
                            {{
                                room.manage
                                    ? 'Nothing noted yet: a phone number, what they like, where they are from.'
                                    : 'Nothing noted.'
                            }}
                        </p>
                        <Button
                            v-if="room.manage"
                            variant="outline"
                            size="sm"
                            class="mt-2 w-full"
                            data-chat-detail-add
                            @click="edit(true)"
                        >
                            <Plus />
                            Add a note
                        </Button>
                    </template>

                    <form
                        v-else
                        class="grid gap-2"
                        data-chat-details-form
                        @submit.prevent="save"
                    >
                        <div
                            v-for="(row, at) in rows"
                            :key="at"
                            class="bg-card grid gap-1.5 rounded-xl border p-2"
                        >
                            <div class="flex items-center gap-1.5">
                                <Input
                                    v-model="row.name"
                                    placeholder="What it is: Phone, Birthday…"
                                    class="h-8 min-w-0 flex-1 text-xs font-medium"
                                    aria-label="Name"
                                />
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="ghost"
                                    aria-label="Remove this note"
                                    @click="rows.splice(at, 1)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                            <Input
                                v-model="row.value"
                                placeholder="The note itself"
                                class="h-8 text-sm"
                                aria-label="Value"
                            />
                        </div>
                        <button
                            type="button"
                            class="text-primary inline-flex items-center gap-1 justify-self-start px-2 text-xs underline-offset-2 hover:underline"
                            @click="rows.push({ name: '', value: '' })"
                        >
                            <Plus class="size-3" />
                            Another
                        </button>
                        <p
                            v-if="wrong || problem"
                            class="text-destructive px-2 text-xs"
                        >
                            {{ wrong || problem }}
                        </p>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="editing = false"
                            >
                                <X />
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="saving || !!wrong"
                                data-chat-details-save
                            >
                                <Check />
                                Save
                            </Button>
                        </div>
                    </form>
                </section>
            </div>

            <FaceViewer
                v-model:open="viewing"
                :src="picture"
                :name="room.title"
            />
        </SheetContent>
    </Sheet>
</template>
