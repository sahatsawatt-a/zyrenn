<script setup lang="ts">
import {
    BedDouble,
    Coffee,
    ExternalLink,
    GripVertical,
    Pencil,
    Plane,
    TramFront,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { Candidate } from '@/lib/maps';
import {
    formatDistance,
    formatDuration,
    formatMoney,
    legMode,
    travelMode,
} from '../format';
import LegEditor from './LegEditor.vue';
import StopEditor from './StopEditor.vue';
import type { LegOverride, LegRow, StopRow, TripPlan } from './useTripPlan';
import { transitLinks } from './useTripPlan';

// One day, in time: out of the hotel or in from the airport, each leg and how
// long it takes, each stop with when you arrive and move on, and where the
// night is spent. Stops are dragged into order -- or onto another day's tab.

const props = defineProps<{
    plan: TripPlan;
    dayId: string;
    editable: boolean;
    /** Being dragged, shared with the day tabs so a stop can move day. */
    dragging: { dayId: string; index: number } | null;
}>();
const emit = defineEmits<{
    focus: [place: Candidate];
    'update:dragging': [value: { dayId: string; index: number } | null];
    drop: [dayId: string, index: number];
}>();

const day = computed(() => props.plan.dayOf(props.dayId)!);
const timeline = computed(() => props.plan.timelineOf(props.dayId));
const color = computed(() => props.plan.colorOf(props.dayId));
const route = computed(() => props.plan.routes[props.dayId]);
const currency = computed(() => props.plan.trip.currency);

const openLeg = ref<string | null>(null);
const openStop = ref<string | null>(null);

/** Where a leg's editor starts: what was typed, or the routed minutes by Metro. */
const legDraft = (row: LegRow): LegOverride =>
    row.manual ?? {
        mode: 'metro',
        minutes: row.leg ? Math.max(1, Math.round(row.leg.seconds / 60)) : 15,
        cost: 0,
        note: '',
    };

const saveLeg = (key: string, leg: LegOverride | null) => {
    props.plan.setLeg(props.dayId, key, leg);
    openLeg.value = null;
};

/** What a stop's line under its name says about its hours that day. */
const hoursLine = (row: StopRow) => {
    if (row.problem) {
        return row.problem[0].toUpperCase() + row.problem.slice(1);
    }

    if (!row.open) {
        return row.stop.hours === undefined ? '' : 'No opening hours found';
    }

    // Google's own words may already say it: "Open 24 hours"
    return /^open/i.test(row.open) ? row.open : `Open ${row.open}`;
};

const drop = (index: number) => emit('drop', props.dayId, index);
</script>

<template>
    <ol class="px-4 py-2 text-sm" data-test="timeline">
        <template v-for="(row, index) in timeline.rows" :key="index">
            <!-- An airport or a hotel -->
            <li
                v-if="row.kind === 'point'"
                class="flex items-start gap-3 py-2"
                :data-test="row.icon === 'plane' ? 'airport-row' : 'hotel-row'"
            >
                <span
                    class="text-muted-foreground w-11 shrink-0 pt-1 text-xs tabular-nums"
                    >{{ row.time }}</span
                >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-md text-white"
                    :class="
                        row.icon === 'plane' ? 'bg-sky-600' : 'bg-slate-700'
                    "
                >
                    <Plane v-if="row.icon === 'plane'" class="size-4" />
                    <BedDouble v-else class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <button
                        type="button"
                        class="block max-w-full truncate text-left font-medium hover:underline"
                        @click="emit('focus', row.place)"
                    >
                        {{ row.label }}
                    </button>
                    <span
                        v-if="row.note"
                        class="block text-xs"
                        :class="
                            row.problem
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                        data-test="stay-note"
                        >{{ row.note }}</span
                    >
                </span>
            </li>

            <!-- The way from one place to the next -->
            <li
                v-else-if="row.kind === 'leg'"
                class="text-muted-foreground flex items-stretch gap-3 text-xs"
                data-test="leg"
            >
                <span class="w-11 shrink-0" />
                <span class="flex w-7 shrink-0 justify-center">
                    <span
                        class="w-0.5 rounded-full"
                        :style="{
                            background: color,
                            opacity: row.transfer ? 0.45 : 0.8,
                        }"
                    />
                </span>
                <span
                    class="group flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5 py-1.5"
                >
                    <template v-if="row.manual">
                        <component
                            :is="legMode(row.manual.mode).icon"
                            class="text-foreground size-3.5"
                        />
                        <span class="text-foreground" data-test="leg-manual"
                            >{{ row.manual.minutes }} min
                            {{ legMode(row.manual.mode).label.toLowerCase()
                            }}{{
                                row.manual.cost
                                    ? ` · ${formatMoney(currency, row.manual.cost)}`
                                    : ''
                            }}{{
                                row.manual.note ? ` · ${row.manual.note}` : ''
                            }}</span
                        >
                        <span
                            class="bg-muted rounded px-1 text-[10px] tracking-wide uppercase"
                            :title="
                                row.leg
                                    ? `Routed: ${formatDuration(row.leg.seconds)} ${row.transfer ? 'by car' : travelMode(day.mode).verb}`
                                    : ''
                            "
                            >yours</span
                        >
                    </template>
                    <template v-else-if="row.leg">
                        <component
                            :is="
                                row.transfer
                                    ? travelMode('auto').icon
                                    : travelMode(day.mode).icon
                            "
                            class="size-3.5"
                        />
                        {{ formatDuration(row.leg.seconds) }}
                        {{
                            row.transfer ? 'by car' : travelMode(day.mode).verb
                        }}
                        · {{ formatDistance(row.leg.km) }}
                    </template>
                    <template v-else>{{
                        // Not asked for yet is on its way too: the router is asked a moment after the day changes
                        !route || route.loading ? 'Working out the way…' : '–'
                    }}</template>

                    <span
                        class="ml-auto flex items-center gap-0.5 opacity-70 group-hover:opacity-100"
                    >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="hover:bg-accent flex items-center gap-1 rounded px-1 py-0.5"
                                    :aria-label="`Public transport from ${row.from.name}`"
                                    data-test="leg-transit"
                                >
                                    <TramFront class="size-3.5" /> Transit
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem as-child>
                                    <a
                                        :href="
                                            transitLinks(row.from, row.to).amap
                                        "
                                        target="_blank"
                                        rel="noopener"
                                        data-test="transit-amap"
                                    >
                                        Amap
                                        <ExternalLink
                                            class="ml-auto size-3.5"
                                        />
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem as-child>
                                    <a
                                        :href="
                                            transitLinks(row.from, row.to)
                                                .google
                                        "
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Google Maps
                                        <ExternalLink
                                            class="ml-auto size-3.5"
                                        />
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Popover
                            v-if="editable"
                            :open="openLeg === row.key"
                            @update:open="openLeg = $event ? row.key : null"
                        >
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    class="hover:bg-accent rounded p-1"
                                    :aria-label="
                                        row.manual
                                            ? 'Change this leg'
                                            : 'Enter this leg yourself'
                                    "
                                    data-test="leg-edit"
                                >
                                    <Pencil class="size-3.5" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent align="end" class="w-72">
                                <LegEditor
                                    :initial="legDraft(row)"
                                    :currency="currency"
                                    :typed="!!row.manual"
                                    @save="saveLeg(row.key, $event)"
                                    @clear="saveLeg(row.key, null)"
                                    @cancel="openLeg = null"
                                />
                            </PopoverContent>
                        </Popover>
                    </span>
                </span>
            </li>

            <!-- The rest after the flight, set with the arrival -->
            <li
                v-else-if="row.kind === 'arrival-rest'"
                class="flex items-center gap-3 py-2"
                data-test="rest-row"
            >
                <span class="w-11 shrink-0 text-xs tabular-nums">
                    {{ row.time }}
                    <span class="text-muted-foreground block">{{
                        row.until
                    }}</span>
                </span>
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                >
                    <Coffee class="size-4" />
                </span>
                <span class="text-sm">Rest after the flight</span>
            </li>

            <!-- A sight, or a rest in the day -->
            <li
                v-else
                class="group flex items-start gap-3 rounded-lg py-2 transition-colors"
                :class="[
                    dragging?.dayId === dayId &&
                        dragging.index === row.index &&
                        'opacity-40',
                    editable && 'hover:bg-accent/50',
                ]"
                :draggable="editable"
                :data-test="row.stop.rest ? 'rest-row' : 'stop'"
                @dragstart="
                    emit('update:dragging', { dayId, index: row.index })
                "
                @dragend="emit('update:dragging', null)"
                @dragover.prevent
                @drop="drop(row.index)"
            >
                <span class="w-11 shrink-0 pt-0.5 text-xs tabular-nums">
                    {{ row.arrive }}
                    <span class="text-muted-foreground block">{{
                        row.leave
                    }}</span>
                </span>
                <span
                    v-if="row.stop.rest"
                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                >
                    <Coffee class="size-4" />
                </span>
                <span
                    v-else
                    class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white shadow-sm"
                    :style="{ background: color }"
                    >{{ row.number }}</span
                >
                <span class="min-w-0 flex-1">
                    <button
                        type="button"
                        class="block max-w-full truncate text-left font-medium hover:underline"
                        @click="
                            row.stop.rest !== 'here' && emit('focus', row.stop)
                        "
                    >
                        {{ row.stop.name }}
                    </button>
                    <span
                        v-if="!row.stop.rest && hoursLine(row)"
                        class="block text-xs"
                        :class="
                            row.problem
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                        data-test="stop-hours"
                        >{{ hoursLine(row) }}</span
                    >
                    <Popover
                        :open="openStop === row.stop.id"
                        @update:open="
                            openStop = $event && editable ? row.stop.id : null
                        "
                    >
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                class="text-muted-foreground mt-0.5 flex items-center gap-1 text-xs"
                                :class="editable && 'hover:text-foreground'"
                                data-test="stop-edit"
                            >
                                {{ row.stop.minutes }} min{{
                                    !row.stop.rest && row.stop.cost
                                        ? ` · ${formatMoney(currency, row.stop.cost)}`
                                        : ''
                                }}
                                <Pencil
                                    v-if="editable"
                                    class="size-3 opacity-0 group-hover:opacity-100"
                                />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent align="start" class="w-72">
                            <StopEditor
                                :stop="row.stop"
                                :currency="currency"
                                :weekday="timeline.weekday"
                                @done="openStop = null"
                                @remove="plan.removeStop(dayId, row.stop.id)"
                            />
                        </PopoverContent>
                    </Popover>
                    <p
                        v-if="row.stop.note"
                        class="text-muted-foreground mt-0.5 text-xs italic"
                    >
                        {{ row.stop.note }}
                    </p>
                </span>
                <GripVertical
                    v-if="editable"
                    class="text-muted-foreground mt-1 size-4 shrink-0 cursor-grab opacity-0 group-hover:opacity-100"
                />
            </li>
        </template>
        <li
            v-if="!timeline.rows.length"
            class="text-muted-foreground py-4 text-center text-sm"
        >
            Nothing planned yet.{{
                editable ? ' Add a place below, or click one on the map.' : ''
            }}
        </li>
    </ol>
</template>
