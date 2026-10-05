<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import type {
    GeoJSONSource,
    Map as MapLibreMap,
    MapGeoJSONFeature,
} from 'maplibre-gl';
import { computed, ref, toRaw, watch, watchEffect } from 'vue';
import BasemapSwitcher from '@/components/Map/BasemapSwitcher.vue';
import DirectionsPanel from '@/components/Map/explore/DirectionsPanel.vue';
import PlaceCard from '@/components/Map/explore/PlaceCard.vue';
import PlacesPanel from '@/components/Map/explore/PlacesPanel.vue';
import { usePlaceStore } from '@/components/Map/explore/usePlaceStore';
import FloatingPanel from '@/components/Map/FloatingPanel.vue';
import { pin, placePin } from '@/components/Map/markers';
import type { LocalPlace } from '@/components/Map/PlaceSearch.vue';
import PlaceSearch from '@/components/Map/PlaceSearch.vue';
import { useMap } from '@/components/Map/useMap';
import { matchingSaved } from '@/components/Map/useSavedPlaces';
import type { Candidate, PlaceList, Route, SavedPlace } from '@/lib/maps';
import { whatIsHere } from '@/lib/maps';
import { canChange, owned } from '@/lib/projects';
import { index as ownIndex } from '@/routes/maps';
import { index as projectIndex } from '@/routes/projects/maps';

// The map: find a place, save it to a list, get directions. The map fills
// the page; everything else is in the panel floating over it.

const props = defineProps<{ lists: PlaceList[]; places: SavedPlace[] }>();

const editable = canChange();
const store = usePlaceStore(props);

watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [{ title: 'Maps', href: owned(ownIndex, projectIndex)() }],
    }),
);

type Panel = 'places' | 'place' | 'directions';
const panel = ref<Panel>('places');
const folded = ref(false);
const current = ref<Candidate | null>(null);
const from = ref<Candidate | null>(null);
const to = ref<Candidate | null>(null);
const searchText = ref('');

// ---- what is drawn: saved places, and the route ----

const savedData = computed<GeoJSON.FeatureCollection>(() => ({
    type: 'FeatureCollection',
    features: store.shown.value.map((place) => ({
        type: 'Feature',
        geometry: { type: 'Point', coordinates: [place.lng, place.lat] },
        properties: {
            id: place.ref_id,
            name: place.name,
            color: store.listOf(place)?.color ?? '#3b82f6',
        },
    })),
}));

let routeData: GeoJSON.FeatureCollection = {
    type: 'FeatureCollection',
    features: [],
};

const addLayers = (made: MapLibreMap, dark: boolean) => {
    made.addSource('route', { type: 'geojson', data: routeData });
    made.addSource('saved', { type: 'geojson', data: savedData.value });

    made.addLayer({
        id: 'route-casing',
        type: 'line',
        source: 'route',
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': '#ffffff', 'line-width': 9 },
    });
    made.addLayer({
        id: 'route-line',
        type: 'line',
        source: 'route',
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': '#2563eb', 'line-width': 5 },
    });
    made.addLayer({
        id: 'saved-points',
        type: 'circle',
        source: 'saved',
        paint: {
            'circle-color': ['get', 'color'],
            'circle-radius': ['interpolate', ['linear'], ['zoom'], 8, 5, 15, 8],
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 2,
        },
    });
    made.addLayer({
        id: 'saved-labels',
        type: 'symbol',
        source: 'saved',
        minzoom: 12,
        layout: {
            'text-field': ['get', 'name'],
            'text-font': ['Noto Sans Bold'],
            'text-size': 12,
            'text-offset': [0, 1.1],
            'text-anchor': 'top',
            'text-optional': true,
        },
        paint: {
            'text-color': dark ? '#f8fafc' : '#0f172a',
            'text-halo-color': dark ? '#0f172a' : '#ffffff',
            'text-halo-width': 1.5,
        },
    });
};

const host = ref<HTMLElement>();
const { map, basemap, pointer, centre, frame } = useMap(host, {
    hash: 'map',
    onStyle: addLayers,
});

watch(
    savedData,
    (data) => void map.value?.getSource<GeoJSONSource>('saved')?.setData(data),
);

// ---- pins: the place being looked at, and a route's two ends ----

const pins = {
    current: pin('#ef4444'),
    from: pin('#16a34a', 'A'),
    to: pin('#dc2626', 'B'),
};

watch([current, panel, map], () =>
    placePin(
        pins.current,
        map.value,
        panel.value === 'place' ? current.value : null,
    ),
);
watch([from, panel, map], () =>
    placePin(
        pins.from,
        map.value,
        panel.value === 'directions' ? from.value : null,
    ),
);
watch([to, panel, map], () =>
    placePin(
        pins.to,
        map.value,
        panel.value === 'directions' ? to.value : null,
    ),
);

// ---- looking at a place ----

/** Saved places matching what is typed, for the search boxes. */
const local = (term: string): LocalPlace[] =>
    matchingSaved(
        store.places.value.map((place) => ({
            ...place,
            list: store.listOf(place)?.name ?? null,
            color: store.listOf(place)?.color ?? null,
        })),
        term,
    );

const show = (target: Candidate) => {
    current.value = target;
    panel.value = 'place';
    folded.value = false;
    map.value?.flyTo({
        center: [target.lng, target.lat],
        zoom: Math.max(map.value.getZoom(), 15),
        duration: 900,
    });
};

const openSaved = (saved: SavedPlace) =>
    show({ ...saved, savedId: saved.ref_id });

const closeCard = () => {
    current.value = null;
    searchText.value = '';
    panel.value = 'places';
};

// A shop or landmark the map labels has its name in the tile itself
const POI_LAYERS = new Set(['poi', 'aerodrome_label']);
const fromFeature = (
    feature: MapGeoJSONFeature,
    lat: number,
    lng: number,
): Candidate => ({
    name:
        feature.properties.name ??
        feature.properties['name:latin'] ??
        'Unnamed place',
    address: '',
    kind: `${feature.properties.class ?? 'place'}/${feature.properties.subclass ?? feature.properties.class ?? ''}`,
    lat,
    lng,
});

// Each click asks for an address; only the latest one's answer is used
let asked = 0;

const fillAddress = async (
    target: Candidate,
    apply: (filled: Candidate) => void,
) => {
    const mine = ++asked;
    const hit = await whatIsHere(target.lat, target.lng).catch(() => null);

    if (hit && mine === asked) {
        const dropped = target.name === 'Dropped pin';
        apply({
            ...target,
            name: dropped ? hit.name : target.name,
            address: dropped ? hit.address : hit.label,
        });
    }
};

const clicked = (event: {
    point: { x: number; y: number };
    lngLat: { lat: number; lng: number };
}) => {
    const made = map.value;

    if (!made) {
        return;
    }

    const at: [number, number] = [event.point.x, event.point.y];
    const saved = made.queryRenderedFeatures(at, {
        layers: ['saved-points'],
    })[0];

    if (saved && panel.value !== 'directions') {
        const found = store.places.value.find(
            (place) => place.ref_id === saved.properties.id,
        );

        if (found) {
            openSaved(found);
        }

        return;
    }

    const poi = made
        .queryRenderedFeatures(at)
        .find(
            (feature) =>
                POI_LAYERS.has(feature.sourceLayer ?? '') &&
                feature.properties.name,
        );
    const [lng, lat] =
        poi?.geometry.type === 'Point'
            ? poi.geometry.coordinates
            : [event.lngLat.lng, event.lngLat.lat];
    const target: Candidate = poi
        ? fromFeature(poi, lat, lng)
        : { name: 'Dropped pin', address: '', kind: '', lat, lng };

    // Planning a route: a click fills whichever end is still empty
    if (panel.value === 'directions') {
        const end = from.value ? to : from;
        end.value = target;
        void fillAddress(target, (filled) => {
            // A ref hands back a reactive copy, so compare what it wraps
            if (toRaw(end.value) === target) {
                end.value = filled;
            }
        });

        return;
    }

    show(target);
    void fillAddress(target, (filled) => {
        if (toRaw(current.value) === target) {
            current.value = filled;
        }
    });
};

watch(map, (made) => {
    if (!made) {
        return;
    }

    made.on('click', clicked);
    pointer([
        'saved-points',
        'poi_r1',
        'poi_r7',
        'poi_r20',
        'poi_transit',
        'airport',
    ]);

    // Open on the saved places, unless a shared link says where to look
    if (!location.hash.includes('map=') && store.places.value.length) {
        made.once('load', () => frame(store.places.value, 14));
    }

    // For the e2e suite to read the map with
    Object.assign(window, { __maps: { map: () => map.value, store } });
});

// ---- directions ----

const startDirections = (end: 'from' | 'to', target: Candidate) => {
    if (end === 'to') {
        to.value = target;
    } else {
        from.value = target;
    }

    panel.value = 'directions';
};

const showRoute = (found: Route | null) => {
    routeData = {
        type: 'FeatureCollection',
        features: found
            ? [
                  {
                      type: 'Feature',
                      geometry: { type: 'LineString', coordinates: found.line },
                      properties: {},
                  },
              ]
            : [],
    };
    void map.value?.getSource<GeoJSONSource>('route')?.setData(routeData);

    if (found && found.line.length > 1) {
        frame(
            found.line.map(([lng, lat]) => ({ lat, lng })),
            16,
        );
    }
};

const closeDirections = () => {
    showRoute(null);
    from.value = null;
    to.value = null;
    panel.value = current.value ? 'place' : 'places';
};
</script>

<template>
    <Head title="Maps">
        <!-- The tile server's connection opened while the map code loads -->
        <link
            rel="preconnect"
            href="https://tiles.openfreemap.org"
            crossorigin=""
        />
    </Head>

    <div
        class="relative h-[calc(100svh-4rem)] overflow-hidden"
        data-test="maps-page"
    >
        <!-- Sized by its parent, not positioned: MapLibre makes its container
             position: relative, which would undo an inset-0 -->
        <div ref="host" class="h-full w-full" data-test="maps-map" />
        <BasemapSwitcher v-model="basemap" />

        <FloatingPanel v-model:folded="folded">
            <template #header>
                <div
                    v-if="panel === 'directions'"
                    class="flex items-center gap-2"
                >
                    <button
                        type="button"
                        class="text-muted-foreground hover:bg-accent rounded-md p-1"
                        aria-label="Close directions"
                        @click="closeDirections"
                    >
                        <ArrowLeft class="size-4" />
                    </button>
                    <h1 class="font-semibold">Directions</h1>
                </div>
                <PlaceSearch
                    v-else
                    v-model="searchText"
                    placeholder="Search places"
                    :near="centre"
                    :local="local"
                    @pick="show"
                />
            </template>

            <PlaceCard
                v-if="panel === 'place' && current"
                :place="current"
                :store="store"
                :editable="editable"
                @close="closeCard"
                @directions-to="startDirections('to', $event)"
                @directions-from="startDirections('from', $event)"
                @saved="current = $event"
            />
            <DirectionsPanel
                v-else-if="panel === 'directions'"
                v-model:from="from"
                v-model:to="to"
                :near="centre"
                :local="local"
                @route="showRoute"
                @focus="map?.flyTo({ center: $event, zoom: 17 })"
            />
            <PlacesPanel
                v-else
                :store="store"
                :editable="editable"
                @open="openSaved"
            />
        </FloatingPanel>
    </div>
</template>
