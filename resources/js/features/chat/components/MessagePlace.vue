<script setup lang="ts">
import { MapPin } from '@lucide/vue';
import { computed, defineAsyncComponent, ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { listPlaceLists, savePlace } from '@/lib/maps';
import type { PlaceList } from '@/lib/maps';
import { owned } from '@/lib/projects';
import { index as mapsIndex } from '@/routes/maps';
import { index as projectMapsIndex } from '@/routes/projects/maps';
import type { ChatPlace as Place } from '../types';

// A place under a chat message: a small map with its pin, what it is called
// and where, opened larger over the chat -- with the way there, the app's
// own map of it, and a way to keep it among one's saved places.

export type ChatPlace = Place;

const props = defineProps<{ place: ChatPlace }>();

// The map brings MapLibre with it; most chats show no place, so it comes when one does
const PlaceMap = defineAsyncComponent(
    () => import('@/components/map/PlaceMap.vue'),
);

const ZOOM = 15;
const open = ref(false);

// Kept among one's own saved places: in which list is asked when it is wanted
const lists = ref<PlaceList[] | null>(null);
const saving = ref(false);
const savedIn = ref<string | null>(null);
const failed = ref(false);

async function offerLists(opened: boolean) {
    if (opened && lists.value === null) {
        lists.value = await listPlaceLists().catch(() => []);
    }
}

async function save(list: PlaceList) {
    saving.value = true;
    failed.value = false;

    try {
        await savePlace(list.ref_id, {
            name: name.value,
            address: props.place.address ?? '',
            kind: 'place',
            lat: props.place.lat,
            lng: props.place.lng,
        });
        savedIn.value = list.name;
    } catch {
        failed.value = true;
    } finally {
        saving.value = false;
    }
}

const name = computed(() => props.place.title || 'A place');

/** The way there, in whichever maps app the reader has. */
const directions = computed(
    () =>
        `https://www.google.com/maps/dir/?api=1&destination=${props.place.lat},${props.place.lng}`,
);

/** The app's own Maps page, opened on the place with its pin and its card. */
const inMaps = computed(
    () =>
        `${owned(mapsIndex, projectMapsIndex).url({
            query: {
                pin: `${props.place.lat},${props.place.lng}`,
                ...(props.place.title ? { name: props.place.title } : {}),
                ...(props.place.address
                    ? { address: props.place.address }
                    : {}),
            },
        })}#map=${ZOOM}/${props.place.lat.toFixed(5)}/${props.place.lng.toFixed(5)}`,
);
</script>

<template>
    <div
        class="bg-card mt-1 w-64 max-w-full overflow-hidden rounded-2xl border text-left sm:w-72"
        data-chat-place
    >
        <button
            type="button"
            class="block h-36 w-full cursor-zoom-in"
            :aria-label="`Open the map of ${name}`"
            @click="open = true"
        >
            <!-- Drawn once and left: the card is something to press, not to drag -->
            <span class="small-map pointer-events-none block size-full">
                <PlaceMap :lat="place.lat" :lng="place.lng" :zoom="ZOOM" />
            </span>
        </button>
        <div class="flex items-start gap-2 px-3 py-2">
            <MapPin class="mt-0.5 size-3.5 shrink-0 text-red-500" />
            <div class="min-w-0 flex-1">
                <p class="text-foreground truncate text-sm font-medium">
                    {{ name }}
                </p>
                <p
                    v-if="place.address"
                    class="text-muted-foreground line-clamp-2 text-xs"
                >
                    {{ place.address }}
                </p>
                <p
                    class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs whitespace-nowrap"
                >
                    <a
                        :href="directions"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary underline-offset-2 hover:underline"
                        >Directions</a
                    >
                    <a
                        :href="inMaps"
                        class="text-primary underline-offset-2 hover:underline"
                        data-chat-place-open
                        >Open in Maps</a
                    >
                    <span
                        v-if="savedIn"
                        class="text-muted-foreground"
                        data-chat-place-saved
                        >Saved in {{ savedIn }}</span
                    >
                    <DropdownMenu v-else @update:open="offerLists">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="text-primary underline-offset-2 hover:underline disabled:opacity-60"
                                :disabled="saving"
                                data-chat-place-save
                            >
                                {{ saving ? 'Saving…' : 'Save' }}
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuLabel class="text-xs font-normal">
                                {{
                                    lists === null
                                        ? 'Loading…'
                                        : lists.length
                                          ? 'Save it in'
                                          : 'No lists yet: make one in Maps.'
                                }}
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                v-for="list in lists ?? []"
                                :key="list.ref_id"
                                @select="save(list)"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{ backgroundColor: list.color }"
                                />
                                {{ list.name }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </p>
                <p v-if="failed" class="text-destructive mt-1 text-xs">
                    Couldn’t save it.
                </p>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent
                class="flex max-h-[92vh] w-[92vw] flex-col gap-3 p-3 sm:max-w-3xl"
                data-chat-place-larger
            >
                <DialogTitle class="truncate pr-8 text-sm font-medium">{{
                    name
                }}</DialogTitle>
                <DialogDescription
                    class="text-muted-foreground -mt-2 truncate text-xs"
                    >{{
                        place.address ||
                        `${place.lat.toFixed(5)}, ${place.lng.toFixed(5)}`
                    }}</DialogDescription
                >
                <div class="h-[60vh] overflow-hidden rounded-lg border">
                    <PlaceMap
                        v-if="open"
                        :lat="place.lat"
                        :lng="place.lng"
                        :zoom="ZOOM + 1"
                    />
                </div>
                <p class="flex gap-4 text-xs">
                    <a
                        :href="directions"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary underline-offset-2 hover:underline"
                        >Directions</a
                    >
                    <a
                        :href="inMaps"
                        class="text-primary underline-offset-2 hover:underline"
                        >Open in Maps</a
                    >
                </p>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
/* On a map this small, who the map is by takes one fine line at its foot,
   not a box over the place itself */
.small-map :deep(.maplibregl-ctrl-attrib) {
    margin: 0;
    padding: 0 4px;
    border-radius: 4px 0 0 0;
    background-color: rgb(255 255 255 / 0.75);
    min-height: 0;
}

.small-map :deep(.maplibregl-ctrl-attrib-inner) {
    display: block;
    overflow: hidden;
    max-width: 15rem;
    font-size: 9px;
    line-height: 14px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.small-map :deep(.maplibregl-ctrl-attrib-button),
.small-map :deep(.maplibregl-ctrl-attrib summary) {
    display: none !important;
}
</style>
