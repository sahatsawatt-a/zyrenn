<script setup lang="ts">
import { defineAsyncComponent, ref, watch } from 'vue';
import PlaceSearch from '@/components/map/PlaceSearch.vue';
import { useSavedPlaces } from '@/components/map/useSavedPlaces';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Candidate } from '@/lib/maps';
import type { ChatPlace } from './MessagePlace.vue';

// A place to share in a chat: one of the saved places, somewhere searched
// for, or where this device is -- looked at on a map before it is sent.

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ (e: 'pick', place: ChatPlace): void }>();

const PlaceMap = defineAsyncComponent(
    () => import('@/components/map/PlaceMap.vue'),
);

const text = ref('');
const chosen = ref<Candidate | null>(null);
const local = useSavedPlaces();

// Search looks near what is chosen, or -- before anything is -- Bangkok
const near = () =>
    chosen.value
        ? { lat: chosen.value.lat, lng: chosen.value.lng }
        : { lat: 13.75, lng: 100.52 };

// Fresh each time it opens
watch(open, (isOpen) => {
    if (isOpen) {
        text.value = '';
        chosen.value = null;
    }
});

function share() {
    if (!chosen.value) {
        return;
    }

    emit('pick', {
        lat: chosen.value.lat,
        lng: chosen.value.lng,
        title: chosen.value.name || null,
        address: chosen.value.address || null,
    });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-chat-place-dialog>
            <DialogHeader>
                <DialogTitle>Share a place</DialogTitle>
                <DialogDescription>
                    A saved place, anywhere you search for, or where you are
                    now. It is shown in the chat on a small map.
                </DialogDescription>
            </DialogHeader>

            <PlaceSearch
                v-model="text"
                placeholder="Search for a place, or a saved one"
                :near="near"
                :local="local"
                my-location
                autofocus
                data-chat-place-search
                @pick="chosen = $event"
            />

            <div
                class="bg-muted/40 h-56 overflow-hidden rounded-lg border"
                data-chat-place-preview
            >
                <PlaceMap
                    v-if="chosen"
                    :lat="chosen.lat"
                    :lng="chosen.lng"
                    :zoom="15"
                />
                <p
                    v-else
                    class="text-muted-foreground flex size-full items-center justify-center text-sm"
                >
                    Choose a place to see it here.
                </p>
            </div>
            <p v-if="chosen" class="-mt-1 min-w-0 text-sm">
                <span class="font-medium">{{ chosen.name }}</span>
                <span v-if="chosen.address" class="text-muted-foreground">
                    · {{ chosen.address }}</span
                >
            </p>

            <DialogFooter>
                <Button type="button" variant="secondary" @click="open = false"
                    >Cancel</Button
                >
                <Button
                    type="button"
                    :disabled="!chosen"
                    data-chat-place-share
                    @click="share"
                    >Share it</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
