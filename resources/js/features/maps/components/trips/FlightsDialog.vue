<script setup lang="ts">
import { Plane, X } from '@lucide/vue';
import { ref } from 'vue';
import DateTimeField from '@/components/common/DateTimeField.vue';
import NumberField from '@/components/common/NumberField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import PlaceSearch from '@/components/map/PlaceSearch.vue';
import { useSavedPlaces } from '@/components/map/useSavedPlaces';
import type { TripPlan } from '@/features/maps/composables/useTripPlan';

// The flights in and out: where and when, how long the airport takes on the
// way in, the rest after it, and how early to be there on the way out.

const props = defineProps<{
    plan: TripPlan;
    near: () => { lat: number; lng: number };
    editable: boolean;
}>();

// Saved places come first in the search
const local = useSavedPlaces();
const open = defineModel<boolean>('open', { required: true });

const arrivalText = ref('');
const departureText = ref('');
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-test="flights-dialog">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"
                    ><Plane class="size-5" /> Flights</DialogTitle
                >
                <DialogDescription>
                    The day you land starts at the airport; the day you leave
                    ends there.
                </DialogDescription>
            </DialogHeader>

            <fieldset :disabled="!editable" class="space-y-6">
                <section class="space-y-3">
                    <h3 class="text-sm font-semibold">Arriving</h3>
                    <div
                        v-if="plan.trip.arrival.airport"
                        class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                    >
                        <Plane class="size-4 text-sky-600" />
                        <span class="min-w-0 flex-1 truncate">{{
                            plan.trip.arrival.airport.name
                        }}</span>
                        <button
                            v-if="editable"
                            type="button"
                            class="text-muted-foreground hover:text-destructive"
                            aria-label="No arrival flight"
                            @click="plan.setAirport('arrival', null)"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <PlaceSearch
                        :local="local"
                        v-else
                        v-model="arrivalText"
                        placeholder="Arrival airport"
                        :near="props.near"
                        data-test="arrival-airport"
                        @pick="plan.setAirport('arrival', $event)"
                    />
                    <div class="grid grid-cols-3 gap-2">
                        <div
                            class="text-muted-foreground col-span-3 space-y-1 text-xs sm:col-span-1"
                        >
                            <span class="block">Lands</span>
                            <DateTimeField
                                v-model="plan.trip.arrival.at"
                                kind="datetime"
                                label="Lands"
                                :minute-step="5"
                                compact
                                data-test="lands"
                            />
                        </div>
                        <div class="text-muted-foreground space-y-1 text-xs">
                            <span class="block"
                                >Immigration &amp; bags (min)</span
                            >
                            <NumberField
                                :model-value="plan.trip.arrival.clearMinutes"
                                label="Immigration and bags, in minutes"
                                :min="0"
                                :step="15"
                                compact
                                class="w-full"
                                @update:model-value="
                                    plan.trip.arrival.clearMinutes = $event ?? 0
                                "
                            />
                        </div>
                        <div class="text-muted-foreground space-y-1 text-xs">
                            <span class="block">Rest after check-in (min)</span>
                            <NumberField
                                :model-value="plan.trip.arrival.restMinutes"
                                label="Rest after check-in, in minutes"
                                :min="0"
                                :step="30"
                                compact
                                class="w-full"
                                data-test="arrival-rest"
                                @update:model-value="
                                    plan.trip.arrival.restMinutes = $event ?? 0
                                "
                            />
                        </div>
                    </div>
                </section>

                <section class="space-y-3">
                    <h3 class="text-sm font-semibold">Flying home</h3>
                    <div
                        v-if="plan.trip.departure.airport"
                        class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                    >
                        <Plane class="size-4 rotate-45 text-sky-600" />
                        <span class="min-w-0 flex-1 truncate">{{
                            plan.trip.departure.airport.name
                        }}</span>
                        <button
                            v-if="editable"
                            type="button"
                            class="text-muted-foreground hover:text-destructive"
                            aria-label="No departure flight"
                            @click="plan.setAirport('departure', null)"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <template v-else>
                        <PlaceSearch
                            :local="local"
                            v-model="departureText"
                            placeholder="Departure airport"
                            :near="props.near"
                            data-test="departure-airport"
                            @pick="plan.setAirport('departure', $event)"
                        />
                        <button
                            v-if="plan.trip.arrival.airport && editable"
                            type="button"
                            class="text-primary text-xs hover:underline"
                            @click="
                                plan.setAirport(
                                    'departure',
                                    plan.trip.arrival.airport,
                                )
                            "
                        >
                            Same airport as arrival
                        </button>
                    </template>
                    <div class="grid grid-cols-3 gap-2">
                        <div
                            class="text-muted-foreground col-span-3 space-y-1 text-xs sm:col-span-2"
                        >
                            <span class="block">Flight leaves</span>
                            <DateTimeField
                                v-model="plan.trip.departure.at"
                                kind="datetime"
                                label="Flight leaves"
                                :minute-step="5"
                                compact
                                data-test="flight-leaves"
                            />
                        </div>
                        <div class="text-muted-foreground space-y-1 text-xs">
                            <span class="block">Be there early (min)</span>
                            <NumberField
                                :model-value="plan.trip.departure.earlyMinutes"
                                label="Be there early, in minutes"
                                :min="0"
                                :step="30"
                                compact
                                class="w-full"
                                @update:model-value="
                                    plan.trip.departure.earlyMinutes =
                                        $event ?? 0
                                "
                            />
                        </div>
                    </div>
                </section>
            </fieldset>

            <DialogFooter>
                <Button @click="open = false">Done</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
