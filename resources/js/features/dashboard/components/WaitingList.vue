<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    CheckSquare,
    CircleX,
    Hand,
    MessageCircle,
    MonitorX,
    PartyPopper,
    Ticket,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { show as noteShow } from '@/routes/notes';
import { formatRelativeTime, xsrfToken } from '@/lib/utils';

// What waits on someone, most pressing first, each with the one thing to do
// about it: steps asking for approval, tickets given to them, unread chats,
// checks and runs that failed today -- and, last, the to-dos still open in
// their notes. Approving is asked once more in a small question, so nothing
// is approved by a stray tap.

export type WaitingItem = {
    kind: 'approval' | 'ticket' | 'chat' | 'failed_check' | 'failed_run';
    title: string;
    context: string;
    at: string | null;
    href: string;
    priority?: string;
    answer?: string;
};

const props = defineProps<{
    items: WaitingItem[];
    todos: {
        items: { text: string; note: { ref_id: string; title: string } }[];
        total: number;
    };
}>();

const LOOK: Record<
    WaitingItem['kind'],
    { icon: Component; tone: string; act: string }
> = {
    approval: {
        icon: Hand,
        tone: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
        act: 'Approve',
    },
    ticket: {
        icon: Ticket,
        tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        act: 'Open',
    },
    chat: {
        icon: MessageCircle,
        tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        act: 'Read',
    },
    failed_check: {
        icon: MonitorX,
        tone: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
        act: 'See run',
    },
    failed_run: {
        icon: CircleX,
        tone: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
        act: 'See run',
    },
};

const count = computed(() => props.items.length + props.todos.total);

// ------------------------------------------------------------ Approving
const asking = ref<WaitingItem | null>(null);
const busy = ref(false);
const failed = ref('');

async function answer(approved: boolean) {
    const item = asking.value;

    if (!item?.answer) {
        return;
    }

    busy.value = true;
    failed.value = '';

    try {
        const response = await fetch(item.answer, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ approved }),
        });

        if (!response.ok) {
            failed.value =
                (await response.json().catch(() => null))?.message ??
                'It could not be answered. Open the run and try there.';

            return;
        }

        asking.value = null;
        router.reload({ only: ['waiting', 'glance'] });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section data-test="waiting">
        <h2 class="mb-2.5 flex items-center gap-2 text-[15px] font-semibold">
            Waiting on you
            <span
                v-if="count"
                class="bg-primary text-primary-foreground rounded-full px-2 text-xs leading-5 font-semibold tabular-nums"
                >{{ count }}</span
            >
        </h2>

        <div class="bg-card divide-y overflow-hidden rounded-xl border">
            <div
                v-for="(item, index) in items"
                :key="`${item.kind}-${index}`"
                class="flex min-w-0 items-center gap-3 px-3.5 py-2.5"
                :data-waiting="item.kind"
            >
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-lg"
                    :class="LOOK[item.kind].tone"
                >
                    <component :is="LOOK[item.kind].icon" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">
                        {{ item.title }}
                        <span
                            v-if="item.priority === 'high'"
                            class="ml-1 rounded bg-rose-500/10 px-1.5 text-[11px] font-semibold text-rose-600 dark:text-rose-400"
                            >High</span
                        >
                    </p>
                    <p class="text-muted-foreground truncate text-xs">
                        {{ item.context
                        }}<template v-if="item.at">
                            · {{ formatRelativeTime(item.at) }}</template
                        >
                    </p>
                </div>
                <Button
                    v-if="item.kind === 'approval' && item.answer"
                    size="sm"
                    data-test="waiting-approve"
                    @click="asking = item"
                >
                    Approve
                </Button>
                <Button v-else as-child size="sm" variant="outline">
                    <Link :href="item.href">{{ LOOK[item.kind].act }}</Link>
                </Button>
            </div>

            <!-- The to-dos still open in their notes, last -->
            <div
                v-if="todos.items.length"
                class="px-3.5 py-2.5"
                data-test="todos"
            >
                <p
                    class="text-muted-foreground mb-1.5 flex items-center gap-1.5 text-xs font-medium"
                >
                    <CheckSquare class="size-3.5" /> To-dos in your notes
                    <span class="tabular-nums">· {{ todos.total }}</span>
                </p>
                <ul class="grid gap-1">
                    <li
                        v-for="(todo, index) in todos.items.slice(0, 4)"
                        :key="index"
                        class="flex min-w-0 items-center gap-2 text-sm"
                    >
                        <span
                            class="border-input size-3.5 shrink-0 rounded border"
                            aria-hidden="true"
                        />
                        <Link
                            :href="noteShow(todo.note.ref_id)"
                            class="min-w-0 truncate hover:underline"
                            >{{ todo.text }}</Link
                        >
                        <span
                            class="text-muted-foreground ml-auto shrink-0 truncate text-xs"
                            >{{ todo.note.title }}</span
                        >
                    </li>
                </ul>
            </div>

            <p
                v-if="!items.length && !todos.items.length"
                class="text-muted-foreground flex items-center gap-2 px-4 py-5 text-sm"
            >
                <PartyPopper class="size-4" /> Nothing waits on you. All clear.
            </p>
        </div>

        <Dialog
            :open="asking !== null"
            @update:open="!$event && ((asking = null), (failed = ''))"
        >
            <DialogContent class="sm:max-w-md" data-test="approve-dialog">
                <DialogHeader>
                    <DialogTitle>{{ asking?.title }}</DialogTitle>
                    <DialogDescription>{{ asking?.context }}</DialogDescription>
                </DialogHeader>
                <p class="text-sm">
                    <Link
                        v-if="asking"
                        :href="asking.href"
                        class="text-primary underline-offset-2 hover:underline"
                        >Read it first, in the waiting run</Link
                    >
                </p>
                <p v-if="failed" class="text-destructive text-sm">
                    {{ failed }}
                </p>
                <DialogFooter class="gap-2">
                    <Button
                        variant="outline"
                        :disabled="busy"
                        data-test="approve-reject"
                        @click="answer(false)"
                    >
                        Reject
                    </Button>
                    <Button
                        :disabled="busy"
                        data-test="approve-yes"
                        @click="answer(true)"
                    >
                        Approve
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
