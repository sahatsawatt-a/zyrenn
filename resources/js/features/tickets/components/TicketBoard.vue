<script setup lang="ts">
import { computed, ref } from 'vue';
import { STATUSES } from '@/features/tickets/tickets';
import type { Ticket, TicketStatus } from '@/features/tickets/tickets';
import TicketCard from './TicketCard.vue';

// The tickets as columns, one for each step on the way to done. A card is
// dragged from one to the next -- or, where there is nothing to drag with,
// opened and given its status there.
const props = defineProps<{ tickets: Ticket[]; canChange: boolean }>();

const emit = defineEmits<{
    open: [Ticket];
    status: [Ticket, TicketStatus];
}>();

// What was looked at and turned down is not on the way anywhere
const columns = computed(() =>
    STATUSES.filter((status) => status.value !== 'dropped').map((status) => ({
        ...status,
        tickets: props.tickets.filter(
            (ticket) => ticket.status === status.value,
        ),
    })),
);

const dropped = computed(
    () => props.tickets.filter((ticket) => ticket.status === 'dropped').length,
);

const dragging = ref<Ticket | null>(null);
const over = ref<TicketStatus | null>(null);

const drop = (status: TicketStatus) => {
    const ticket = dragging.value;

    dragging.value = null;
    over.value = null;

    if (ticket && ticket.status !== status) {
        emit('status', ticket, status);
    }
};
</script>

<template>
    <div data-test="ticket-board">
        <!-- Side by side where there is room; on a phone, a column a screen,
             slid between -->
        <div
            class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-2 md:mx-0 md:px-0"
        >
            <section
                v-for="column in columns"
                :key="column.value"
                class="bg-muted/40 flex min-h-40 w-[85%] shrink-0 snap-center flex-col rounded-xl border p-2 transition-colors md:w-auto md:min-w-0 md:flex-1"
                :class="{
                    'border-primary bg-primary/5': over === column.value,
                }"
                :data-status="column.value"
                @dragover.prevent="over = column.value"
                @dragleave="over === column.value && (over = null)"
                @drop.prevent="drop(column.value)"
            >
                <h2
                    class="text-muted-foreground flex items-center gap-1.5 px-1.5 pt-1 pb-2 text-xs font-semibold tracking-wide uppercase"
                >
                    <component
                        :is="column.icon"
                        class="size-3.5"
                        :class="column.tone"
                    />
                    {{ column.label }}
                    <span class="font-normal tabular-nums">{{
                        column.tickets.length
                    }}</span>
                </h2>

                <div class="flex flex-col gap-2">
                    <button
                        v-for="ticket in column.tickets"
                        :key="ticket.ref_id"
                        type="button"
                        class="bg-background hover:border-foreground/30 rounded-lg border px-3 py-2.5 text-left shadow-xs"
                        :class="{ 'opacity-50': dragging === ticket }"
                        :draggable="canChange"
                        data-test="ticket-card"
                        @dragstart="dragging = ticket"
                        @dragend="((dragging = null), (over = null))"
                        @click="emit('open', ticket)"
                    >
                        <TicketCard :ticket="ticket" />
                    </button>
                </div>
            </section>
        </div>

        <p v-if="dropped" class="text-muted-foreground mt-2 text-xs">
            {{ dropped }} turned down {{ dropped === 1 ? 'is' : 'are' }} in the
            list, under “Won’t do”.
        </p>
    </div>
</template>
