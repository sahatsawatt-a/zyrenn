<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import type {
    GeoJSONSource,
    Map as MapLibreMap,
    MapGeoJSONFeature,
    Marker,
} from 'maplibre-gl';
import { computed, ref, toRaw, watch, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import BasemapSwitcher from '@/components/Map/BasemapSwitcher.vue';
import FloatingPanel from '@/components/Map/FloatingPanel.vue';
import { pin, placePin } from '@/components/Map/markers';
import TripHeader from '@/components/Map/trip/TripHeader.vue';
import TripPanel from '@/components/Map/trip/TripPanel.vue';
import TripPlaceCard from '@/components/Map/trip/TripPlaceCard.vue';
import type { TripContent } from '@/components/Map/trip/useTripPlan';
import { useTripPlan } from '@/components/Map/trip/useTripPlan';
import { useMap } from '@/components/Map/useMap';
import { usePresence } from '@/composables/usePresence';
import type { Candidate } from '@/lib/maps';
import { whatIsHere } from '@/lib/maps';
import { canChange, owned } from '@/lib/projects';
import { index as projectIndex } from '@/routes/projects/trips';
import { index as ownIndex, show } from '@/routes/trips';

// A trip: the map shows every day's way in its colour, the chosen day over
// the rest; the panel floating over it holds the plan, day by day.

const props = defineProps<{
    trip: {
        ref_id: string;
        title: string;
        content: TripContent;
        revision: number;
        updated_at: string;
    };
    breadcrumbs: { ref_id: string; name: string }[];
}>();

const editable = canChange();
const plan = useTripPlan(props.trip, editable);
const { trip, routes, selectedDayId } = plan;

const index = owned(ownIndex, projectIndex);
watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            { title: 'Trips', href: index() },
            ...props.breadcrumbs.map((crumb) => ({
                title: crumb.name,
                href: index({ query: { folder: crumb.ref_id } }),
            })),
            {
                title: plan.title.value || 'Untitled trip',
                href: show(props.trip.ref_id),
            },
        ],
    }),
);

const folded = ref(false);
const picked = ref<Candidate | null>(null);

// ---- what is drawn: each day's way, its stops numbered ----

const onDay = (dayId: string) =>
    selectedDayId.value === null || selectedDayId.value === dayId;

const lines = computed<GeoJSON.FeatureCollection>(() => ({
    type: 'FeatureCollection',
    features: trip.days.flatMap((day) =>
        // The day's own route, and its rides to and from the airport
        (['', ':in', ':out'] as const).flatMap((part) => {
            const line = routes[`${day.id}${part}`]?.route?.line;

            return line
                ? [
                      {
                          type: 'Feature' as const,
                          geometry: {
                              type: 'LineString' as const,
                              coordinates: line,
                          },
                          properties: {
                              color: plan.colorOf(day.id),
                              active: onDay(day.id),
                              transfer: part !== '',
                          },
                      },
                  ]
                : [];
        }),
    ),
}));

const stops = computed<GeoJSON.FeatureCollection>(() => ({
    type: 'FeatureCollection',
    features: trip.days.flatMap((day) =>
        // Sights only, numbered as the day lists them: a rest is where you
        // already are, or at the hotel's own pin
        day.stops
            .filter((stop) => !stop.rest)
            .map((stop, index) => ({
                type: 'Feature' as const,
                geometry: {
                    type: 'Point' as const,
                    coordinates: [stop.lng, stop.lat],
                },
                properties: {
                    n: String(index + 1),
                    color: plan.colorOf(day.id),
                    active: onDay(day.id),
                    dayId: day.id,
                },
            })),
    ),
}));

const addLayers = (made: MapLibreMap) => {
    made.addSource('trip-lines', { type: 'geojson', data: lines.value });
    made.addSource('trip-stops', { type: 'geojson', data: stops.value });

    made.addLayer({
        id: 'trip-lines',
        type: 'line',
        source: 'trip-lines',
        filter: ['!', ['get', 'transfer']],
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: {
            'line-color': ['get', 'color'],
            'line-width': ['case', ['get', 'active'], 5, 3],
            'line-opacity': ['case', ['get', 'active'], 0.9, 0.25],
        },
    });
    // A dash pattern can't vary by feature, so transfers are a layer of their own
    made.addLayer({
        id: 'trip-transfers',
        type: 'line',
        source: 'trip-lines',
        filter: ['get', 'transfer'],
        paint: {
            'line-color': ['get', 'color'],
            'line-width': ['case', ['get', 'active'], 4, 2],
            'line-opacity': ['case', ['get', 'active'], 0.9, 0.25],
            'line-dasharray': [2, 1.5],
        },
    });
    made.addLayer({
        id: 'trip-stops',
        type: 'circle',
        source: 'trip-stops',
        paint: {
            'circle-color': ['get', 'color'],
            'circle-radius': 11,
            'circle-opacity': ['case', ['get', 'active'], 1, 0.35],
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 2,
            'circle-stroke-opacity': ['case', ['get', 'active'], 1, 0.35],
        },
    });
    made.addLayer({
        id: 'trip-numbers',
        type: 'symbol',
        source: 'trip-stops',
        layout: {
            'text-field': ['get', 'n'],
            'text-font': ['Noto Sans Bold'],
            'text-size': 12,
            'text-allow-overlap': true,
            'text-ignore-placement': true,
        },
        paint: {
            'text-color': '#ffffff',
            'text-opacity': ['case', ['get', 'active'], 1, 0.4],
        },
    });
};

const host = ref<HTMLElement>();
const { map, basemap, pointer, centre, frame } = useMap(host, {
    onStyle: addLayers,
});

watch(
    lines,
    (data) =>
        void map.value?.getSource<GeoJSONSource>('trip-lines')?.setData(data),
);
watch(
    stops,
    (data) =>
        void map.value?.getSource<GeoJSONSource>('trip-stops')?.setData(data),
);

// ---- pins: hotels, airports, and the place picked ----

let tripPins: Marker[] = [];
const pickedPin = pin('#ef4444');

// Watched as plain coordinates, never deep: a deep watch would walk into the
// MapLibre map itself, and Vue can't traverse its internals
watch(
    () =>
        JSON.stringify([
            trip.stays.map((stay) => [stay.place.lat, stay.place.lng]),
            [trip.arrival.airport?.lat, trip.arrival.airport?.lng],
            [trip.departure.airport?.lat, trip.departure.airport?.lng],
            !!map.value,
        ]),
    () => {
        tripPins.forEach((marker) => marker.remove());
        tripPins = [];

        const add = (at: Candidate, color: string, label: string) => {
            const marker = pin(color, label);
            placePin(marker, map.value, at);
            tripPins.push(marker);
        };

        trip.stays.forEach((stay) => add(stay.place, '#334155', 'H'));

        // One plane where you land and leave from the same airport
        const airports = [trip.arrival.airport, trip.departure.airport].filter(
            (airport, index, all): airport is NonNullable<typeof airport> =>
                !!airport &&
                all.findIndex(
                    (other) =>
                        other?.lat === airport.lat &&
                        other?.lng === airport.lng,
                ) === index,
        );
        airports.forEach((airport) => add(airport, '#0284c7', 'plane'));
    },
);

watch([picked, map], () => placePin(pickedPin, map.value, picked.value));

/** Frames a day -- or the whole trip -- with its hotels and airports. */
const frameTrip = () =>
    frame(
        trip.days
            .filter((day) => onDay(day.id))
            .flatMap((day) =>
                plan
                    .entriesOf(day)
                    .flatMap((entry) =>
                        'place' in entry ? [entry.place] : [],
                    ),
            ),
    );

watch(selectedDayId, frameTrip);

const focus = (place: Candidate) => {
    picked.value = null;
    map.value?.flyTo({
        center: [place.lng, place.lat],
        zoom: Math.max(map.value.getZoom(), 15),
        duration: 800,
    });
};

// ---- clicking the map ----

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

let asked = 0;

const clicked = (event: {
    point: { x: number; y: number };
    lngLat: { lat: number; lng: number };
}) => {
    const made = map.value;

    if (!made) {
        return;
    }

    const at: [number, number] = [event.point.x, event.point.y];

    // A numbered stop brings its day into view
    const stop = made.queryRenderedFeatures(at, { layers: ['trip-stops'] })[0];

    if (stop) {
        selectedDayId.value = stop.properties.dayId;
        picked.value = null;

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
    picked.value = target;

    // Its address, a moment later -- only the latest click's
    const mine = ++asked;
    void whatIsHere(lat, lng)
        .then((hit) => {
            // A ref hands back a reactive copy, so compare what it wraps
            if (hit && mine === asked && toRaw(picked.value) === target) {
                const dropped = target.name === 'Dropped pin';
                picked.value = {
                    ...target,
                    name: dropped ? hit.name : target.name,
                    address: dropped ? hit.address : hit.label,
                };
            }
        })
        .catch(() => {});
};

watch(map, (made) => {
    if (!made) {
        return;
    }

    made.on('click', clicked);
    pointer([
        'trip-stops',
        'poi_r1',
        'poi_r7',
        'poi_r20',
        'poi_transit',
        'airport',
    ]);
    made.once('load', frameTrip);

    // For the e2e suite to read the map and the plan with
    Object.assign(window, { __trip: { map: () => map.value, plan } });
});

// ---- someone else saved ----

// With nothing unsaved here, their copy is loaded quietly; with changes of
// our own, the next save finds theirs and asks which to keep
usePresence(() => `trips.${props.trip.ref_id}`, {
    changed: (change: { change: 'saved' | 'deleted'; revision: number }) => {
        if (change.change === 'deleted') {
            toast.info('Someone deleted this trip.');
            router.visit(index());
        } else if (plan.canCatchUp(change.revision)) {
            router.reload({
                only: ['trip'],
                onSuccess: () => plan.catchUp(props.trip),
            });
        }
    },
});

const conflictOpen = computed({
    get: () => plan.conflict.value !== null,
    set: () => {},
});
</script>

<template>
    <Head :title="plan.title.value || 'Untitled trip'">
        <link
            rel="preconnect"
            href="https://tiles.openfreemap.org"
            crossorigin=""
        />
    </Head>

    <div
        class="relative h-[calc(100svh-4rem)] overflow-hidden"
        data-test="trip-page"
    >
        <!-- Sized by its parent, not positioned: MapLibre makes its container
             position: relative, which would undo an inset-0 -->
        <div ref="host" class="h-full w-full" data-test="trip-map" />
        <BasemapSwitcher v-model="basemap" />

        <FloatingPanel v-model:folded="folded">
            <template #header>
                <TripHeader :plan="plan" :near="centre" :editable="editable" />
            </template>
            <TripPanel
                :plan="plan"
                :near="centre"
                :editable="editable"
                @focus="focus"
            />
        </FloatingPanel>

        <TripPlaceCard
            v-if="picked"
            :place="picked"
            :plan="plan"
            :editable="editable"
            @close="picked = null"
        />

        <Dialog v-model:open="conflictOpen">
            <DialogContent
                class="sm:max-w-md"
                data-test="trip-conflict"
                @escape-key-down.prevent
                @pointer-down-outside.prevent
            >
                <DialogHeader>
                    <DialogTitle>This trip changed elsewhere</DialogTitle>
                    <DialogDescription>
                        {{
                            plan.conflict.value?.edited_by
                                ? `${plan.conflict.value.edited_by} saved`
                                : 'Someone saved'
                        }}
                        changes since you opened it. Keep theirs, or save yours
                        over them?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        variant="outline"
                        data-test="take-theirs"
                        @click="plan.takeTheirs()"
                        >Load their version</Button
                    >
                    <Button data-test="keep-mine" @click="plan.keepMine()"
                        >Keep mine</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
