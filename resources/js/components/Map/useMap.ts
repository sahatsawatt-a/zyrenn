import {
    AttributionControl,
    GeolocateControl,
    Map as MapLibreMap,
    NavigationControl,
    ScaleControl,
} from 'maplibre-gl';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import type { BasemapId } from './maplibre';
import { basemaps, startingBasemap, styleOf } from './maplibre';

// One MapLibre map in a container: the usual controls, the background map
// chosen (and switched), and the page's own sources and layers put back each
// time a style loads -- a new style takes everything added with it.

export interface MapOptions {
    center?: [number, number];
    zoom?: number;
    /** Keep the view in the address under this name, so a link opens the same place. */
    hash?: string;
    /** Add the page's sources and layers; called on every style load. */
    onStyle?: (map: MapLibreMap, dark: boolean) => void;
    /** Leave the buttons off: a small map inside something else. */
    bare?: boolean;
}

export function useMap(
    container: Ref<HTMLElement | undefined>,
    options: MapOptions = {},
) {
    const map = shallowRef<MapLibreMap | null>(null);
    const basemap = ref<BasemapId>(startingBasemap());
    const dark = () =>
        basemaps.find((each) => each.id === basemap.value)?.dark ?? false;

    onMounted(() => {
        const made = new MapLibreMap({
            container: container.value!,
            style: styleOf(basemap.value),
            center: options.center ?? [100.52, 13.75],
            zoom: options.zoom ?? 11,
            hash: options.hash ?? false,
            attributionControl: false,
        });
        made.addControl(
            new AttributionControl({ compact: true }),
            'bottom-right',
        );

        if (!options.bare) {
            made.addControl(new NavigationControl(), 'top-right');
            made.addControl(
                new GeolocateControl({
                    positionOptions: { enableHighAccuracy: true },
                }),
                'top-right',
            );
            made.addControl(new ScaleControl(), 'bottom-right');
        }

        // OpenFreeMap's styles name a few icons their sprite doesn't have; a
        // blank stands in, rather than a console warning for each
        made.setMissingStyleImageResolver((id) => {
            if (!made.hasImage(id)) {
                made.addImage(id, {
                    width: 1,
                    height: 1,
                    data: new Uint8Array(4),
                });
            }
        });

        made.on('style.load', () => options.onStyle?.(made, dark()));
        map.value = made;
    });

    watch(basemap, (id) => map.value?.setStyle(styleOf(id), { diff: false }));

    onBeforeUnmount(() => {
        map.value?.remove();
        map.value = null;
    });

    /** A pointing hand over these layers, so it shows they can be clicked. */
    const pointer = (layers: string[]) => {
        for (const layer of layers) {
            map.value?.on(
                'mouseenter',
                layer,
                () => (map.value!.getCanvas().style.cursor = 'pointer'),
            );
            map.value?.on(
                'mouseleave',
                layer,
                () => (map.value!.getCanvas().style.cursor = ''),
            );
        }
    };

    /** The middle of the map, for "near here". */
    const centre = () => {
        const at = map.value?.getCenter();

        return at ? { lat: at.lat, lng: at.lng } : { lat: 13.75, lng: 100.52 };
    };

    /** Brings points into view, leaving room for the panel on the left. */
    const frame = (points: { lat: number; lng: number }[], maxZoom = 15) => {
        if (!map.value || !points.length) {
            return;
        }

        const lngs = points.map((point) => point.lng);
        const lats = points.map((point) => point.lat);
        const wide = window.matchMedia('(min-width: 768px)').matches;
        map.value.fitBounds(
            [
                [Math.min(...lngs), Math.min(...lats)],
                [Math.max(...lngs), Math.max(...lats)],
            ],
            {
                padding: wide
                    ? { top: 60, bottom: 60, right: 60, left: 440 }
                    : { top: 60, bottom: 320, right: 40, left: 40 },
                maxZoom,
                duration: 800,
            },
        );
    };

    return { map, basemap, dark, pointer, centre, frame };
}
