<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard as projectDashboard } from '@/routes/projects';
import { formatRelativeTime } from '@/lib/utils';
import type { Glance } from './glance';
import Health from './Health.vue';
import TicketBar from './TicketBar.vue';

// One of someone's projects at a glance, on their own dashboard: its tickets
// as a bar, what is being built, whether it runs well, and the last thing
// that happened in it. The whole card opens the project.

const props = defineProps<{ project: Glance }>();

// A colour of its own, the same every time, from its name
const HUES = [
    '#7c5cfc',
    '#0891b2',
    '#ea580c',
    '#16a34a',
    '#db2777',
    '#2563eb',
    '#ca8a04',
    '#475569',
];
const hue = computed(
    () =>
        HUES[
            [...props.project.name].reduce(
                (sum, c) => sum + c.charCodeAt(0),
                0,
            ) % HUES.length
        ],
);
</script>

<template>
    <Link
        :href="projectDashboard(project.ref_id)"
        class="bg-card hover:border-primary/40 grid min-w-0 gap-3 rounded-xl border p-3.5 transition-colors hover:shadow-sm"
        data-test="project-card"
    >
        <div class="flex min-w-0 items-center gap-2.5">
            <span
                class="grid size-8 shrink-0 place-items-center rounded-lg text-sm font-bold text-white"
                :style="{ background: hue }"
                >{{ project.name.charAt(0).toUpperCase() }}</span
            >
            <b class="min-w-0 truncate text-[15px]">{{ project.name }}</b>
            <span
                class="text-muted-foreground ml-auto shrink-0 text-xs capitalize"
                >{{ project.role }}</span
            >
        </div>

        <TicketBar :counts="project.tickets" />

        <div class="grid gap-1.5 text-[13px]">
            <div
                v-if="project.building.length"
                class="flex min-w-0 items-center gap-2"
            >
                <span class="size-2 shrink-0 rounded-full bg-violet-500" />
                <span class="text-muted-foreground w-16 shrink-0"
                    >Building</span
                >
                <span class="min-w-0 truncate">{{
                    project.building.map((part) => part.name).join(', ')
                }}</span>
            </div>
            <Health :running="project.running" />
        </div>

        <p
            v-if="project.last"
            class="text-muted-foreground truncate border-t pt-2.5 text-xs"
        >
            {{ project.last.who ? `${project.last.who} ` : ''
            }}{{ project.last.text }} ·
            {{ formatRelativeTime(project.last.at) }}
        </p>
    </Link>
</template>
