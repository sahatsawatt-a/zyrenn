<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import { STATUSES } from '@/features/tickets/tickets';
import type { Ticket, TicketStatus } from '@/features/tickets/tickets';
import TicketCard from './TicketCard.vue';
import TicketPick from './TicketPick.vue';

// The tickets as a list, under how far along each is. What is finished is
// folded away: the list is for what is left to do.
const props = defineProps<{ tickets: Ticket[]; canChange: boolean }>();

const emit = defineEmits<{
    open: [Ticket];
    status: [Ticket, TicketStatus];
}>();

const groups = computed(() =>
    STATUSES.map((status) => ({
        ...status,
        tickets: props.tickets.filter(
            (ticket) => ticket.status === status.value,
        ),
    })).filter((group) => group.tickets.length),
);

const folded = ref<Record<string, boolean>>({ done: true, dropped: true });
</script>

<template>
    <div class="space-y-4" data-test="ticket-list">
        <section
            v-for="group in groups"
            :key="group.value"
            :data-status="group.value"
        >
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground mb-1.5 flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase"
                :aria-expanded="!folded[group.value]"
                @click="folded[group.value] = !folded[group.value]"
            >
                <ChevronRight
                    class="size-3.5 transition-transform"
                    :class="{ 'rotate-90': !folded[group.value] }"
                />
                <component
                    :is="group.icon"
                    class="size-3.5"
                    :class="group.tone"
                />
                {{ group.label }}
                <span class="font-normal tabular-nums">{{
                    group.tickets.length
                }}</span>
            </button>

            <ul
                v-if="!folded[group.value]"
                class="divide-y overflow-hidden rounded-xl border"
            >
                <li
                    v-for="ticket in group.tickets"
                    :key="ticket.ref_id"
                    class="hover:bg-muted/50 flex items-center gap-2 pr-2"
                    data-test="ticket-row"
                >
                    <button
                        type="button"
                        class="min-w-0 flex-1 px-3 py-2.5 text-left"
                        @click="emit('open', ticket)"
                    >
                        <TicketCard :ticket="ticket" />
                    </button>
                    <TicketPick
                        v-if="canChange"
                        :model-value="ticket.status"
                        :options="STATUSES"
                        :label="`Status of #${ticket.number}`"
                        compact
                        class="shrink-0"
                        data-test="ticket-row-status"
                        @update:model-value="
                            emit('status', ticket, $event as TicketStatus)
                        "
                    />
                </li>
            </ul>
        </section>
    </div>
</template>
