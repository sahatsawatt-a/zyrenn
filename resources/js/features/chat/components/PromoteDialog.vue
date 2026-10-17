<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Ticket } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/common/InputError.vue';
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
import TicketPick from '@/features/tickets/components/TicketPick.vue';
import { TYPES } from '@/features/tickets/tickets';
import type { TicketType } from '@/features/tickets/tickets';
import { store as promote } from '@/routes/chats/tickets';

// A bug or a feature a customer asked for, made a ticket of the project's:
// what they said goes in it, with who said it and where, and the chat keeps
// a note of which ticket came of it.

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    room: string;
    // What they said, and who they are on which service
    message: { id: number; content: string } | null;
    customer: string;
    service: string | null;
    // The parts of the project's product it can be about
    parts?: { ref_id: string; code: string; name: string; kind: string }[];
}>();

const type = ref<TicketType>('bug');
const title = ref('');
const body = ref('');
// Which part it is about, when someone knows: the bug counts against it
const NONE = '-';
const part = ref(NONE);
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const TYPE_OPTIONS = (Object.keys(TYPES) as TicketType[]).map((value) => ({
    value,
    label: TYPES[value].label,
    icon: TYPES[value].icon,
    tone: TYPES[value].tone,
    test: `promote-${value}`,
}));

// Filled from the message each time it opens: its first line as the title
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const said = props.message?.content.trim() ?? '';
    // "Ada on LINE" says where already
    const where =
        props.service && !props.customer.endsWith(props.service)
            ? ` on ${props.service}`
            : '';

    title.value = (said.split('\n')[0] ?? '').slice(0, 200);
    body.value = said ? `${said}\n\nAsked by ${props.customer}${where}.` : '';
    part.value = NONE;
    errors.value = {};
});

const partOptions = computed(() => [
    { value: NONE, label: 'Not sure, or none' },
    ...(props.parts ?? []).map((one) => ({
        value: one.ref_id,
        label: `${one.code} ${one.name}`,
    })),
]);

function make() {
    busy.value = true;
    router.post(
        promote(props.room).url,
        {
            type: type.value,
            title: title.value,
            body: body.value,
            message: props.message?.id ?? null,
            element: part.value === NONE ? null : part.value,
        },
        {
            preserveScroll: true,
            only: ['room', 'messages'],
            onSuccess: () => (open.value = false),
            onError: (problems) => (errors.value = problems),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-test="promote-dialog">
            <DialogHeader>
                <DialogTitle>Make a ticket</DialogTitle>
                <DialogDescription>
                    Filed in the project's tickets, pointing back at this chat.
                    {{ customer }} is not told.
                </DialogDescription>
            </DialogHeader>

            <form
                novalidate
                id="promote-form"
                class="grid gap-4"
                @submit.prevent="make"
            >
                <SegmentTabs v-model="type" :options="TYPE_OPTIONS" />
                <div class="grid gap-2">
                    <Label for="promote-title">Title</Label>
                    <Input
                        id="promote-title"
                        v-model="title"
                        required
                        maxlength="200"
                        autocomplete="off"
                        data-test="promote-title"
                    />
                    <InputError :message="errors.title" />
                </div>
                <div v-if="parts?.length" class="grid gap-2">
                    <Label for="promote-part">Part of the product</Label>
                    <TicketPick
                        id="promote-part"
                        v-model="part"
                        :options="partOptions"
                        label="Part of the product"
                        data-test="promote-part"
                    />
                    <InputError :message="errors.element" />
                </div>
                <div class="grid gap-2">
                    <Label for="promote-body">What they asked</Label>
                    <textarea
                        id="promote-body"
                        v-model="body"
                        rows="5"
                        maxlength="20000"
                        class="border-input focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                    />
                </div>
            </form>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="open = false">
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="promote-form"
                    :disabled="busy || !title.trim()"
                    data-test="promote-save"
                >
                    <Ticket />
                    Make ticket
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
