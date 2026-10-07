<script setup lang="ts">
import {
    BedDouble,
    Check,
    ChevronDown,
    LoaderCircle,
    MapPin,
    Plane,
    Plus,
    Sparkles,
    Star,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Candidate, PlaceInfo } from '@/lib/maps';
import { placeInfo } from '@/lib/maps';
import { WEEKDAYS } from '@/features/maps/lib/hours';
import type { TripPlan } from '@/features/maps/composables/useTripPlan';

// A place clicked on the trip's map, in a card over it: put it into a day, or
// make it the hotel or an airport. Its Google hours, once looked up, go along
// into the trip.

const props = defineProps<{
    place: Candidate;
    plan: TripPlan;
    editable: boolean;
}>();
const emit = defineEmits<{ close: [] }>();

const info = ref<PlaceInfo | null>(null);
const looking = ref(false);
const lookError = ref('');
const done = ref('');

// A new place, not the same one with its address just arrived
watch(
    // A string: a new array each time would always count as a change
    () => `${props.place.lat},${props.place.lng}`,
    () => {
        info.value = null;
        lookError.value = '';
        done.value = '';
    },
);

const day = computed(
    () => props.plan.selectedDayId.value ?? props.plan.trip.days[0]?.id,
);
const dayLabel = (dayId: string) => {
    const index = props.plan.trip.days.findIndex((each) => each.id === dayId);

    return `Day ${index + 1} · ${props.plan.dateOf(index)}`;
};

const lookUp = async () => {
    looking.value = true;
    lookError.value = '';

    try {
        info.value = await placeInfo(props.place);
    } catch (thrown) {
        lookError.value = (thrown as Error).message;
    } finally {
        looking.value = false;
    }
};

const addTo = (dayId: string) => {
    props.plan.addStop(
        dayId,
        props.place,
        info.value
            ? info.value.found
                ? (info.value.hours ?? null)
                : null
            : undefined,
    );
    done.value = `Added to ${dayLabel(dayId).split(' · ')[0]}`;
};

const stay = () => {
    props.plan.addStay(props.place);
    done.value = 'Booked as a hotel -- set its dates under Hotels';
};

const airport = (which: 'arrival' | 'departure') => {
    props.plan.setAirport(which, props.place);
    done.value =
        which === 'arrival'
            ? 'Set as where you land'
            : 'Set as where you fly home from';
};

const kind = computed(() => {
    const [, what] = (props.place.kind || '').split('/');

    return (
        info.value?.type ||
        (what && what !== 'yes' ? what.replaceAll('_', ' ') : '')
    );
});

const today = computed(() =>
    props.plan.weekdayOf(
        Math.max(
            0,
            props.plan.trip.days.findIndex((each) => each.id === day.value),
        ),
    ),
);
</script>

<template>
    <div
        class="bg-background/95 absolute z-20 w-[min(26rem,calc(100%-1.5rem))] rounded-xl border p-4 shadow-xl backdrop-blur max-md:top-3 max-md:left-3 md:bottom-6 md:left-[calc(400px+1.5rem)]"
        data-test="trip-place-card"
    >
        <div class="flex items-start gap-2">
            <MapPin class="mt-0.5 size-4 shrink-0 text-red-500" />
            <div class="min-w-0 flex-1">
                <h2 class="truncate leading-tight font-semibold">
                    {{ place.name }}
                </h2>
                <p class="text-muted-foreground truncate text-xs capitalize">
                    {{ [kind, place.address].filter(Boolean).join(' · ') }}
                </p>
            </div>
            <button
                type="button"
                class="text-muted-foreground hover:bg-accent rounded-md p-1"
                aria-label="Close"
                @click="emit('close')"
            >
                <X class="size-4" />
            </button>
        </div>

        <div v-if="editable" class="mt-3 flex flex-wrap items-center gap-2">
            <div class="flex">
                <Button
                    size="sm"
                    class="rounded-r-none"
                    data-test="add-to-day"
                    @click="day && addTo(day)"
                >
                    <Plus class="size-4" />
                    {{ day ? dayLabel(day).split(' · ')[0] : 'Add' }}
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            size="sm"
                            class="border-primary-foreground/20 rounded-l-none border-l px-2"
                            aria-label="Choose the day"
                        >
                            <ChevronDown class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem
                            v-for="each in plan.trip.days"
                            :key="each.id"
                            :data-test="`add-to-${plan.trip.days.indexOf(each) + 1}`"
                            @select="addTo(each.id)"
                        >
                            <span
                                class="size-2 rounded-full"
                                :style="{ background: plan.colorOf(each.id) }"
                            />
                            {{ dayLabel(each.id) }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <Button
                size="sm"
                variant="outline"
                data-test="stay-here"
                @click="stay"
                ><BedDouble class="size-4" /> Hotel</Button
            >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="use-as-airport"
                        ><Plane class="size-4" /> Airport</Button
                    >
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuItem @select="airport('arrival')"
                        >Where you land</DropdownMenuItem
                    >
                    <DropdownMenuItem @select="airport('departure')"
                        >Where you fly home from</DropdownMenuItem
                    >
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <p
            v-if="done"
            class="mt-2 flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"
            data-test="card-done"
        >
            <Check class="size-3.5" /> {{ done }}
        </p>

        <div class="mt-3 border-t pt-3 text-xs">
            <button
                v-if="!info && !looking"
                type="button"
                class="text-primary flex items-center gap-1.5 hover:underline"
                data-test="look-up"
                @click="lookUp"
            >
                <Sparkles class="size-3.5" /> Opening hours &amp; rating from
                Google
            </button>
            <p
                v-if="looking"
                class="text-muted-foreground flex items-center gap-1.5"
            >
                <LoaderCircle class="size-3.5 animate-spin" /> Asking Google…
            </p>
            <p v-if="lookError" class="text-destructive">{{ lookError }}</p>
            <p v-if="info && !info.found" class="text-muted-foreground">
                Google has nothing by this name here.
            </p>
            <div v-else-if="info" class="space-y-1">
                <p v-if="info.rating" class="flex items-center gap-1">
                    <Star class="size-3.5 fill-amber-400 text-amber-400" />
                    <span class="font-semibold">{{ info.rating }}</span>
                    <span class="text-muted-foreground"
                        >({{ (info.reviews ?? 0).toLocaleString() }})</span
                    >
                </p>
                <p v-if="info.hours && today" class="text-muted-foreground">
                    <span class="capitalize">{{ today }}</span
                    >: {{ info.hours[today] ?? 'not listed' }}
                </p>
                <p v-else-if="!info.hours" class="text-muted-foreground">
                    No opening hours listed.
                </p>
                <details v-if="info.hours" class="text-muted-foreground">
                    <summary class="cursor-pointer">Every day</summary>
                    <p
                        v-for="weekday in WEEKDAYS"
                        :key="weekday"
                        class="capitalize"
                    >
                        {{ weekday.slice(0, 3) }}:
                        {{ info.hours[weekday] ?? '–' }}
                    </p>
                </details>
            </div>
        </div>
    </div>
</template>
