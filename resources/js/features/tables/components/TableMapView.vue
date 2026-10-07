<script setup lang="ts">
import { MapPinOff, Rows3, X } from '@lucide/vue';
import type {
    GeoJSONSource,
    Map as MapLibreMap,
    MapLayerMouseEvent,
} from 'maplibre-gl';
import { computed, ref, watch } from 'vue';
import BasemapSwitcher from '@/components/map/BasemapSwitcher.vue';
import { useMap } from '@/components/map/useMap';
import { Button } from '@/components/ui/button';
import { cellText } from '@/features/tables/composables/cellText';
import { toneOf } from '@/features/tables/composables/tones';
import type { Tone } from '@/features/tables/composables/tones';
import type { ColumnMeta, LocationValue, RowData } from '@/types';

// A table's rows on a map: each row with a place is a dot, coloured by one of
// its choice columns, named by its first text column. The rows are the
// grid's own -- searched and filtered the same -- so the map is just another
// way to look at them.

const props = defineProps<{ columns: ColumnMeta[]; rows: RowData[] }>();
const emit = defineEmits<{ showRow: [id: number] }>();

// The hex of each tone a choice comes in, for drawing on the map
const HEX: Record<Tone, string> = {
    amber: '#f59e0b',
    sky: '#0ea5e9',
    purple: '#a855f7',
    emerald: '#10b981',
    fuchsia: '#d946ef',
    indigo: '#6366f1',
    rose: '#f43f5e',
    cyan: '#06b6d4',
    slate: '#64748b',
};

const places = computed(() =>
    props.columns.filter((column) => column.type === 'location'),
);
const choices = computed(() =>
    props.columns.filter((column) => column.type === 'select'),
);
const title = computed(() =>
    props.columns.find(
        (column) =>
            !column.isPrimary &&
            (column.type === 'varchar' || column.type === 'text'),
    ),
);

const placeColumn = ref(places.value[0]?.name ?? '');
const colourColumn = ref(choices.value[0]?.name ?? '');

watch(places, (now) => {
    if (!now.some((column) => column.name === placeColumn.value)) {
        placeColumn.value = now[0]?.name ?? '';
    }
});

const colourBy = computed(() =>
    choices.value.find((column) => column.name === colourColumn.value),
);

/** A row's dot colour: its choice's tone in the colouring column. */
const colourOf = (row: RowData) => {
    const option = colourBy.value?.options?.find(
        (each) => each.value === row[colourColumn.value],
    );

    return HEX[option ? toneOf(option.color) : 'slate'];
};

const placed = computed(() =>
    props.rows.filter((row) => {
        const place = row[placeColumn.value] as LocationValue | null;

        return (
            place && Number.isFinite(place.lat) && Number.isFinite(place.lng)
        );
    }),
);

const data = computed<GeoJSON.FeatureCollection>(() => ({
    type: 'FeatureCollection',
    features: placed.value.map((row) => {
        const place = row[placeColumn.value] as LocationValue;

        return {
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [place.lng, place.lat] },
            properties: {
                id: row.id,
                name: title.value
                    ? cellText(row[title.value.name])
                    : place.label,
                color: colourOf(row),
            },
        };
    }),
}));

/** How many rows on the map have each choice, for the legend. */
const legend = computed(() =>
    (colourBy.value?.options ?? []).map((option) => ({
        value: option.value,
        color: HEX[toneOf(option.color)],
        count: placed.value.filter(
            (row) => row[colourColumn.value] === option.value,
        ).length,
    })),
);

const addLayers = (made: MapLibreMap, dark: boolean) => {
    made.addSource('rows', { type: 'geojson', data: data.value });
    made.addLayer({
        id: 'row-points',
        type: 'circle',
        source: 'rows',
        paint: {
            'circle-color': ['get', 'color'],
            'circle-radius': ['interpolate', ['linear'], ['zoom'], 4, 5, 15, 9],
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 2,
        },
    });
    made.addLayer({
        id: 'row-labels',
        type: 'symbol',
        source: 'rows',
        minzoom: 11,
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
const { map, basemap, pointer, frame } = useMap(host, {
    onStyle: addLayers,
    zoom: 4,
});

watch(
    data,
    (now) => void map.value?.getSource<GeoJSONSource>('rows')?.setData(now),
);

/** Brings every placed row into view. */
const frameAll = () =>
    frame(
        placed.value.map((row) => row[placeColumn.value] as LocationValue),
        14,
    );

watch(placeColumn, frameAll);

// ---- a row picked on the map ----

const picked = ref<RowData | null>(null);

watch(map, (made) => {
    if (!made) {
        return;
    }

    // At once, not on load: tiles can take a while, and by then the map may
    // have been moved by hand
    frameAll();
    pointer(['row-points']);
    made.on('click', 'row-points', (event: MapLayerMouseEvent) => {
        const id = Number(event.features?.[0]?.properties.id);
        picked.value = props.rows.find((row) => row.id === id) ?? null;
    });

    // For the e2e suite to read the map with
    Object.assign(window, { __tableMap: { map: () => map.value } });
});

/** What the card for a picked row shows: its filled-in fields. */
const fieldsOf = (row: RowData) =>
    props.columns
        .filter(
            (column) =>
                !column.isPrimary &&
                !column.hidden &&
                column.name !== title.value?.name,
        )
        .map((column) => ({
            label: column.label,
            text: cellText(row[column.name]),
        }))
        .filter((field) => field.text !== '')
        .slice(0, 6);

const select =
    'h-8 rounded-md border bg-background px-2 text-xs dark:bg-input/30 dark:[color-scheme:dark]';
</script>

<template>
    <div
        class="relative min-h-0 flex-1 overflow-hidden rounded-lg border"
        data-test="table-map"
    >
        <!-- Sized by its parent: MapLibre makes its container position: relative -->
        <div ref="host" class="h-full w-full" />
        <BasemapSwitcher v-model="basemap" />

        <div
            class="bg-background/95 absolute top-3 left-3 z-10 flex flex-wrap items-center gap-2 rounded-lg border p-2 text-xs shadow-md backdrop-blur"
        >
            <label v-if="places.length > 1" class="flex items-center gap-1.5">
                Place
                <select
                    v-model="placeColumn"
                    :class="select"
                    data-test="map-place-column"
                >
                    <option
                        v-for="column in places"
                        :key="column.name"
                        :value="column.name"
                    >
                        {{ column.label }}
                    </option>
                </select>
            </label>
            <label v-if="choices.length" class="flex items-center gap-1.5">
                Colour by
                <select
                    v-model="colourColumn"
                    :class="select"
                    data-test="map-colour-column"
                >
                    <option value="">Nothing</option>
                    <option
                        v-for="column in choices"
                        :key="column.name"
                        :value="column.name"
                    >
                        {{ column.label }}
                    </option>
                </select>
            </label>
            <span class="text-muted-foreground" data-test="map-count">
                {{ placed.length }} of {{ rows.length }}
                {{ rows.length === 1 ? 'row' : 'rows' }} placed
            </span>
        </div>

        <ul
            v-if="legend.length"
            class="bg-background/95 absolute bottom-8 left-3 z-10 space-y-1 rounded-lg border px-3 py-2 text-xs shadow-md backdrop-blur"
            data-test="map-legend"
        >
            <li
                v-for="item in legend"
                :key="item.value"
                class="flex items-center gap-2"
            >
                <span
                    class="size-2.5 rounded-full"
                    :style="{ background: item.color }"
                />
                {{ item.value }}
                <span class="text-muted-foreground ml-auto pl-3">{{
                    item.count
                }}</span>
            </li>
        </ul>

        <div
            v-if="!placed.length"
            class="bg-background/95 absolute inset-x-0 top-1/3 z-10 mx-auto w-fit max-w-[90%] rounded-lg border px-4 py-3 text-center text-sm shadow-md"
        >
            <MapPinOff class="text-muted-foreground mx-auto mb-1 size-5" />
            No row has a place yet -- add one in the grid's Location column.
        </div>

        <div
            v-if="picked"
            class="bg-background/95 absolute right-3 bottom-8 z-10 w-72 space-y-2 rounded-lg border p-3 text-sm shadow-xl backdrop-blur"
            data-test="map-row-card"
        >
            <div class="flex items-start gap-2">
                <span class="min-w-0 flex-1 truncate font-semibold">
                    {{
                        title
                            ? cellText(picked[title.name]) || 'Untitled row'
                            : `Row ${picked.id}`
                    }}
                </span>
                <button
                    type="button"
                    class="text-muted-foreground hover:bg-accent rounded p-0.5"
                    aria-label="Close"
                    @click="picked = null"
                >
                    <X class="size-4" />
                </button>
            </div>
            <dl class="space-y-1 text-xs">
                <div
                    v-for="field in fieldsOf(picked)"
                    :key="field.label"
                    class="flex gap-2"
                >
                    <dt class="text-muted-foreground w-20 shrink-0 truncate">
                        {{ field.label }}
                    </dt>
                    <dd class="min-w-0 truncate">{{ field.text }}</dd>
                </div>
            </dl>
            <Button
                size="sm"
                variant="outline"
                class="w-full"
                @click="emit('showRow', picked.id)"
            >
                <Rows3 class="size-4" /> Show in the grid
            </Button>
        </div>
    </div>
</template>
