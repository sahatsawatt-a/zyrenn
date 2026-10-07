import { Marker } from 'maplibre-gl';
import type { Map as MapLibreMap } from 'maplibre-gl';

// The pins the maps pages put down: the place being looked at, the ends of
// a route, hotels and airports.

const glyph = (label: string) =>
    label === 'plane'
        ? // A small plane, drawn rather than typed, so it looks the same everywhere
          '<path transform="translate(7.5 7.5) scale(0.62)" d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z" fill="#fff"/>'
        : label
          ? `<text x="15" y="19.5" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="12.5" font-weight="700" fill="#fff">${label}</text>`
          : '<circle cx="15" cy="15" r="5" fill="#fff"/>';

/** A teardrop pin in a colour, with a letter, a plane, or a dot in it. */
export function pin(color: string, label = ''): Marker {
    const element = document.createElement('div');
    element.className = 'drop-shadow-md';
    element.innerHTML = `<svg width="30" height="40" viewBox="0 0 30 40"><path d="M15 0C6.7 0 0 6.7 0 15c0 11.3 15 25 15 25s15-13.7 15-25C30 6.7 23.3 0 15 0z" fill="${color}" stroke="#fff" stroke-width="2"/>${glyph(label)}</svg>`;

    return new Marker({ element, anchor: 'bottom' });
}

/** Puts a pin at a place on the map, or takes it off when there is none. */
export function placePin(
    marker: Marker,
    map: MapLibreMap | null,
    at: { lat: number; lng: number } | null,
) {
    if (at && map) {
        marker.setLngLat([at.lng, at.lat]).addTo(map);
    } else {
        marker.remove();
    }
}
