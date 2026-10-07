<script setup lang="ts">
import { ExternalLink, MapPin, X } from '@lucide/vue';
import { ref } from 'vue';
import PlaceSearch from '@/components/map/PlaceSearch.vue';
import { useSavedPlaces } from '@/components/map/useSavedPlaces';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    CELL_PICKER,
    useCellCell,
} from '@/features/tables/composables/useCellCell';
import { rows } from '@/features/tables/composables/useTableState';
import type { Candidate } from '@/lib/maps';
import type { ColumnMeta, LocationValue } from '@/types';

// A place: shown by its name or address, chosen by searching -- the same
// search the maps use, nearest the places already in the column first.
const props = defineProps<{
    column: ColumnMeta;
    modelValue?: LocationValue | null;
}>();

// Saved places come first in the search
const local = useSavedPlaces();
const emit = defineEmits<{
    (e: 'update:modelValue', value: LocationValue | null): void;
}>();

const cell = useCellCell();
const text = ref('');

/** Where "near" is: this cell's place, or else another one in the column. */
const near = () => {
    const here =
        props.modelValue ??
        (rows.value
            .map((row) => row[props.column.name] as LocationValue | null)
            .find((place) => place) ||
            null);

    return here
        ? { lat: here.lat, lng: here.lng }
        : { lat: 13.75, lng: 100.52 };
};

const pick = (place: Candidate) => {
    emit('update:modelValue', {
        lat: place.lat,
        lng: place.lng,
        label: place.address ? `${place.name}, ${place.address}` : place.name,
        // Chosen from the saved places: kept in step with it
        ...(place.savedId ? { place: place.savedId } : {}),
    });
    cell.isOpen.value = false;
    text.value = '';
};

const clear = () => {
    emit('update:modelValue', null);
    cell.isOpen.value = false;
};
</script>

<template>
    <Popover v-model:open="cell.isOpen.value">
        <PopoverTrigger as-child>
            <button
                type="button"
                :class="CELL_PICKER"
                data-test="cell-location"
            >
                <template v-if="modelValue">
                    <MapPin class="size-3.5 shrink-0 text-rose-500" />
                    <span class="truncate">{{
                        modelValue.label ||
                        `${modelValue.lat.toFixed(5)}, ${modelValue.lng.toFixed(5)}`
                    }}</span>
                </template>
                <span v-else class="text-muted-foreground/50 text-sm"
                    >Add a place</span
                >
            </button>
        </PopoverTrigger>
        <PopoverContent align="start" class="w-80 space-y-2 p-2">
            <PlaceSearch
                :local="local"
                v-model="text"
                placeholder="Search for a place"
                :near="near"
                autofocus
                @pick="pick"
            />
            <div v-if="modelValue" class="flex items-center gap-3 px-1 text-xs">
                <a
                    :href="`https://www.openstreetmap.org/?mlat=${modelValue.lat}&mlon=${modelValue.lng}#map=17/${modelValue.lat}/${modelValue.lng}`"
                    target="_blank"
                    rel="noopener"
                    class="text-muted-foreground flex items-center gap-1 hover:underline"
                >
                    <ExternalLink class="size-3" /> Open in OpenStreetMap
                </a>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-destructive ml-auto flex items-center gap-1"
                    data-test="cell-location-clear"
                    @click="clear"
                >
                    <X class="size-3" /> Remove
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
