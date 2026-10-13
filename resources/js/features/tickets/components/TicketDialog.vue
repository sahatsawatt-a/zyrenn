<script setup lang="ts">
import { ImagePlus, MessagesSquare, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/common/InputError.vue';
import MediaPickerDialog from '@/components/media/MediaPickerDialog.vue';
import type { PickedMedia } from '@/components/media/MediaPickerDialog.vue';
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
import { useMediaViewer } from '@/composables/useMediaViewer';
import {
    PRIORITIES,
    STATUSES,
    TYPES,
    blankDraft,
    draftOf,
} from '@/features/tickets/tickets';
import type {
    Ticket,
    TicketDraft,
    TicketPerson,
    TicketStatus,
    TicketType,
} from '@/features/tickets/tickets';
import { formatRelativeTime } from '@/lib/utils';
import TicketPick from './TicketPick.vue';

// One form for a ticket, new or already filed: what it is about, how far
// along it is, who has it, and pictures of it. Somebody who can only look
// reads the same form, with nothing in it to change.
const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    // The ticket being changed; none, for a new one
    ticket: Ticket | null;
    // The kind a new one starts as
    type?: TicketType;
    people: TicketPerson[];
    // The units already in use here, offered as the form is typed in
    units: string[];
    canChange: boolean;
    errors: Record<string, string>;
    busy: boolean;
}>();

const emit = defineEmits<{
    save: [TicketDraft];
    remove: [];
    discuss: [];
}>();

const draft = ref<TicketDraft>(blankDraft());

// A fresh form each time it opens, on whatever it was opened for
watch(open, (now) => {
    if (now) {
        draft.value = props.ticket
            ? draftOf(props.ticket)
            : blankDraft(props.type);
    }
});

const kind = computed(() => TYPES[draft.value.type]);

// Reka's Select can't hold an empty value, so nobody has a name of its own
const NOBODY = 'nobody';

const assignees = computed(() => [
    { value: NOBODY, label: 'Nobody yet' },
    ...props.people.map((person) => ({
        value: String(person.id),
        label: person.name,
    })),
]);

// ------------------------------------------------------------------ Pictures
const picking = ref(false);
const viewer = useMediaViewer();

const addPictures = (picked: PickedMedia[]) => {
    draft.value.pictures = [
        ...draft.value.pictures,
        ...picked.map(({ src, name }) => ({ src, name })),
    ].slice(0, 12);
};

const show = (index: number) =>
    viewer.open(
        draft.value.pictures.map((picture) => ({
            type: 'image' as const,
            src: picture.src,
            alt: picture.name,
        })),
        index,
    );
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[calc(100dvh-2rem)] flex-col gap-0 p-0 sm:max-h-[90dvh] sm:max-w-2xl"
            data-test="ticket-dialog"
        >
            <DialogHeader class="border-b px-6 py-5 text-left">
                <DialogTitle>
                    {{ ticket ? `#${ticket.number}` : 'New ticket' }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="ticket">
                        Filed
                        {{ formatRelativeTime(ticket.created_at) }}
                        <template v-if="ticket.created_by">
                            by {{ ticket.created_by.name }}
                        </template>
                    </template>
                    <template v-else>
                        Something to fix, or something to add.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <form
                id="ticket-form"
                class="min-h-0 flex-1 overflow-y-auto px-6 py-6"
                @submit.prevent="emit('save', draft)"
            >
                <fieldset :disabled="!canChange" class="min-w-0 space-y-6">
                    <!-- What kind it is, first: it words the rest -->
                    <div class="ticket-segments" role="radiogroup">
                        <button
                            v-for="(option, value) in TYPES"
                            :key="value"
                            type="button"
                            role="radio"
                            :aria-checked="draft.type === value"
                            :class="{ 'is-on': draft.type === value }"
                            :data-test="`ticket-type-${value}`"
                            @click="draft.type = value"
                        >
                            <component
                                :is="option.icon"
                                class="size-4"
                                :class="option.tone"
                            />
                            {{ option.label }}
                        </button>
                    </div>

                    <div class="grid gap-2">
                        <Label for="ticket-title">Title</Label>
                        <Input
                            id="ticket-title"
                            v-model="draft.title"
                            required
                            maxlength="200"
                            autocomplete="off"
                            :placeholder="
                                draft.type === 'bug'
                                    ? 'e.g. Resizing a shape shakes'
                                    : 'e.g. Shapes with no fill'
                            "
                            data-test="ticket-title"
                        />
                        <InputError :message="errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ticket-body">{{ kind.asks }}</Label>
                        <textarea
                            id="ticket-body"
                            v-model="draft.body"
                            rows="5"
                            maxlength="20000"
                            class="bg-background dark:bg-input/30 border-input w-full rounded-md border px-3 py-2.5 text-sm leading-relaxed"
                            :placeholder="kind.hint"
                            data-test="ticket-body"
                        />
                        <InputError :message="errors.body" />
                    </div>

                    <div class="grid gap-x-5 gap-y-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="ticket-unit">Part</Label>
                            <Input
                                id="ticket-unit"
                                v-model="draft.unit"
                                maxlength="64"
                                list="ticket-units"
                                autocomplete="off"
                                placeholder="Which part it is about"
                                data-test="ticket-unit"
                            />
                            <datalist id="ticket-units">
                                <option
                                    v-for="unit in units"
                                    :key="unit"
                                    :value="unit"
                                />
                            </datalist>
                            <InputError :message="errors.unit" />
                        </div>

                        <div class="grid gap-2">
                            <Label>Priority</Label>
                            <div class="ticket-segments" role="radiogroup">
                                <button
                                    v-for="option in PRIORITIES"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="
                                        draft.priority === option.value
                                    "
                                    :class="{
                                        'is-on':
                                            draft.priority === option.value,
                                    }"
                                    :data-test="`ticket-priority-${option.value}`"
                                    @click="draft.priority = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="ticket-assignee">Given to</Label>
                            <TicketPick
                                id="ticket-assignee"
                                :model-value="
                                    String(draft.assigned_to ?? NOBODY)
                                "
                                :options="assignees"
                                label="Given to"
                                :disabled="!canChange"
                                data-test="ticket-assignee"
                                @update:model-value="
                                    draft.assigned_to =
                                        $event === NOBODY
                                            ? null
                                            : Number($event)
                                "
                            />
                            <InputError :message="errors.assigned_to" />
                        </div>

                        <div v-if="ticket" class="grid gap-2">
                            <Label for="ticket-status">Status</Label>
                            <TicketPick
                                id="ticket-status"
                                :model-value="draft.status"
                                :options="STATUSES"
                                label="Status"
                                :disabled="!canChange"
                                data-test="ticket-status"
                                @update:model-value="
                                    draft.status = $event as TicketStatus
                                "
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label>Pictures</Label>
                        <div class="flex flex-wrap gap-2">
                            <div
                                v-for="(picture, index) in draft.pictures"
                                :key="`${picture.src}-${index}`"
                                class="group relative"
                            >
                                <button
                                    type="button"
                                    class="block overflow-hidden rounded-md border"
                                    :title="picture.name || 'Picture'"
                                    data-test="ticket-picture"
                                    @click="show(index)"
                                >
                                    <img
                                        :src="picture.src"
                                        :alt="picture.name"
                                        class="size-20 object-cover"
                                        loading="lazy"
                                    />
                                </button>
                                <button
                                    v-if="canChange"
                                    type="button"
                                    class="bg-background absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full border shadow-sm"
                                    title="Take this picture off"
                                    @click="draft.pictures.splice(index, 1)"
                                >
                                    <X class="size-3" />
                                </button>
                            </div>
                            <button
                                v-if="canChange && draft.pictures.length < 12"
                                type="button"
                                class="text-muted-foreground hover:text-foreground hover:border-foreground/40 flex size-20 flex-col items-center justify-center gap-1 rounded-md border border-dashed text-xs"
                                data-test="ticket-add-picture"
                                @click="picking = true"
                            >
                                <ImagePlus class="size-4" />
                                Add
                            </button>
                            <p
                                v-if="!canChange && !draft.pictures.length"
                                class="text-muted-foreground text-sm"
                            >
                                None
                            </p>
                        </div>
                        <InputError :message="errors.pictures" />
                    </div>
                </fieldset>
            </form>

            <DialogFooter
                class="flex-row flex-wrap items-center gap-2 border-t px-6 py-4 sm:justify-start"
            >
                <Button
                    v-if="ticket"
                    type="button"
                    variant="outline"
                    :title="
                        ticket.chat
                            ? 'Open the chat about this ticket'
                            : 'Start a chat about this ticket'
                    "
                    data-test="ticket-discuss"
                    @click="emit('discuss')"
                >
                    <MessagesSquare />
                    <!-- On a phone the picture says it: the row is short -->
                    <span class="hidden sm:inline">{{
                        ticket.chat ? 'Open chat' : 'Discuss'
                    }}</span>
                </Button>
                <Button
                    v-if="ticket && canChange"
                    type="button"
                    variant="ghost"
                    size="icon"
                    title="Delete this ticket"
                    data-test="ticket-delete"
                    @click="emit('remove')"
                >
                    <Trash2 />
                </Button>
                <span class="flex-1" />
                <Button type="button" variant="secondary" @click="open = false">
                    {{ canChange ? 'Cancel' : 'Close' }}
                </Button>
                <Button
                    v-if="canChange"
                    type="submit"
                    form="ticket-form"
                    :disabled="busy"
                    data-test="ticket-save"
                >
                    {{ ticket ? 'Save' : 'File ticket' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <MediaPickerDialog
        v-model:open="picking"
        destination="the ticket"
        @insert="addPictures"
    />
</template>

<style scoped>
.ticket-segments {
    display: inline-flex;
    width: fit-content;
    max-width: 100%;
    padding: 2px;
    gap: 2px;
    background-color: var(--muted);
    border-radius: var(--radius-md);
}
.ticket-segments button {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    height: 2rem;
    padding: 0 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--muted-foreground);
    border-radius: calc(var(--radius-md) - 2px);
    cursor: pointer;
}
.ticket-segments button.is-on {
    color: var(--foreground);
    background-color: var(--background);
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.12);
}
fieldset:disabled .ticket-segments button {
    cursor: default;
}
</style>
