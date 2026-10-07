<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue';
import type { TripTotals } from '@/lib/maps';
import { formatMoney } from '@/features/maps/lib/format';
import DayTimeline from './DayTimeline.vue';
import type { TripContent } from '@/features/maps/composables/useTripPlan';
import { useTripPlan } from '@/features/maps/composables/useTripPlan';

// A trip shown somewhere other than its own page -- in a note. The whole of
// it as a list of days, or one day as its own page draws it, times and all.
// Nothing here changes the trip.

const props = defineProps<{
    trip: {
        ref_id: string;
        title: string;
        content: TripContent;
        revision: number;
    };
    totals: TripTotals;
    /** A day's id, or "all" for the whole trip. */
    day: string;
}>();
const emit = defineEmits<{ ready: [] }>();

const plan = useTripPlan(props.trip, false);

const shown = computed(() =>
    props.day === 'all' ? null : (plan.dayOf(props.day) ?? null),
);
const money = (amount: number) =>
    amount ? formatMoney(plan.trip.currency, amount) : '';

const dates = computed(() =>
    props.totals.day_count > 1
        ? `${plan.dateOf(0)} – ${plan.dateOf(props.totals.day_count - 1)}`
        : plan.dateOf(0),
);

// Ready to print once the day's routes are in -- the times hang on them
let fallback: ReturnType<typeof setTimeout> | undefined;
const settled = () =>
    Object.values(plan.routes).length > 0 &&
    Object.values(plan.routes).every((state) => !state.loading);

watch(
    () => (props.day === 'all' || !shown.value ? true : settled()),
    (ready) => {
        if (ready) {
            clearTimeout(fallback);
            emit('ready');
        }
    },
    { immediate: true },
);
// A router that never answers mustn't hold the PDF for ever
fallback = setTimeout(() => emit('ready'), 15000);
onBeforeUnmount(() => clearTimeout(fallback));
</script>

<template>
    <div class="space-y-3 p-3 text-sm" data-test="trip-embed">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <span class="font-semibold">{{
                trip.title || 'Untitled trip'
            }}</span>
            <span class="text-muted-foreground text-xs">
                {{ dates }} · {{ totals.day_count }}
                {{ totals.day_count === 1 ? 'day' : 'days' }}
            </span>
            <span
                v-if="totals.total_cost"
                class="ml-auto text-xs font-medium"
                data-test="trip-embed-total"
                >{{ money(totals.total_cost) }}</span
            >
        </div>

        <!-- One day, as the trip's page shows it -->
        <template v-if="day !== 'all'">
            <DayTimeline
                v-if="shown"
                :plan="plan"
                :day-id="shown.id"
                :editable="false"
                :dragging="null"
            />
            <p v-else class="text-muted-foreground text-xs">
                That day is no longer on this trip.
            </p>
        </template>

        <!-- The whole trip: each day, where it goes, what it costs -->
        <ol
            v-else
            class="divide-y rounded-lg border"
            data-test="trip-embed-days"
        >
            <li
                v-for="(each, index) in plan.trip.days"
                :key="each.id"
                class="flex items-start gap-3 px-3 py-2"
            >
                <span
                    class="mt-1.5 size-2.5 shrink-0 rounded-full"
                    :style="{ background: plan.colorOf(each.id) }"
                />
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-medium">
                        Day {{ index + 1 }} · {{ plan.dateOf(index) }}
                    </div>
                    <div class="text-muted-foreground text-xs">
                        {{
                            each.stops
                                .filter((stop) => stop.rest !== 'here')
                                .map((stop) => stop.name)
                                .join(' → ') || 'Nothing planned yet'
                        }}
                    </div>
                </div>
                <span class="text-xs">{{
                    money(totals.days[index]?.cost ?? 0)
                }}</span>
            </li>
            <li
                v-if="totals.stays.length"
                class="text-muted-foreground flex gap-3 px-3 py-2 text-xs"
            >
                <span class="min-w-0 flex-1">
                    {{
                        totals.stays
                            .map(
                                (stay) =>
                                    `${stay.name}, ${stay.nights} ${stay.nights === 1 ? 'night' : 'nights'}`,
                            )
                            .join(' · ')
                    }}
                </span>
                <span class="text-foreground">{{
                    money(totals.stays_cost)
                }}</span>
            </li>
        </ol>
    </div>
</template>
