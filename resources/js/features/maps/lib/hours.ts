// Opening hours as Google words them -- "9 AM–4:30 PM", "11 AM–2 PM, 5–10 PM",
// "Closed", "Open 24 hours" -- read into minutes, and what's wrong with being
// somewhere at a given time.

import type { Hours, Weekday } from '@/lib/maps';

export type { Hours, Weekday };

export const WEEKDAYS: Weekday[] = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
];

/** "9 AM" as minutes after midnight; a time with no AM/PM borrows `meridiem`. */
const minutesOf = (text: string, meridiem?: string) => {
    const match = text.trim().match(/^(\d{1,2})(?::(\d{2}))?\s*([AP]M)?$/i);

    if (!match) {
        return null;
    }

    const half = (match[3] ?? meridiem)?.toUpperCase();
    const hours = (Number(match[1]) % 12) + (half === 'PM' ? 12 : 0);

    return hours * 60 + Number(match[2] ?? 0);
};

export type DayHours =
    | { kind: 'closed' }
    | { kind: 'always' }
    | { kind: 'open'; ranges: [number, number][] };

/**
 * One day's hours read into minutes: "11 AM–2:30 PM, 5–10 PM" is two ranges,
 * the "5" taking its PM from the time after it, and a range that ends past
 * midnight runs on into the next day. Null when the words aren't understood.
 */
export function readHours(text: string | undefined): DayHours | null {
    if (!text) {
        return null;
    }

    if (/closed/i.test(text)) {
        return { kind: 'closed' };
    }

    if (/24 hours/i.test(text)) {
        return { kind: 'always' };
    }

    const ranges: [number, number][] = [];

    for (const part of text.split(/,\s*/)) {
        const [from, to] = part.split(/\s*[–-]\s*/);
        const toHalf = to?.match(/[AP]M/i)?.[0];
        const start = from === undefined ? null : minutesOf(from, toHalf);
        const end = to === undefined ? null : minutesOf(to);

        if (start === null || end === null) {
            return null;
        }

        ranges.push([start, end <= start ? end + 1440 : end]);
    }

    return { kind: 'open', ranges };
}

/** Minutes after midnight as "16:30". */
export const timeOf = (minutes: number) =>
    `${String(Math.floor((minutes % 1440) / 60)).padStart(2, '0')}:${String(Math.round(minutes % 60)).padStart(2, '0')}`;

/**
 * What is wrong with being at a place from `arrive` to `leave` (minutes after
 * that day's midnight), given its hours on that weekday -- or null if nothing,
 * or nothing known.
 */
export function visitProblem(
    hours: Hours | null | undefined,
    weekday: Weekday,
    arrive: number,
    leave: number,
): string | null {
    const day = readHours(hours?.[weekday]);

    if (!day || day.kind === 'always') {
        return null;
    }

    const name = weekday[0].toUpperCase() + weekday.slice(1);

    if (day.kind === 'closed') {
        return `closed on ${name}s`;
    }

    const during = day.ranges.find(
        ([open, close]) => arrive >= open && arrive < close,
    );

    if (!during) {
        const later = day.ranges.find(([open]) => open > arrive);

        return later
            ? `opens at ${timeOf(later[0])} -- you arrive at ${timeOf(arrive)}`
            : `closed by ${timeOf(arrive)} (it closes at ${timeOf(day.ranges.at(-1)![1])})`;
    }

    return leave > during[1]
        ? `closes at ${timeOf(during[1])} -- you planned to stay until ${timeOf(leave)}`
        : null;
}
