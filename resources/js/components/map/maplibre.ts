import { setWorkerUrl } from 'maplibre-gl';
import type { StyleSpecification } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
// The worker sits beside MapLibre's own module and is found from it -- which
// Vite's dependency pre-bundling moves, so it is handed over explicitly.
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import { styleUrl } from '@/lib/maps';

// Everything a page needs before it makes a MapLibre map: the worker, and the
// background maps to choose from. Every map in the app is set up from here.

// In dev Vite answers with its own address (http://0.0.0.0:5173/...), which a
// page on https can't load -- and without its worker MapLibre draws no tile at
// all. nginx serves the same path on the page's own origin, so use that.
const devPath = (url: string) => {
    const parsed = new URL(url, location.href);

    return parsed.pathname + parsed.search;
};
setWorkerUrl(import.meta.env.DEV ? devPath(workerUrl) : workerUrl);

export type BasemapId = 'map' | 'light' | 'dark' | 'satellite';

export interface Basemap {
    id: BasemapId;
    label: string;
    /** Labels on it want light text with a dark halo. */
    dark: boolean;
}

export const basemaps: Basemap[] = [
    { id: 'map', label: 'Map', dark: false },
    { id: 'light', label: 'Light', dark: false },
    { id: 'dark', label: 'Dark', dark: true },
    { id: 'satellite', label: 'Satellite', dark: true },
];

/** Fonts for labels drawn on a raster map, which brings none of its own. */
export const GLYPHS =
    'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf';

/**
 * The style for a background map: OpenFreeMap's, trimmed and served by our
 * server with today's tiles (App\Support\Maps\MapStyle), or Esri's imagery.
 */
export const styleOf = (id: BasemapId): string | StyleSpecification => {
    switch (id) {
        case 'light':
            return styleUrl('positron');
        case 'dark':
            return styleUrl('dark');
        case 'satellite':
            return {
                version: 8,
                glyphs: GLYPHS,
                sources: {
                    base: {
                        type: 'raster',
                        tiles: [
                            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                        ],
                        tileSize: 256,
                        maxzoom: 19,
                        attribution:
                            'Imagery © Esri, Maxar, Earthstar Geographics',
                    },
                },
                layers: [{ id: 'base', type: 'raster', source: 'base' }],
            };
        default:
            return styleUrl('liberty');
    }
};
