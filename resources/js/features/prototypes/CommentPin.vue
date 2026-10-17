<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, RotateCcw, Ticket } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { PrototypeComment } from './feedback';
import { usePrototypeComments } from './feedback';

// One comment's pin, where it was left on its screen, and what it says when
// opened: who wrote it and when, resolving it (or opening it again), and --
// for whoever may file work on the project -- making it a ticket. The same
// in Play and over the screens on the board.
const props = defineProps<{
    board: string;
    comment: PrototypeComment;
    // Its number among its screen's comments, as the pin shows it
    number: number;
    // Anyone who can change the prototype resolves any comment; others their own
    canResolve: boolean;
    // Members who may add work to the project
    canTicket: boolean;
}>();

const { resolve, ticket } = usePrototypeComments(props.board);
const page = usePage();
const mine = computed(() => props.comment.by?.id === page.props.auth.user.id);
const resolvable = computed(() => props.canResolve || mine.value);
const busy = ref(false);

const when = computed(() =>
    props.comment.created_at
        ? new Date(props.comment.created_at).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '',
);

const run = async (doing: () => Promise<unknown>, done: string) => {
    busy.value = true;

    try {
        await doing();
        toast.success(done);
    } catch (problem) {
        toast.error((problem as Error).message);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="comment-pin"
                :class="{ 'is-resolved': comment.resolved }"
                :aria-label="`Comment ${number}${comment.by ? ` by ${comment.by.name}` : ''}: ${comment.body}`"
                :data-comment="comment.ref_id"
                data-test="comment-pin"
            >
                {{ number }}
            </button>
        </PopoverTrigger>
        <PopoverContent
            class="w-72 space-y-2 p-3"
            :collision-padding="8"
            data-test="comment-card"
        >
            <div class="flex items-baseline justify-between gap-2 text-xs">
                <span class="truncate font-medium">{{
                    comment.by?.name ?? 'Someone'
                }}</span>
                <span class="text-muted-foreground shrink-0">{{ when }}</span>
            </div>
            <p class="text-sm break-words whitespace-pre-wrap">
                {{ comment.body }}
            </p>
            <p
                v-if="comment.ticket"
                class="text-muted-foreground text-xs"
                data-test="comment-ticket"
            >
                Ticket #{{ comment.ticket.number }} ·
                {{ comment.ticket.status.replace('_', ' ') }}
            </p>
            <div
                v-if="resolvable || (canTicket && !comment.ticket)"
                class="flex flex-wrap gap-2 pt-1"
            >
                <Button
                    v-if="resolvable"
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    data-test="comment-resolve"
                    @click="
                        run(
                            () => resolve(comment.ref_id, !comment.resolved),
                            comment.resolved ? 'Opened again' : 'Resolved',
                        )
                    "
                >
                    <RotateCcw v-if="comment.resolved" />
                    <Check v-else />
                    {{ comment.resolved ? 'Open again' : 'Resolve' }}
                </Button>
                <Button
                    v-if="canTicket && !comment.ticket"
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    data-test="comment-make-ticket"
                    @click="
                        run(() => ticket(comment.ref_id), 'Made a ticket of it')
                    "
                >
                    <Ticket /> Make a ticket
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>

<style scoped>
.comment-pin {
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    /* The point of the pin is the spot */
    transform: translate(-4px, -100%);
    font-size: 12px;
    font-weight: 600;
    color: white;
    background: #f59e0b;
    border: 2px solid white;
    border-radius: 13px 13px 13px 3px;
    box-shadow: 0 2px 6px rgb(15 23 42 / 0.3);
    cursor: pointer;
    pointer-events: auto;
}
.comment-pin.is-resolved {
    background: #94a3b8;
}
.comment-pin:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
</style>
