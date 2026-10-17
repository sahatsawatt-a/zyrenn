<script setup lang="ts">
import { BedDouble, X } from '@lucide/vue';
import { ref } from 'vue';
import DateTimeField from '@/components/common/DateTimeField.vue';
import NumberField from '@/components/common/NumberField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

// The hotels booked, each with its check-in, check-out and what the whole
// stay costs. A day starts at the one slept in the night before and ends at
// the one booked for that night.

const props = defineProps<{
    plan: TripPlan;
    near: () => { lat: number; lng: number };
    editable: boolean;
}>();

// Saved places come first in the search
const local = useSavedPlaces();
const open = defineModel<boolean>('open', { required: true });

const searchText = ref('');
const book = (place: Parameters<TripPlan['addStay']>[0]) => {
    props.plan.addStay(place);
    searchText.value = '';
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-test="hotels-dialog">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"
                    ><BedDouble class="size-5" /> Hotels</DialogTitle
                >
                <DialogDescription>
                    Each day starts at the hotel you woke up in and ends at the
                    one booked for that night.
                </DialogDescription>
            </DialogHeader>

            <div class="max-h-[50vh] space-y-3 overflow-y-auto">
                <div
                    v-for="stay in plan.trip.stays"
                    :key="stay.id"
                    class="space-y-2 rounded-lg border p-3"
                    data-test="stay"
                >
                    <div class="flex items-center gap-2">
                        <BedDouble class="text-muted-foreground size-4" />
                        <span
                            class="min-w-0 flex-1 truncate text-sm font-medium"
                            >{{ stay.place.name }}</span
                        >
                        <span class="text-muted-foreground text-xs">
                            {{ plan.nightsOf(stay) }}
                            {{ plan.nightsOf(stay) === 1 ? 'night' : 'nights' }}
                        </span>
                        <button
                            v-if="editable"
                            type="button"
                            class="text-muted-foreground hover:text-destructive"
                            :aria-label="`Remove ${stay.place.name}`"
                            @click="plan.removeStay(stay.id)"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <fieldset
                        :disabled="!editable"
                        class="grid grid-cols-2 gap-2 sm:grid-cols-[1fr_1fr_7.5rem]"
                    >
                        <div
                            class="text-muted-foreground min-w-0 space-y-1 text-xs"
                        >
                            <span class="block">Check in</span>
                            <DateTimeField
                                v-model="stay.checkIn"
                                kind="datetime"
                                label="Check in"
                                :disabled="!editable"
                                compact
                                data-test="check-in"
                            />
                        </div>
                        <div
                            class="text-muted-foreground min-w-0 space-y-1 text-xs"
                        >
                            <span class="block">Check out</span>
                            <DateTimeField
                                v-model="stay.checkOut"
                                kind="datetime"
                                label="Check out"
                                :min="stay.checkIn"
                                :disabled="!editable"
                                compact
                                data-test="check-out"
                            />
                        </div>
                        <div class="text-muted-foreground space-y-1 text-xs">
                            <span class="block"
                                >Cost{{
                                    plan.trip.currency
                                        ? ` (${plan.trip.currency})`
                                        : ''
                                }}</span
                            >
                            <NumberField
                                :model-value="stay.cost"
                                label="Cost of the stay"
                                :min="0"
                                :step="0.01"
                                placeholder="0"
                                :disabled="!editable"
                                compact
                                class="w-full"
                                data-test="stay-cost"
                                @update:model-value="stay.cost = $event ?? 0"
                            />
                        </div>
                    </fieldset>
                </div>
                <p
                    v-if="!plan.trip.stays.length"
                    class="text-muted-foreground text-sm"
                >
                    No hotel booked yet.
                </p>
            </div>

            <template v-if="editable">
                <PlaceSearch
                    :local="local"
                    v-model="searchText"
                    :placeholder="
                        plan.trip.stays.length
                            ? 'Add another hotel'
                            : 'Find your hotel'
                    "
                    :near="props.near"
                    data-test="add-stay"
                    @pick="book"
                />
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="plan.trip.fromHotel"
                        data-test="from-hotel"
                        @update:model-value="plan.trip.fromHotel = !!$event"
                    />
                    Days start and end at the hotel
                </label>
            </template>

            <DialogFooter>
                <Button @click="open = false">Done</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
