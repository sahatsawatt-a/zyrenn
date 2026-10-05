// Everything the maps pages ask the server: saved places and lists, trips,
// and -- through us, never straight from the browser -- place search, routes
// and Google's details. Each answers with JSON; a refusal comes back as an
// Error carrying the server's own words.
import { socketHeaders } from '@/lib/live';
import { owned } from '@/lib/projects';
import { xsrfToken } from '@/lib/utils';
import * as services from '@/routes/map-services';
import * as placeListRoutes from '@/routes/place-lists';
import * as placeRoutes from '@/routes/places';
import * as projectPlaceListRoutes from '@/routes/projects/place-lists';
import * as projectPlaceRoutes from '@/routes/projects/places';
import * as tripRoutes from '@/routes/trips';

// ---- shapes ----

/** A place as search, a click or a saved place gives it. */
export interface Candidate {
    name: string;
    address: string;
    kind: string;
    lat: number;
    lng: number;
    /** The saved place it is, when it is one. */
    savedId?: string;
}

/** What place search answers with. */
export interface Hit extends Candidate {
    label: string;
}

export type Weekday =
    | 'monday'
    | 'tuesday'
    | 'wednesday'
    | 'thursday'
    | 'friday'
    | 'saturday'
    | 'sunday';

/** Opening hours as Google words them, a day at a time: "9 AM–4:30 PM", "Closed". */
export type Hours = Partial<Record<Weekday, string>>;

/** Google's facts about a place, by way of SerpAPI and our server. */
export interface PlaceInfo {
    found: boolean;
    title?: string;
    type?: string | null;
    rating?: number | null;
    reviews?: number | null;
    price?: string | null;
    address?: string | null;
    phone?: string | null;
    website?: string | null;
    description?: string | null;
    thumbnail?: string | null;
    hours?: Hours | null;
    distanceMetres?: number;
}

export interface PlaceList {
    ref_id: string;
    name: string;
    color: string;
}

export interface SavedPlace {
    ref_id: string;
    list: string;
    name: string;
    address: string;
    kind: string;
    note: string;
    lat: number;
    lng: number;
    details: PlaceInfo | null;
    updated_at: string | null;
}

export type TravelMode = 'auto' | 'bicycle' | 'pedestrian';

export interface Route {
    km: number;
    seconds: number;
    /** One per pair of stops in a row. */
    legs: { km: number; seconds: number }[];
    /** The way to go, as [lng, lat] pairs. */
    line: [number, number][];
    steps: {
        instruction: string;
        km: number;
        seconds: number;
        at: [number, number];
    }[];
}

/** A save turned away because someone else saved first; it carries their copy. */
export class StaleSave extends Error {
    constructor(
        message: string,
        public readonly current: {
            title: string;
            content: unknown;
            revision: number;
            edited_by: string | null;
        },
    ) {
        super(message);
    }
}

// ---- talking to the server ----

const headers = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': xsrfToken(),
    // A trip's save isn't told back to the page that made it
    ...socketHeaders(),
});

async function send<T>(
    url: string,
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
    body?: unknown,
    signal?: AbortSignal,
    keepalive = false,
): Promise<T> {
    const init: RequestInit = {
        method,
        headers: headers(),
        credentials: 'same-origin',
        signal,
        // A save sent as the page closes still reaches the server
        keepalive,
    };

    // Only a request that changes something carries a body
    if (body !== undefined) {
        init.body = JSON.stringify(body);
    }

    const response = await fetch(url, init);
    const answer = await response.json().catch(() => null);

    if (response.status === 409 && answer?.trip) {
        throw new StaleSave(answer.message, answer.trip);
    }

    if (!response.ok) {
        throw new Error(
            answer?.message ?? `The server answered ${response.status}`,
        );
    }

    return answer as T;
}

const query = (params: Record<string, string | number>) =>
    `?${new URLSearchParams(Object.entries(params).map(([key, value]) => [key, String(value)]))}`;

// ---- saved places ----

export const createList = (name: string) =>
    send<{ list: PlaceList }>(
        owned(placeListRoutes.store, projectPlaceListRoutes.store).url(),
        'POST',
        { name },
    );

export const updateList = (
    list: string,
    changes: Partial<Pick<PlaceList, 'name' | 'color'>>,
) =>
    send<{ list: PlaceList }>(
        placeListRoutes.update.url(list),
        'PATCH',
        changes,
    );

export const deleteList = (list: string) =>
    send<{ moved_to: string | null }>(
        placeListRoutes.destroy.url(list),
        'DELETE',
    );

export const savePlace = (
    list: string,
    place: Candidate & { note?: string; details?: PlaceInfo | null },
) =>
    send<{ place: SavedPlace }>(
        owned(placeRoutes.store, projectPlaceRoutes.store).url(),
        'POST',
        { list, ...place, savedId: undefined },
    );

export const updatePlace = (
    place: string,
    changes: Partial<
        Pick<SavedPlace, 'name' | 'note' | 'list' | 'lat' | 'lng' | 'details'>
    >,
) =>
    send<{ place: SavedPlace }>(
        placeRoutes.update.url(place),
        'PATCH',
        changes,
    );

export const deletePlace = (place: string) =>
    send<{ deleted: true }>(placeRoutes.destroy.url(place), 'DELETE');

// ---- trips ----

/** Saves a trip; a StaleSave when someone else saved since `revision`. */
export const saveTrip = (
    trip: string,
    changes: { title?: string; content?: unknown; revision: number },
    keepalive = false,
) =>
    send<{ revision: number; updated_at: string }>(
        tripRoutes.update.url(trip),
        'PATCH',
        changes,
        undefined,
        keepalive,
    );

// ---- other services, through us ----

export const searchPlaces = (
    q: string,
    near: { lat: number; lng: number },
    signal?: AbortSignal,
) =>
    send<{ hits: Hit[] }>(
        services.search.url() + query({ q, lat: near.lat, lng: near.lng }),
        'GET',
        undefined,
        signal,
    ).then((answer) => answer.hits);

export const whatIsHere = (lat: number, lng: number) =>
    send<{ hit: Hit | null }>(
        services.reverse.url() + query({ lat, lng }),
        'GET',
    ).then((answer) => answer.hit);

export const findRoute = (
    stops: { lat: number; lng: number }[],
    costing: TravelMode,
    signal?: AbortSignal,
) =>
    send<Route>(
        services.route.url(),
        'POST',
        { stops: stops.map(({ lat, lng }) => ({ lat, lng })), costing },
        signal,
    );

/** A route asked for in a batch: the caller's own name for it, and the way. */
export interface RouteJob {
    key: string;
    stops: { lat: number; lng: number }[];
    costing: TravelMode;
}

/**
 * Many routes in one request -- a trip's days and its airport rides -- which
 * the server asks for side by side. Each comes back by its key: the route,
 * or why there isn't one.
 */
export const findRoutes = (jobs: RouteJob[], signal?: AbortSignal) =>
    send<{ routes: Record<string, Route | { error: string }> }>(
        services.routes.url(),
        'POST',
        {
            jobs: jobs.map((job) => ({
                key: job.key,
                costing: job.costing,
                stops: job.stops.map(({ lat, lng }) => ({ lat, lng })),
            })),
        },
        signal,
    ).then((answer) => answer.routes);

/** The quickest order to visit the stops, as their indexes; ends held. */
export const quickestOrder = (
    stops: { lat: number; lng: number }[],
    costing: TravelMode,
) =>
    send<{ order: number[] }>(services.quickest.url(), 'POST', {
        stops: stops.map(({ lat, lng }) => ({ lat, lng })),
        costing,
    }).then((answer) => answer.order);

/** Stops one request may carry; the server refuses more. */
export const MAX_ROUTE_STOPS = 25;
export const MAX_ORDERED_STOPS = 10;

// The same place asked again in one visit is answered from here
const looked = new Map<string, Promise<PlaceInfo>>();

/** Google's opening hours, rating and the like for a place. */
export function placeInfo(place: { name: string; lat: number; lng: number }) {
    const key = `${place.name}|${place.lat.toFixed(4)}|${place.lng.toFixed(4)}`;

    if (!looked.has(key)) {
        const asking = send<PlaceInfo>(
            services.placeDetails.url() +
                query({ name: place.name, lat: place.lat, lng: place.lng }),
            'GET',
        );
        // A failure is not kept: the next try asks again
        asking.catch(() => looked.delete(key));
        looked.set(key, asking);
    }

    return looked.get(key)!;
}

/** The URL of a map style the server serves, trimmed and with today's tiles. */
export const styleUrl = (name: 'liberty' | 'positron' | 'dark') =>
    services.style.url(name);
