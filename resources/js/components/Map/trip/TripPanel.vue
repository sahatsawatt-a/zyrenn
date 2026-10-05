<script setup lang="ts">
import {
    AlertTriangle,
    Clock,
    Coffee,
    LoaderCircle,
    Plus,
    Sparkles,
    Trash2,
    Wand2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { Candidate } from '@/lib/maps';
import { placeInfo } from '@/lib/maps';
import {
    formatDistance,
    formatDuration,
    formatMoney,
    travelMode,
    travelModes,
} from '../format';
import PlaceSearch from '../PlaceSearch.vue';
import { useSavedPlaces } from '../useSavedPlaces';
import DayTimeline from './DayTimeline.vue';
import type { TripPlan } from './useTripPlan';

// The body of the trip panel: what's wrong with the trip as a whole, the
// days as tabs, and the day being looked at in time -- or every day at once.

const props = defineProps<{
    plan: TripPlan;
    near: () => { lat: number; lng: number };
    editable: boolean;
}>();

// Saved places come first in the search
const local = useSavedPlaces();
const emit = defineEmits<{ focus: [place: Candidate] }>();

const day = computed(() =>
    props.plan.selectedDayId.value
        ? props.plan.dayOf(props.plan.selectedDayId.value)
        : undefined,
);
const dayIndex = computed(() =>
    props.plan.trip.days.findIndex((each) => each.id === day.value?.id),
);
const timeline = computed(() =>
    day.value ? props.plan.timelineOf(day.value.id) : null,
);
const problems = computed(() => props.plan.tripProblems());
const currency = computed(() => props.plan.trip.currency);

// ---- dragging stops into order, or onto another day's tab ----

const dragging = ref<{ dayId: string; index: number } | null>(null);
const dropOn = (dayId: string, index: number) => {
    if (dragging.value) {
        props.plan.moveStop(
            dragging.value.dayId,
            dragging.value.index,
            dayId,
            index,
        );
    }

    dragging.value = null;
};

// ---- the quickest order, and opening hours ----

const ordering = ref(false);
const checking = ref(false);
const actionError = ref('');

const putInOrder = async () => {
    ordering.value = true;
    actionError.value = '';

    try {
        await props.plan.quickest(day.value!.id);
    } catch (thrown) {
        actionError.value = (thrown as Error).message;
    } finally {
        ordering.value = false;
    }
};

const unchecked = computed(
    () =>
        day.value?.stops.filter(
            (stop) => !stop.rest && stop.hours === undefined,
        ).length ?? 0,
);

/** Looks up the hours of the day's sights not yet looked up -- one at a time. */
const checkHours = async () => {
    checking.value = true;
    actionError.value = '';

    try {
        for (const stop of day.value!.stops.filter(
            (each) => !each.rest && each.hours === undefined,
        )) {
            const info = await placeInfo(stop);
            stop.hours = info.found ? (info.hours ?? null) : null;
        }
    } catch (thrown) {
        actionError.value = (thrown as Error).message;
    } finally {
        checking.value = false;
    }
};

// ---- adding ----

const addText = ref('');
const add = (place: Candidate) => {
    props.plan.addStop(day.value!.id, place);
    addText.value = '';
};

const restError = ref('');
const rest = (where: 'here' | 'hotel') => {
    restError.value = props.plan.addRest(day.value!.id, where)
        ? ''
        : 'No hotel booked for this day to rest at.';
};
</script>

<template>
    <div class="flex flex-col" data-test="trip">
        <ul
            v-if="problems.length"
            class="mx-4 mt-3 space-y-0.5 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300"
            data-test="stay-problems"
        >
            <li v-for="problem in problems" :key="problem" class="flex gap-1.5">
                <AlertTriangle class="mt-0.5 size-3 shrink-0" /> {{ problem }}
            </li>
        </ul>

        <!-- The days -->
        <div
            class="bg-background/95 sticky top-0 z-10 flex gap-1.5 overflow-x-auto border-b px-4 py-2.5 backdrop-blur"
            data-test="days"
        >
            <button
                type="button"
                class="shrink-0 rounded-full border px-3 py-1 text-xs"
                :class="
                    plan.selectedDayId.value === null
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent'
                "
                @click="plan.selectedDayId.value = null"
            >
                All days
            </button>
            <button
                v-for="(each, index) in plan.trip.days"
                :key="each.id"
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-xs"
                :class="
                    plan.selectedDayId.value === each.id
                        ? 'border-foreground/30 bg-accent font-semibold'
                        : 'hover:bg-accent'
                "
                :data-test="`day-${index + 1}`"
                @click="plan.selectedDayId.value = each.id"
                @dragover.prevent
                @drop="dropOn(each.id, each.stops.length)"
            >
                <span
                    class="size-2 rounded-full"
                    :style="{ background: plan.colorOf(each.id) }"
                />
                Day {{ index + 1 }}
                <span class="text-muted-foreground font-normal">{{
                    plan.tabDateOf(index)
                }}</span>
            </button>
            <button
                v-if="editable"
                type="button"
                class="hover:bg-accent flex shrink-0 items-center gap-1 rounded-full border border-dashed px-3 py-1 text-xs"
                data-test="add-day"
                @click="plan.addDay()"
            >
                <Plus class="size-3" /> Day
            </button>
        </div>

        <!-- Every day at once -->
        <ul v-if="!day" class="text-sm" data-test="all-days">
            <li
                v-for="(each, index) in plan.trip.days"
                :key="each.id"
                class="hover:bg-accent/60 flex cursor-pointer items-start gap-3 border-b px-4 py-3"
                @click="plan.selectedDayId.value = each.id"
            >
                <span
                    class="mt-1.5 size-2.5 shrink-0 rounded-full"
                    :style="{ background: plan.colorOf(each.id) }"
                />
                <span class="min-w-0 flex-1">
                    <span class="block font-medium"
                        >Day {{ index + 1 }} · {{ plan.dateOf(index) }}</span
                    >
                    <span class="text-muted-foreground block truncate text-xs">
                        {{
                            each.stops
                                .filter((stop) => !stop.rest)
                                .map((stop) => stop.name)
                                .join(' → ') || 'Nothing planned'
                        }}
                    </span>
                </span>
                <span class="text-muted-foreground shrink-0 text-right text-xs">
                    <span class="block"
                        >ends {{ plan.timelineOf(each.id).ends }}</span
                    >
                    <span class="block">{{
                        formatMoney(currency, plan.timelineOf(each.id).cost)
                    }}</span>
                </span>
            </li>
        </ul>

        <!-- One day, in time -->
        <template v-else-if="timeline">
            <div class="space-y-2 px-4 pt-3">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-semibold"
                        >Day {{ dayIndex + 1 }} ·
                        {{ plan.dateOf(dayIndex) }}</span
                    >
                    <span
                        v-if="timeline.lands"
                        class="text-muted-foreground flex items-center gap-1 text-xs"
                    >
                        <Clock class="size-3" /> lands {{ timeline.lands }}
                    </span>
                    <label
                        v-else
                        class="text-muted-foreground flex items-center gap-1 text-xs"
                    >
                        <Clock class="size-3" /> from
                        <input
                            v-model="day.start"
                            type="time"
                            :readonly="!editable"
                            class="bg-background dark:bg-input/30 h-7 rounded-md border px-1.5 text-xs dark:[color-scheme:dark]"
                            data-test="day-start"
                        />
                    </label>
                    <div
                        class="ml-auto flex rounded-md border p-0.5"
                        role="radiogroup"
                        aria-label="Getting about"
                    >
                        <button
                            v-for="option in travelModes"
                            :key="option.id"
                            type="button"
                            role="radio"
                            :aria-checked="day.mode === option.id"
                            :aria-label="option.label"
                            :title="option.label"
                            :disabled="!editable"
                            class="rounded px-1.5 py-1"
                            :class="
                                day.mode === option.id
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-accent'
                            "
                            :data-test="`mode-${option.id}`"
                            @click="day.mode = option.id"
                        >
                            <component :is="option.icon" class="size-3.5" />
                        </button>
                    </div>
                </div>

                <div v-if="editable" class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-7 text-xs"
                        :disabled="
                            ordering ||
                            day.stops.filter((stop) => !stop.rest).length < 2
                        "
                        data-test="quickest"
                        @click="putInOrder"
                    >
                        <LoaderCircle
                            v-if="ordering"
                            class="size-3.5 animate-spin"
                        />
                        <Wand2 v-else class="size-3.5" /> Quickest order
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-7 text-xs"
                        :disabled="checking || !unchecked"
                        data-test="check-hours"
                        @click="checkHours"
                    >
                        <LoaderCircle
                            v-if="checking"
                            class="size-3.5 animate-spin"
                        />
                        <Sparkles v-else class="size-3.5" />
                        {{
                            unchecked
                                ? `Opening hours (${unchecked})`
                                : 'Hours checked'
                        }}
                    </Button>
                </div>
                <p v-if="actionError" class="text-destructive text-xs">
                    {{ actionError }}
                </p>
                <p
                    v-if="plan.routes[day.id]?.error"
                    class="text-destructive text-xs"
                >
                    No route: {{ plan.routes[day.id].error }}
                </p>

                <ul
                    v-if="timeline.warnings.length"
                    class="space-y-1 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300"
                    data-test="warnings"
                >
                    <li
                        v-for="warning in timeline.warnings"
                        :key="warning"
                        class="flex gap-1.5"
                    >
                        <AlertTriangle class="mt-0.5 size-3 shrink-0" />
                        {{ warning }}
                    </li>
                </ul>
            </div>

            <DayTimeline
                v-model:dragging="dragging"
                :plan="plan"
                :day-id="day.id"
                :editable="editable"
                @focus="emit('focus', $event)"
                @drop="dropOn"
            />

            <div
                class="space-y-3 border-t px-4 py-3"
                @dragover.prevent
                @drop="dropOn(day.id, day.stops.length)"
            >
                <div
                    class="text-muted-foreground flex justify-between gap-2 text-xs"
                    data-test="day-summary"
                >
                    <span>
                        <template v-if="timeline.travel">
                            {{ formatDuration(timeline.travel) }}
                            {{ travelMode(day.mode).verb }} ·
                            {{ formatDistance(timeline.km) }}
                        </template>
                        <template v-if="timeline.transfers">
                            · {{ formatDuration(timeline.transfers) }} airport
                            transfer</template
                        >
                    </span>
                    <span class="shrink-0"
                        >{{
                            timeline.cost
                                ? `${formatMoney(currency, timeline.cost)} · `
                                : ''
                        }}ends {{ timeline.ends }}</span
                    >
                </div>
                <template v-if="editable">
                    <PlaceSearch
                        :local="local"
                        v-model="addText"
                        :placeholder="`Add a place to Day ${dayIndex + 1}`"
                        :near="props.near"
                        @pick="add"
                    />
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-muted-foreground">Add a rest:</span>
                        <button
                            type="button"
                            class="hover:bg-accent flex items-center gap-1 rounded-full border px-2.5 py-1"
                            data-test="rest-here"
                            @click="rest('here')"
                        >
                            <Coffee class="size-3.5" /> Here
                        </button>
                        <button
                            type="button"
                            class="hover:bg-accent flex items-center gap-1 rounded-full border px-2.5 py-1"
                            data-test="rest-hotel"
                            @click="rest('hotel')"
                        >
                            <Coffee class="size-3.5" /> At the hotel
                        </button>
                        <span v-if="restError" class="text-destructive">{{
                            restError
                        }}</span>
                    </div>
                    <button
                        v-if="plan.trip.days.length > 1"
                        type="button"
                        class="text-muted-foreground hover:text-destructive flex items-center gap-1 text-xs"
                        @click="plan.removeDay(day.id)"
                    >
                        <Trash2 class="size-3.5" /> Remove Day {{ dayIndex + 1
                        }}{{
                            day.stops.length
                                ? ' (its stops join the day before)'
                                : ''
                        }}
                    </button>
                </template>
            </div>
        </template>

        <p class="text-muted-foreground border-t px-4 py-2 text-[11px]">
            Times by Valhalla on OpenStreetMap. Public transport opens in Amap
            or Google.
        </p>
    </div>
</template>
