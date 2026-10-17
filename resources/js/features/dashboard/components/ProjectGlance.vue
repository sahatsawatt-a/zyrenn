<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ProjectGlance } from './glance';
import Health from './Health.vue';
import TicketBar from './TicketBar.vue';

// A project at a glance, on its own dashboard: its tickets (who has those in
// progress, what those on hold wait on), what the product tree says is being
// built, and whether its workflows and checks run well.

defineProps<{ glance: ProjectGlance }>();

const STATUS: Record<string, string> = {
    building: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    verifying: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    partial: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
};

const initials = (name: string | null) =>
    (name ?? '?')
        .split(/\s+/)
        .map((word) => word.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
</script>

<template>
    <section data-test="glance">
        <h2 class="mb-2.5 text-[15px] font-semibold">At a glance</h2>
        <div class="grid gap-3 md:grid-cols-3">
            <div
                class="bg-card grid content-start gap-2.5 rounded-xl border p-3.5"
            >
                <h3
                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                >
                    Tickets
                </h3>
                <TicketBar :counts="glance.tickets.counts" />
                <Link
                    v-for="ticket in glance.tickets.in_progress"
                    :key="ticket.number"
                    :href="ticket.href"
                    class="flex min-w-0 items-center gap-2 text-[13px] hover:underline"
                >
                    <span
                        class="bg-primary/15 text-primary grid size-5 shrink-0 place-items-center rounded-full text-[9px] font-bold"
                        :title="ticket.assignee ?? 'Nobody yet'"
                        >{{ initials(ticket.assignee) }}</span
                    >
                    <span class="min-w-0 truncate"
                        >#{{ ticket.number }} {{ ticket.title }}</span
                    >
                </Link>
                <Link
                    v-for="ticket in glance.tickets.on_hold"
                    :key="ticket.number"
                    :href="ticket.href"
                    class="grid min-w-0 gap-0.5 text-[13px] hover:underline"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <span
                            class="shrink-0 rounded bg-sky-500/10 px-1.5 text-[11px] font-semibold text-sky-600 dark:text-sky-400"
                            >On hold</span
                        >
                        <span class="min-w-0 truncate"
                            >#{{ ticket.number }} {{ ticket.title }}</span
                        >
                    </span>
                    <span
                        v-if="ticket.reason"
                        class="text-muted-foreground line-clamp-1 pl-1 text-xs"
                        >{{ ticket.reason }}</span
                    >
                </Link>
            </div>

            <div
                class="bg-card grid content-start gap-2 rounded-xl border p-3.5"
            >
                <h3
                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                >
                    Being built
                </h3>
                <div
                    v-for="part in glance.building"
                    :key="part.code"
                    class="flex min-w-0 items-center gap-2 text-[13px]"
                >
                    <span class="min-w-0 flex-1 truncate">{{ part.name }}</span>
                    <span
                        class="shrink-0 rounded-full px-2 text-[11px] font-semibold"
                        :class="
                            STATUS[part.status] ??
                            'bg-muted text-muted-foreground'
                        "
                        >{{ part.status }}</span
                    >
                </div>
                <p
                    v-if="!glance.building.length"
                    class="text-muted-foreground text-[13px]"
                >
                    Nothing is being built in the product tree.
                </p>
            </div>

            <div
                class="bg-card grid content-start gap-2 rounded-xl border p-3.5 text-[13px]"
            >
                <h3
                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                >
                    Running
                </h3>
                <Health :running="glance.running" />
                <p
                    v-if="
                        !glance.running.workflows_on &&
                        !glance.running.runs_failed &&
                        !glance.running.suites &&
                        !glance.running.requests_new
                    "
                    class="text-muted-foreground"
                >
                    No workflows or checks here yet.
                </p>
            </div>
        </div>
    </section>
</template>
