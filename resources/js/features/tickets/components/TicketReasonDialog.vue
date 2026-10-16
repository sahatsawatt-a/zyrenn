<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { REASONS, statusOf } from '@/features/tickets/tickets';
import type { Ticket, TicketStatus } from '@/features/tickets/tickets';

// Asked when a ticket is dragged or switched to on hold or won't do from the
// list or the board: it goes there only with a word on why.
const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    ticket: Ticket | null;
    status: TicketStatus;
    error?: string;
    busy?: boolean;
}>();

const emit = defineEmits<{ confirm: [string] }>();

const reason = ref('');
const asking = computed(() => REASONS[props.status]);

watch(open, (now) => {
    if (now) {
        // Back on hold, what it waited on before is a fair start
        reason.value = props.ticket?.reason ?? '';
    }
});

const send = () => {
    if (reason.value.trim()) {
        emit('confirm', reason.value.trim());
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" data-test="ticket-reason-dialog">
            <DialogHeader>
                <DialogTitle>
                    #{{ ticket?.number }} to “{{ statusOf(status).label }}”
                </DialogTitle>
                <DialogDescription>{{ ticket?.title }}</DialogDescription>
            </DialogHeader>
            <form
                id="ticket-reason-form"
                class="grid gap-2"
                @submit.prevent="send"
            >
                <Label for="ticket-move-reason">{{ asking?.asks }}</Label>
                <textarea
                    id="ticket-move-reason"
                    v-model="reason"
                    rows="3"
                    required
                    maxlength="500"
                    class="bg-background dark:bg-input/30 border-input w-full rounded-md border px-3 py-2.5 text-sm leading-relaxed"
                    :placeholder="asking?.hint"
                    data-test="ticket-move-reason"
                    @keydown.enter.exact.prevent="send"
                />
                <InputError :message="error" />
            </form>
            <DialogFooter>
                <Button type="button" variant="secondary" @click="open = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="ticket-reason-form"
                    :disabled="busy || !reason.trim()"
                    data-test="ticket-move-confirm"
                    >Move</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
