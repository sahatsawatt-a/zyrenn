<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { NodeViewProps } from '@tiptap/vue-3';
import { MapPin } from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import PlaceSearch from '@/components/map/PlaceSearch.vue';
import { savedPlaces, useSavedPlaces } from '@/components/map/useSavedPlaces';
import type { Candidate, SavedChoice } from '@/lib/maps';
import { holdPrint } from '@/lib/printReady';
import { owned } from '@/lib/projects';
import { index as mapsIndex } from '@/routes/maps';
import { index as projectMapsIndex } from '@/routes/projects/maps';
import type { MapBlockPlace } from '@/features/notes/lib/links';
import { readMapFence, writeMapFence } from '@/features/notes/lib/links';

// One place shown in a note: a small map with its pin, what it is called and
// where. Kept as a ```map fence of "key: value" lines -- name, address, lat,
// lng, zoom -- so it reads in Markdown as what it is. A saved place keeps its
// ref_id too, and is shown as that place is now: renamed on the map, it is
// renamed here.
const props = defineProps<NodeViewProps>();

// The PDF printer waits for the map to finish drawing (see printReady)
const releasePrint = holdPrint();
onBeforeUnmount(releasePrint);

const kept = computed(() => readMapFence(props.node.textContent));

// A saved place as it is now, when the block names one
const saved = ref<SavedChoice | null>(null);
onMounted(async () => {
    if (kept.value?.saved) {
        saved.value =
            (await savedPlaces()).find(
                (place) => place.ref_id === kept.value?.saved,
            ) ?? null;
    }
});

const place = computed<MapBlockPlace | null>(() =>
    kept.value && saved.value
        ? {
              ...kept.value,
              name: saved.value.name,
              address: saved.value.address,
              lat: saved.value.lat,
              lng: saved.value.lng,
          }
        : kept.value,
);

const editable = computed(() => props.editor.isEditable);
const choosing = ref(false);
const searchText = ref('');
const local = useSavedPlaces();

/** Writes the place back into the fence, which is what is saved. */
const write = (next: Partial<MapBlockPlace>) => {
    const from = props.getPos();

    if (typeof from !== 'number') {
        return;
    }

    props.editor
        .chain()
        .focus()
        .insertContentAt(
            { from: from + 1, to: from + props.node.nodeSize - 1 },
            writeMapFence(next),
        )
        .run();
};

const pick = (chosen: Candidate) => {
    saved.value = null;
    write({
        name: chosen.name,
        address: chosen.address,
        lat: chosen.lat,
        lng: chosen.lng,
        zoom: kept.value?.zoom ?? 15,
        saved: chosen.savedId ?? '',
    });
    choosing.value = false;
    searchText.value = '';
};

const near = () =>
    place.value
        ? { lat: place.value.lat, lng: place.value.lng }
        : { lat: 13.75, lng: 100.52 };

// ---- the map ----

// The map brings MapLibre with it; most notes hold no map, so it comes when needed
const PlaceMap = defineAsyncComponent(
    () => import('@/components/map/PlaceMap.vue'),
);

// Nothing to draw: nothing to wait for
watch(
    place,
    (now) => {
        if (!now) {
            releasePrint();
        }
    },
    { immediate: true },
);

/** The app's own Maps page, opened on the place. */
const openInMaps = computed(() =>
    place.value
        ? `${owned(mapsIndex, projectMapsIndex).url()}#map=${place.value.zoom}/${place.value.lat.toFixed(5)}/${place.value.lng.toFixed(5)}`
        : '',
);

/** Directions there, in whichever maps app the reader has. */
const directions = computed(() =>
    place.value
        ? `https://www.google.com/maps/dir/?api=1&destination=${place.value.lat},${place.value.lng}`
        : '',
);
</script>

<template>
    <NodeViewWrapper class="map-block" data-test="map-block">
        <div class="map-toolbar" contenteditable="false">
            <MapPin class="text-muted-foreground size-3.5 shrink-0" />
            <div v-if="editable && (choosing || !place)" class="min-w-0 flex-1">
                <PlaceSearch
                    v-model="searchText"
                    placeholder="Search for a place, or a saved one"
                    :near="near"
                    :local="local"
                    :autofocus="choosing"
                    data-test="map-block-search"
                    @pick="pick"
                />
            </div>
            <template v-else-if="place">
                <span class="map-name" data-test="map-block-name">{{
                    place.name || 'Dropped pin'
                }}</span>
                <span v-if="place.address" class="map-address">{{
                    place.address
                }}</span>
            </template>
            <div class="map-actions">
                <button
                    v-if="editable && place && !choosing"
                    type="button"
                    data-test="map-block-change"
                    @click="choosing = true"
                >
                    Change
                </button>
                <button
                    v-if="choosing && place"
                    type="button"
                    @click="choosing = false"
                >
                    Cancel
                </button>
                <a
                    v-if="place"
                    :href="directions"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Directions</a
                >
                <a
                    v-if="place"
                    :href="openInMaps"
                    target="_blank"
                    rel="noopener"
                    data-test="map-block-open"
                    >Open in Maps</a
                >
            </div>
        </div>

        <div
            v-if="place"
            class="map-stage"
            contenteditable="false"
            data-test="map-block-map"
        >
            <PlaceMap
                :lat="place.lat"
                :lng="place.lng"
                :zoom="place.zoom"
                @ready="releasePrint"
            />
        </div>
        <p v-if="!place" class="map-message" contenteditable="false">
            {{
                editable
                    ? 'Search for a place to show it here.'
                    : 'No place chosen.'
            }}
        </p>

        <!-- The fence's own text: the place, kept out of sight -->
        <pre class="map-source"><code /></pre>
    </NodeViewWrapper>
</template>

<style scoped>
.map-block {
    margin: 1rem 0;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background-color: var(--card);
    break-inside: avoid;
}

.map-toolbar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 2.5rem;
    padding: 0.375rem 0.5rem;
    border-bottom: 1px solid var(--border);
    background-color: var(--muted);
    font-size: 0.8125rem;
}

.map-name {
    font-weight: 600;
    white-space: nowrap;
}

.map-address {
    min-width: 0;
    overflow: hidden;
    color: var(--muted-foreground);
    font-size: 0.75rem;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.map-actions {
    display: flex;
    flex-shrink: 0;
    gap: 0.75rem;
    margin-left: auto;
    font-size: 0.75rem;
}
.map-actions a,
.map-actions button {
    color: var(--muted-foreground);
    text-decoration: none;
    cursor: pointer;
}
.map-actions a:hover,
.map-actions button:hover {
    color: var(--foreground);
}

.map-stage {
    height: 260px;
}

.map-message {
    padding: 2rem 1rem;
    text-align: center;
    font-size: 0.8125rem;
    color: var(--muted-foreground);
}

/* The fence still holds the place; nobody needs to read it here */
.map-source {
    display: none;
}

@media print {
    .map-actions {
        display: none;
    }
}
</style>
