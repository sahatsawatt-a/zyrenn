<script setup lang="ts">
import {
    ChevronsUp,
    Image as ImageIcon,
    MessagesSquare,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';
import Tip from '@/components/common/Tip.vue';
import { TYPES, isVideo } from '@/features/tickets/tickets';
import type { Ticket } from '@/features/tickets/tickets';
import { getInitials } from '@/composables/useInitials';

// A ticket at a glance, the same in the list and on the board: what kind,
// its number and title, and under them only what there is to say -- the part
// it is about, that it is pressing, its pictures and videos, who has it.
const props = defineProps<{ ticket: Ticket }>();

const kind = computed(() => TYPES[props.ticket.type]);

const videos = computed(() => props.ticket.pictures.filter(isVideo).length);
const pictures = computed(() => props.ticket.pictures.length - videos.value);
</script>

<template>
    <div class="flex min-w-0 items-start gap-2.5">
        <component
            :is="kind.icon"
            class="mt-0.5 size-4 shrink-0"
            :class="kind.tone"
            :aria-label="kind.label"
        />
        <div class="min-w-0 flex-1">
            <p
                class="text-sm leading-snug font-medium break-words"
                :class="{
                    'text-muted-foreground line-through':
                        ticket.status === 'dropped',
                }"
            >
                <span class="text-muted-foreground font-normal tabular-nums"
                    >#{{ ticket.number }}</span
                >
                {{ ticket.title }}
            </p>
            <!-- Why it waits, or why not: what anyone looking asks first -->
            <p
                v-if="ticket.reason"
                class="text-muted-foreground mt-0.5 line-clamp-2 text-xs break-words"
                data-test="ticket-reason-line"
            >
                {{ ticket.reason }}
            </p>
            <div
                v-if="
                    ticket.unit ||
                    ticket.priority === 'high' ||
                    ticket.pictures.length ||
                    ticket.chat
                "
                class="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs"
            >
                <span
                    v-if="ticket.unit"
                    class="bg-muted rounded px-1.5 py-0.5"
                    >{{ ticket.unit }}</span
                >
                <span
                    v-if="ticket.priority === 'high'"
                    class="inline-flex items-center gap-0.5 font-medium text-rose-600 dark:text-rose-400"
                >
                    <ChevronsUp class="size-3.5" /> High
                </span>
                <span v-if="pictures" class="inline-flex items-center gap-1">
                    <ImageIcon class="size-3.5" aria-label="Pictures" />
                    {{ pictures }}
                </span>
                <span v-if="videos" class="inline-flex items-center gap-1">
                    <Video class="size-3.5" aria-label="Videos" />
                    {{ videos }}
                </span>
                <MessagesSquare
                    v-if="ticket.chat"
                    class="size-3.5"
                    aria-label="Being discussed"
                />
            </div>
        </div>
        <Tip
            v-if="ticket.assigned_to"
            :text="`Given to ${ticket.assigned_to.name}`"
        >
            <span
                class="bg-primary/10 text-primary flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                :aria-label="`Given to ${ticket.assigned_to.name}`"
            >
                {{ getInitials(ticket.assigned_to.name) }}
            </span>
        </Tip>
    </div>
</template>
