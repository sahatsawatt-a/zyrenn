<script setup lang="ts">
import { Plane, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import PlaceSearch from '../PlaceSearch.vue';
import type { TripPlan } from './useTripPlan';

// The flights in and out: where and when, how long the airport takes on the
// way in, the rest after it, and how early to be there on the way out.

const props = defineProps<{
    plan: TripPlan;
    near: () => { lat: number; lng: number };
    editable: boolean;
}>();
const open = defineModel<boolean>('open', { required: true });

const arrivalText = ref('');
const departureText = ref('');
const field =
    'h-9 w-full rounded-md border bg-background px-2 text-sm dark:bg-input/30 dark:[color-scheme:dark]';
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
                        v-else
                        v-model="arrivalText"
                        placeholder="Arrival airport"
                        :near="props.near"
                        data-test="arrival-airport"
                        @pick="plan.setAirport('arrival', $event)"
                    />
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            class="text-muted-foreground col-span-3 space-y-1 text-xs sm:col-span-1"
                        >
                            Lands
                            <input
                                v-model="plan.trip.arrival.at"
                                type="datetime-local"
                                :class="field"
                                data-test="lands"
                            />
                        </label>
                        <label class="text-muted-foreground space-y-1 text-xs">
                            Immigration &amp; bags (min)
                            <input
                                v-model.number="plan.trip.arrival.clearMinutes"
                                type="number"
                                min="0"
                                step="15"
                                :class="field"
                            />
                        </label>
                        <label class="text-muted-foreground space-y-1 text-xs">
                            Rest after check-in (min)
                            <input
                                v-model.number="plan.trip.arrival.restMinutes"
                                type="number"
                                min="0"
                                step="30"
                                :class="field"
                                data-test="arrival-rest"
                            />
                        </label>
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
                        <label
                            class="text-muted-foreground col-span-3 space-y-1 text-xs sm:col-span-2"
                        >
                            Flight leaves
                            <input
                                v-model="plan.trip.departure.at"
                                type="datetime-local"
                                :class="field"
                                data-test="flight-leaves"
                            />
                        </label>
                        <label class="text-muted-foreground space-y-1 text-xs">
                            Be there early (min)
                            <input
                                v-model.number="
                                    plan.trip.departure.earlyMinutes
                                "
                                type="number"
                                min="0"
                                step="30"
                                :class="field"
                            />
                        </label>
                    </div>
                </section>
            </fieldset>

            <DialogFooter>
                <Button @click="open = false">Done</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
