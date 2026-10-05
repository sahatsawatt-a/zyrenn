<script setup lang="ts">
import { BedDouble, X } from '@lucide/vue';
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

// The hotels booked, each with its check-in, check-out and what the whole
// stay costs. A day starts at the one slept in the night before and ends at
// the one booked for that night.

const props = defineProps<{
    plan: TripPlan;
    near: () => { lat: number; lng: number };
    editable: boolean;
}>();
const open = defineModel<boolean>('open', { required: true });

const searchText = ref('');
const book = (place: Parameters<TripPlan['addStay']>[0]) => {
    props.plan.addStay(place);
    searchText.value = '';
};

const field =
    'h-9 w-full rounded-md border bg-background px-2 text-sm dark:bg-input/30 dark:[color-scheme:dark]';
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
                        class="grid grid-cols-[1fr_1fr_6rem] gap-2"
                    >
                        <label class="text-muted-foreground space-y-1 text-xs">
                            Check in
                            <input
                                v-model="stay.checkIn"
                                type="datetime-local"
                                :class="field"
                                data-test="check-in"
                            />
                        </label>
                        <label class="text-muted-foreground space-y-1 text-xs">
                            Check out
                            <input
                                v-model="stay.checkOut"
                                type="datetime-local"
                                :class="field"
                                data-test="check-out"
                            />
                        </label>
                        <label class="text-muted-foreground space-y-1 text-xs">
                            Cost{{
                                plan.trip.currency
                                    ? ` (${plan.trip.currency})`
                                    : ''
                            }}
                            <input
                                v-model.number="stay.cost"
                                type="number"
                                min="0"
                                step="any"
                                placeholder="0"
                                :class="field"
                                data-test="stay-cost"
                            />
                        </label>
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
                    <input
                        v-model="plan.trip.fromHotel"
                        type="checkbox"
                        class="size-4"
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
