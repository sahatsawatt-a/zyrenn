import type { Candidate, Hours, TravelMode } from '@/lib/maps';
import type { LegMode } from './format';

// A trip as it is kept: the flights in and out, the hotels booked, and days
// of stops and rests in order -- the shape the server keeps (TripDocument) --
// with the dates and times worked out from it and the rows a day is laid out
// as. Nothing here holds state; useTripPlan is what edits a trip.

export interface TripStop extends Candidate {
    id: string;
    /** How long to spend there. */
    minutes: number;
    cost: number;
    note: string;
    /** Google's opening hours: absent until looked up, null if none were found. */
    hours?: Hours | null;
    /** A rest: "here" pauses where you are (no place of its own); "hotel" goes back for it. */
    rest?: 'here' | 'hotel';
    /** The saved place it is, if it is one: renamed or moved on the map, it follows. */
    placeRef?: string;
}

/** Landing: where, when (YYYY-MM-DDTHH:MM), then how long to get out and to rest. */
export interface Arrival {
    airport: TripStop | null;
    at: string;
    clearMinutes: number;
    restMinutes: number;
}

/** Flying out: where, when the flight leaves, and how early to be there. */
export interface Departure {
    airport: TripStop | null;
    at: string;
    earlyMinutes: number;
}

/** A hotel booking: where, and from when until when, as YYYY-MM-DDTHH:MM. */
export interface Stay {
    id: string;
    place: TripStop;
    checkIn: string;
    checkOut: string;
    /** What the whole stay costs. */
    cost: number;
}

/**
 * A leg as you'll really make it -- say Metro Line 2, 25 minutes, ¥5 --
 * where no router knows. It stands in for the routed time, and its cost
 * counts toward the day's.
 */
export interface LegOverride {
    mode: LegMode;
    minutes: number;
    cost: number;
    note: string;
}

export interface TripDay {
    id: string;
    mode: TravelMode;
    /** When the day begins, as HH:MM. */
    start: string;
    stops: TripStop[];
    /** Legs entered by hand, by legKey(from, to). */
    legs: Record<string, LegOverride>;
}

export interface TripContent {
    /** The first day's date, as YYYY-MM-DD. */
    startDate: string;
    currency: string;
    arrival: Arrival;
    departure: Departure;
    stays: Stay[];
    /** Begin each day at the hotel slept in, and end it at the night's. */
    fromHotel: boolean;
    days: TripDay[];
}

export const DAY_COLORS = [
    '#e11d48',
    '#2563eb',
    '#16a34a',
    '#d97706',
    '#7c3aed',
    '#0891b2',
    '#db2777',
];

export const id = () => Math.random().toString(36).slice(2, 10);

/**
 * A deep copy of plain data. Not structuredClone: page props and refs come
 * wrapped in Vue's reactive proxies, which it refuses to copy.
 */
export const copy = <T>(data: T): T => JSON.parse(JSON.stringify(data));

export const asStop = (place: Candidate): TripStop => ({
    name: place.name,
    address: place.address,
    kind: place.kind,
    lat: place.lat,
    lng: place.lng,
    id: id(),
    minutes: 0,
    cost: 0,
    note: '',
    // Chosen from the saved places: kept in step with it
    ...(place.savedId ? { placeRef: place.savedId } : {}),
});

/**
 * Which leg: the two places it joins, by their ids -- so a leg typed in keeps
 * to those two places while they stay next to each other, and drops out of
 * the day's sums when they stop being neighbours.
 */
export const legKey = (from: Candidate, to: Candidate) =>
    `${(from as TripStop).id}>${(to as TripStop).id}`;

// ---- dates and times ----

/** A YYYY-MM-DD date moved on by some days. */
export const addDays = (date: string, days: number) => {
    const moved = new Date(`${date}T12:00:00Z`);
    moved.setUTCDate(moved.getUTCDate() + days);

    return moved.toISOString().slice(0, 10);
};

/** Whole days from one YYYY-MM-DD date to another. */
export const daysBetween = (from: string, to: string) =>
    Math.round(
        (Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) /
            86400000,
    );

/** "09:30" plus some seconds, as "HH:MM" -- past midnight reads as e.g. "00:40 +1". */
export const clock = (start: string, seconds: number) => {
    const [hours, minutes] = start.split(':').map(Number);
    const total = Math.round(hours * 60 + minutes + seconds / 60);
    const day = Math.floor(total / 1440);
    const time = `${String(Math.floor((total % 1440) / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;

    return day ? `${time} +${day}` : time;
};

/** "HH:MM" as minutes after midnight. */
export const minutesOfDay = (time: string) => {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + (minutes || 0);
};

/** "2026-11-16T12:00" as "12:00". */
export const timeIn = (when: string) => when.slice(11, 16);

/** "Fri 13 Nov" for a YYYY-MM-DD date. */
export const shortDate = (date: string) =>
    new Date(`${date.slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

/** Links that open the way between two places by public transport elsewhere. */
export const transitLinks = (from: Candidate, to: Candidate) => ({
    // Amap is what works in mainland China. It is told the points are WGS84 --
    // OpenStreetMap's own -- so it can shift them onto its offset map
    amap: `https://uri.amap.com/navigation?${new URLSearchParams({
        from: `${from.lng},${from.lat},${from.name}`,
        to: `${to.lng},${to.lat},${to.name}`,
        mode: 'bus',
        coordinate: 'wgs84',
        callnative: '0',
    })}`,
    google: `https://www.google.com/maps/dir/?${new URLSearchParams({
        api: '1',
        origin: `${from.lat},${from.lng}`,
        destination: `${to.lat},${to.lng}`,
        travelmode: 'transit',
    })}`,
});

// ---- a day, laid out ----

/** One thing in a day, in the order it happens. */
export type Entry =
    | { kind: 'land'; place: TripStop; arrival: Arrival }
    | { kind: 'wake'; place: TripStop; stay: Stay }
    /** Landing day: to the hotel first, to check in or leave the bags, then rest. */
    | { kind: 'drop'; place: TripStop; stay: Stay }
    | { kind: 'stop'; place: TripStop; index: number }
    /** A rest where you are: time passes, nobody moves. */
    | { kind: 'pause'; stop: TripStop; index: number }
    | { kind: 'night'; place: TripStop; stay: Stay }
    | { kind: 'fly'; place: TripStop; departure: Departure };

/** A line of a day's timeline. */
export type Row =
    | {
          /** An airport or a hotel the day passes through. */
          kind: 'point';
          icon: 'hotel' | 'plane';
          place: TripStop;
          label: string;
          time: string;
          note: string;
          problem: string | null;
      }
    | {
          kind: 'leg';
          from: Candidate;
          to: Candidate;
          leg?: { km: number; seconds: number };
          /** A ride to or from the airport, always by car. */
          transfer: boolean;
          key: string;
          /** As typed in, standing in for the routed time. */
          manual?: LegOverride;
      }
    | { kind: 'arrival-rest'; time: string; until: string }
    | {
          kind: 'stop';
          stop: TripStop;
          index: number;
          /** Its place among the day's sights, as the map numbers them; 0 for a rest. */
          number: number;
          arrive: string;
          leave: string;
          /** Its hours that weekday, as Google (or you) word them. */
          open?: string;
          /** Why being there then won't work, if it won't. */
          problem: string | null;
      };

export type StopRow = Extract<Row, { kind: 'stop' }>;
export type LegRow = Extract<Row, { kind: 'leg' }>;
