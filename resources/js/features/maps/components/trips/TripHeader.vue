<script setup lang="ts">
import {
    AlertTriangle,
    BedDouble,
    Check,
    CloudOff,
    LoaderCircle,
    Plane,
    Settings2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatMoney } from '@/features/maps/lib/format';
import FlightsDialog from './FlightsDialog.vue';
import HotelsDialog from './HotelsDialog.vue';
import type { TripPlan } from '@/features/maps/composables/useTripPlan';
import {
    addDays,
    shortDate,
    timeIn,
} from '@/features/maps/composables/useTripPlan';

// The top of the trip panel: what it's called, when, what it costs, whether
// it's saved -- and the flights, hotels and settings, each a click away.

const props = defineProps<{
    plan: TripPlan;
    near: () => { lat: number; lng: number };
    editable: boolean;
}>();

const flights = ref(false);
const hotels = ref(false);

const dates = computed(() => {
    const { startDate, days } = props.plan.trip;
    const last = addDays(startDate, days.length - 1);
    const year = new Date(`${startDate}T00:00:00`).getFullYear();

    return days.length > 1
        ? `${shortDate(startDate)} – ${shortDate(last)} ${year}`
        : `${shortDate(startDate)} ${year}`;
});

// Every day's stops and fares, and every hotel once -- as TripDocument::totals counts it
const cost = computed(
    () =>
        props.plan.trip.days.reduce(
            (sum, day) => sum + props.plan.timelineOf(day.id).cost,
            0,
        ) +
        props.plan.trip.stays.reduce(
            (sum, stay) => sum + (Number(stay.cost) || 0),
            0,
        ),
);

const flightLabel = computed(() => {
    const { arrival, departure } = props.plan.trip;
    const parts = [
        arrival.airport && arrival.at
            ? `in ${shortDate(arrival.at)} ${timeIn(arrival.at)}`
            : '',
        departure.airport && departure.at
            ? `out ${shortDate(departure.at)} ${timeIn(departure.at)}`
            : '',
    ].filter(Boolean);

    return parts.join(' · ') || 'Add flights';
});

const field =
    'h-9 w-full rounded-md border bg-background px-2 text-sm dark:bg-input/30 dark:[color-scheme:dark]';
</script>

<template>
    <div class="space-y-2" data-test="trip-header">
        <div class="flex items-center gap-2">
            <input
                v-model="plan.title.value"
                :readonly="!editable"
                class="hover:bg-accent/60 focus:bg-accent -mx-1 min-w-0 flex-1 rounded px-1 text-lg font-semibold outline-none"
                aria-label="Trip name"
                data-test="trip-title"
            />
            <span
                class="flex shrink-0 items-center gap-1 text-xs"
                :class="
                    plan.status.value === 'error' ||
                    plan.status.value === 'conflict'
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
                data-test="save-status"
                :data-status="plan.status.value"
            >
                <template v-if="!editable">View only</template>
                <template v-else-if="plan.status.value === 'saving'"
                    ><LoaderCircle class="size-3 animate-spin" />
                    Saving</template
                >
                <template v-else-if="plan.status.value === 'unsaved'"
                    >Unsaved</template
                >
                <template v-else-if="plan.status.value === 'saved'"
                    ><Check class="size-3" /> Saved</template
                >
                <template v-else-if="plan.status.value === 'conflict'"
                    ><AlertTriangle class="size-3" /> Changed
                    elsewhere</template
                >
                <button
                    v-else
                    type="button"
                    class="flex items-center gap-1 hover:underline"
                    @click="plan.retry()"
                >
                    <CloudOff class="size-3" /> Not saved -- retry
                </button>
            </span>
        </div>
        <div
            class="text-muted-foreground flex items-center justify-between text-xs"
        >
            <span
                >{{ dates }} · {{ plan.trip.days.length }}
                {{ plan.trip.days.length === 1 ? 'day' : 'days' }}</span
            >
            <span class="text-foreground font-medium" data-test="trip-cost">{{
                cost ? formatMoney(plan.trip.currency, cost) : 'No costs yet'
            }}</span>
        </div>

        <div class="flex flex-wrap gap-1.5">
            <button
                type="button"
                class="hover:bg-accent flex min-w-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                data-test="open-flights"
                @click="flights = true"
            >
                <Plane class="size-3.5 shrink-0 text-sky-600" />
                <span class="truncate">{{ flightLabel }}</span>
            </button>
            <button
                type="button"
                class="hover:bg-accent flex min-w-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                data-test="open-hotels"
                @click="hotels = true"
            >
                <BedDouble class="size-3.5 shrink-0" />
                <span class="truncate">
                    {{
                        plan.trip.stays.length
                            ? plan.trip.stays
                                  .map((stay) => stay.place.name)
                                  .join(', ')
                            : 'Add a hotel'
                    }}
                </span>
            </button>
            <Popover>
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        class="hover:bg-accent flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                        data-test="open-settings"
                    >
                        <Settings2 class="size-3.5" /> Trip
                    </button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-64 space-y-3">
                    <fieldset :disabled="!editable" class="space-y-3">
                        <label
                            class="text-muted-foreground block space-y-1 text-xs"
                        >
                            First day
                            <input
                                v-model="plan.trip.startDate"
                                type="date"
                                :class="field"
                                data-test="start-date"
                            />
                            <span class="block"
                                >Hotels and flights move with it.</span
                            >
                        </label>
                        <label
                            class="text-muted-foreground block space-y-1 text-xs"
                        >
                            Currency
                            <input
                                v-model="plan.trip.currency"
                                maxlength="8"
                                :class="field"
                                placeholder="¥, $, €, THB…"
                            />
                        </label>
                    </fieldset>
                </PopoverContent>
            </Popover>
        </div>

        <FlightsDialog
            v-model:open="flights"
            :plan="plan"
            :near="near"
            :editable="editable"
        />
        <HotelsDialog
            v-model:open="hotels"
            :plan="plan"
            :near="near"
            :editable="editable"
        />
    </div>
</template>
