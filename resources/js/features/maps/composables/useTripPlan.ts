import { markRaw, onBeforeUnmount, reactive, ref, watch } from 'vue';
import type {
    Candidate,
    Hours,
    Route,
    RouteJob,
    TravelMode,
    Weekday,
} from '@/lib/maps';
import { findRoutes, MAX_ORDERED_STOPS, quickestOrder } from '@/lib/maps';
import { timeOf, visitProblem, WEEKDAYS } from '@/features/maps/lib/hours';
import { useTripSave } from './useTripSave';
import type {
    TripStop,
    Stay,
    LegOverride,
    TripDay,
    TripContent,
    Entry,
    Row,
} from '@/features/maps/lib/trip';
import {
    DAY_COLORS,
    id,
    copy,
    asStop,
    legKey,
    addDays,
    daysBetween,
    clock,
    minutesOfDay,
    timeIn,
    shortDate,
} from '@/features/maps/lib/trip';

// A trip as its page edits it (its shape is in lib/trip). A day starts from the hotel slept in the
// night before -- or the airport, on the day you land -- and ends at the one
// booked for that night, or the airport you fly out from. Each day's route is
// worked out as it changes, and the trip saves itself a moment after each
// change. The server keeps the document in this shape (TripDocument).

export function useTripPlan(
    initial: {
        ref_id: string;
        title: string;
        content: TripContent;
        revision: number;
    },
    editable: boolean,
) {
    const title = ref(initial.title);
    const trip = reactive<TripContent>(copy(initial.content));
    const selectedDayId = ref<string | null>(trip.days[0]?.id ?? null);

    // ---- dates ----

    const dateKey = (index: number) => addDays(trip.startDate, index);
    const dayOf = (dayId: string) => trip.days.find((day) => day.id === dayId);
    const indexOf = (day: TripDay) => trip.days.indexOf(day);
    const colorOf = (dayId: string) =>
        DAY_COLORS[
            Math.max(
                0,
                trip.days.findIndex((day) => day.id === dayId),
            ) % DAY_COLORS.length
        ];

    /** The weekday of the n-th day, as opening hours name it. */
    const weekdayOf = (index: number): Weekday | null => {
        const date = new Date(`${dateKey(index)}T00:00:00`);

        // getDay() counts from Sunday; WEEKDAYS from Monday
        return Number.isNaN(date.getTime())
            ? null
            : WEEKDAYS[(date.getDay() + 6) % 7];
    };

    /** "Fri 13 Nov" for the n-th day. */
    const dateOf = (index: number) => shortDate(dateKey(index));

    /** "Fri 13" for the n-th day, short enough for its tab. */
    const tabDateOf = (index: number) =>
        new Date(`${dateKey(index)}T00:00:00`).toLocaleDateString(undefined, {
            weekday: 'short',
            day: 'numeric',
        });

    // Moving the trip moves its bookings and flights with it, so they still fit the days
    watch(
        () => trip.startDate,
        (now, before) => {
            const shift = now && before ? daysBetween(before, now) : 0;

            if (!shift || Number.isNaN(shift)) {
                return;
            }

            const move = (when: string) =>
                when
                    ? `${addDays(when.slice(0, 10), shift)}${when.slice(10)}`
                    : when;

            for (const stay of trip.stays) {
                stay.checkIn = move(stay.checkIn);
                stay.checkOut = move(stay.checkOut);
            }

            trip.arrival.at = move(trip.arrival.at);
            trip.departure.at = move(trip.departure.at);
        },
    );

    // ---- which hotel and flight each day starts and ends at ----

    /**
     * The hotels a day begins and ends at -- woken up in the one booked for
     * the night before, sleeping in the one booked for that night -- and the
     * flights that land or leave that day. Any of them may be missing.
     */
    const endsOf = (day: TripDay) => {
        const date = dateKey(indexOf(day));
        const { arrival, departure } = trip;

        return {
            date,
            wake: trip.fromHotel
                ? trip.stays.find(
                      (stay) =>
                          stay.checkIn.slice(0, 10) < date &&
                          date <= stay.checkOut.slice(0, 10),
                  )
                : undefined,
            sleep: trip.fromHotel
                ? trip.stays.find(
                      (stay) =>
                          stay.checkIn.slice(0, 10) <= date &&
                          date < stay.checkOut.slice(0, 10),
                  )
                : undefined,
            lands:
                arrival.airport && arrival.at.slice(0, 10) === date
                    ? arrival
                    : undefined,
            leaves:
                departure.airport && departure.at.slice(0, 10) === date
                    ? departure
                    : undefined,
        };
    };

    /**
     * A day, in order: in from the airport or out of the morning's hotel; on
     * a landing day, to the hotel to check in and rest; the stops and rests;
     * and to the night's hotel or the departing flight. A day with nothing in
     * it that ends where it began is empty.
     */
    const entriesOf = (day: TripDay): Entry[] => {
        const { wake, sleep, lands, leaves } = endsOf(day);
        const entries: Entry[] = [];

        if (
            !day.stops.length &&
            !lands &&
            !leaves &&
            (!sleep || sleep.id === wake?.id)
        ) {
            return entries;
        }

        if (lands) {
            entries.push({
                kind: 'land',
                place: lands.airport!,
                arrival: lands,
            });

            if (sleep && !leaves) {
                entries.push({ kind: 'drop', place: sleep.place, stay: sleep });
            }
        } else if (wake) {
            entries.push({ kind: 'wake', place: wake.place, stay: wake });
        }

        day.stops.forEach((stop, index) =>
            entries.push(
                stop.rest === 'here'
                    ? { kind: 'pause', stop, index }
                    : { kind: 'stop', place: stop, index },
            ),
        );

        const last = entries.findLast((entry) => 'place' in entry);

        if (leaves) {
            entries.push({
                kind: 'fly',
                place: leaves.airport!,
                departure: leaves,
            });
        } else if (
            sleep &&
            !(last?.kind === 'drop' && last.stay.id === sleep.id)
        ) {
            entries.push({ kind: 'night', place: sleep.place, stay: sleep });
        }

        return entries;
    };

    const placeOf = (entry: Entry) => ('place' in entry ? entry.place : null);

    /** The day's own route: everything with a place but the airports, whose rides are transfers. */
    const pathOf = (day: TripDay): Candidate[] =>
        entriesOf(day)
            .filter((entry) => entry.kind !== 'land' && entry.kind !== 'fly')
            .map(placeOf)
            .filter((place): place is TripStop => place !== null);

    /** The rides from the landing airport and to the departing one, if the day has them. */
    const transfersOf = (day: TripDay) => {
        const located = entriesOf(day).filter((entry) => 'place' in entry);
        const first = located[0];
        const last = located.at(-1);

        return {
            in:
                first?.kind === 'land' && located[1]
                    ? [first.place, placeOf(located[1])!]
                    : null,
            out:
                last?.kind === 'fly' && located.at(-2)
                    ? [placeOf(located.at(-2)!)!, last.place]
                    : null,
        };
    };

    // ---- each day's route, through our server ----

    const routes = reactive<
        Record<string, { loading: boolean; error: string; route: Route | null }>
    >({});
    const cache = new Map<string, Route>();

    const keyOf = (path: Candidate[], mode: TravelMode) =>
        JSON.stringify([mode, path.map((each) => [each.lat, each.lng])]);

    let routeTimer: ReturnType<typeof setTimeout> | undefined;
    let asking: AbortController | null = null;

    /**
     * Every route the trip needs -- each day's, and its rides to and from the
     * airport -- the ones not already known asked for in a single request,
     * which the server answers side by side.
     */
    const plan = () => {
        const jobs: (RouteJob & { slot: string; cacheKey: string })[] = [];

        const want = (slot: string, path: Candidate[], mode: TravelMode) => {
            const state = (routes[slot] ??= {
                loading: false,
                error: '',
                route: null,
            });
            const cacheKey = keyOf(path, mode);

            if (path.length < 2) {
                Object.assign(state, {
                    loading: false,
                    error: '',
                    route: null,
                });
            } else if (cache.has(cacheKey)) {
                Object.assign(state, {
                    loading: false,
                    error: '',
                    route: cache.get(cacheKey),
                });
            } else {
                state.loading = true;
                jobs.push({
                    key: slot,
                    slot,
                    cacheKey,
                    stops: path,
                    costing: mode,
                });
            }
        };

        for (const day of trip.days) {
            const transfers = transfersOf(day);
            want(day.id, pathOf(day), day.mode);
            // To and from the airport is a ride whatever pace the day keeps
            want(`${day.id}:in`, transfers.in ?? [], 'auto');
            want(`${day.id}:out`, transfers.out ?? [], 'auto');
        }

        if (!jobs.length) {
            return;
        }

        // A newer edit's batch supersedes one still on its way
        asking?.abort();
        asking = new AbortController();

        findRoutes(jobs, asking.signal)
            .then((answers) => {
                for (const job of jobs) {
                    const answer = answers[job.slot];
                    const state = routes[job.slot];

                    if (!answer || 'error' in answer) {
                        Object.assign(state, {
                            loading: false,
                            error: answer?.error ?? 'No route.',
                            route: null,
                        });
                    } else {
                        // Thousands of points, read but never changed: kept out of Vue's reach
                        cache.set(job.cacheKey, markRaw(answer));
                        Object.assign(state, {
                            loading: false,
                            error: '',
                            route: cache.get(job.cacheKey),
                        });
                    }
                }
            })
            .catch((thrown: Error) => {
                if (thrown.name === 'AbortError') {
                    return;
                }

                for (const job of jobs) {
                    Object.assign(routes[job.slot], {
                        loading: false,
                        error: thrown.message,
                        route: null,
                    });
                }
            });
    };

    // Edits come in bursts -- typing a time, dragging a stop -- so the router
    // is asked once they settle
    watch(
        () =>
            trip.days.map((day) => [
                day.mode,
                pathOf(day).map((each) => [each.lat, each.lng]),
                Object.values(transfersOf(day)).map((pair) =>
                    pair?.map((each) => [each.lat, each.lng]),
                ),
            ]),
        () => {
            clearTimeout(routeTimer);
            routeTimer = setTimeout(plan, 400);
        },
        { deep: true, immediate: true },
    );

    // ---- a day in time ----

    const hotelRow = (
        kind: 'drop' | 'night',
        stay: Stay,
        date: string,
        start: string,
        seconds: number,
    ): Row => {
        const checkingIn = stay.checkIn.slice(0, 10) === date;
        const from = timeIn(stay.checkIn);
        const arrive = clock(start, seconds);
        const early =
            checkingIn &&
            minutesOfDay(start) + seconds / 60 < minutesOfDay(from);

        return {
            kind: 'point',
            icon: 'hotel',
            place: stay.place,
            label:
                checkingIn || kind === 'drop'
                    ? `Check in at ${stay.place.name}`
                    : `Back at ${stay.place.name}`,
            time: arrive,
            note: checkingIn ? `check-in from ${from}` : '',
            problem: early
                ? `Check-in at ${stay.place.name} is from ${from} -- you get there at ${arrive}; leave the bags?`
                : null,
        };
    };

    /**
     * A day laid out in time: every leg between two places, the clock at
     * each, and the check-ins, check-outs and flights that fall on it.
     */
    const timelineOf = (dayId: string) => {
        const target = dayOf(dayId)!;
        const weekday = weekdayOf(indexOf(target));
        const { date } = endsOf(target);
        const entries = entriesOf(target);
        const legs = routes[dayId]?.route?.legs ?? [];
        const inbound = routes[`${dayId}:in`]?.route?.legs[0];
        const outbound = routes[`${dayId}:out`]?.route?.legs[0];

        // A landing day begins once you are out of the airport
        const landing = entries[0]?.kind === 'land' ? entries[0].arrival : null;
        const start = landing
            ? timeOf(minutesOfDay(timeIn(landing.at)) + landing.clearMinutes)
            : target.start;
        const at = (seconds: number) => minutesOfDay(start) + seconds / 60;

        const rows: Row[] = [];
        let seconds = 0;
        let travel = 0;
        let transfers = 0;
        let fares = 0;
        let legIndex = 0;
        let sights = 0;
        let flying = false;
        let previous: { kind: string; place: Candidate } | null = null;

        const travelTo = (kind: string, place: Candidate) => {
            if (previous) {
                const transfer = previous.kind === 'land' || kind === 'fly';
                const leg =
                    previous.kind === 'land'
                        ? inbound
                        : kind === 'fly'
                          ? outbound
                          : legs[legIndex++];
                const key = legKey(previous.place, place);
                const manual = target.legs?.[key];
                rows.push({
                    kind: 'leg',
                    from: previous.place,
                    to: place,
                    leg,
                    transfer,
                    key,
                    manual,
                });

                // A leg typed in takes its own time, and isn't the day's walking
                if (manual) {
                    seconds += (Number(manual.minutes) || 0) * 60;
                    fares += Number(manual.cost) || 0;
                } else {
                    seconds += leg?.seconds ?? 0;

                    if (transfer) {
                        transfers += leg?.seconds ?? 0;
                    } else {
                        travel += leg?.seconds ?? 0;
                    }
                }
            }

            previous = { kind, place };
        };

        for (const entry of entries) {
            if (entry.kind === 'land') {
                rows.push({
                    kind: 'point',
                    icon: 'plane',
                    place: entry.place,
                    label: `Land at ${entry.place.name}`,
                    time: timeIn(entry.arrival.at),
                    note: `${entry.arrival.clearMinutes} min for immigration & bags -- out at ${start}`,
                    problem: null,
                });
                previous = { kind: 'land', place: entry.place };
            } else if (entry.kind === 'wake') {
                const out = entry.stay.checkOut.slice(0, 10) === date;
                const by = timeIn(entry.stay.checkOut);
                rows.push({
                    kind: 'point',
                    icon: 'hotel',
                    place: entry.place,
                    label: `Leave ${entry.place.name}`,
                    time: start,
                    note: out ? `check out by ${by}` : '',
                    problem:
                        out && minutesOfDay(start) > minutesOfDay(by)
                            ? `Check-out at ${entry.place.name} is ${by} -- the day starts at ${start}`
                            : null,
                });
                previous = { kind: 'wake', place: entry.place };
            } else if (entry.kind === 'drop' || entry.kind === 'night') {
                travelTo(entry.kind, entry.place);
                rows.push(
                    hotelRow(entry.kind, entry.stay, date, start, seconds),
                );

                // After the flight, a rest -- once checked in
                if (
                    entry.kind === 'drop' &&
                    landing &&
                    landing.restMinutes > 0
                ) {
                    const from = clock(start, seconds);
                    seconds += landing.restMinutes * 60;
                    rows.push({
                        kind: 'arrival-rest',
                        time: from,
                        until: clock(start, seconds),
                    });
                }
            } else if (entry.kind === 'fly') {
                travelTo('fly', entry.place);
                flying = true;
                const leaves = timeIn(entry.departure.at);
                const by = timeOf(
                    minutesOfDay(leaves) - entry.departure.earlyMinutes,
                );
                rows.push({
                    kind: 'point',
                    icon: 'plane',
                    place: entry.place,
                    label: `At ${entry.place.name}`,
                    time: clock(start, seconds),
                    note: `flight ${leaves} -- be there by ${by}`,
                    problem:
                        at(seconds) > minutesOfDay(by)
                            ? `You'd reach ${entry.place.name} at ${clock(start, seconds)} -- be there by ${by} for the ${leaves} flight`
                            : null,
                });
            } else {
                const stop = entry.kind === 'pause' ? entry.stop : entry.place;

                if (entry.kind === 'stop') {
                    travelTo('stop', stop);
                }

                const arrive = seconds;
                seconds += stop.minutes * 60;
                rows.push({
                    kind: 'stop',
                    stop,
                    index: entry.index,
                    number: stop.rest ? 0 : ++sights,
                    arrive: clock(start, arrive),
                    leave: clock(start, seconds),
                    open:
                        weekday && !stop.rest
                            ? stop.hours?.[weekday]
                            : undefined,
                    problem:
                        weekday && !stop.rest
                            ? visitProblem(
                                  stop.hours,
                                  weekday,
                                  at(arrive),
                                  at(seconds),
                              )
                            : null,
                });
            }
        }

        const cost =
            fares +
            target.stops.reduce(
                (sum, stop) => sum + (Number(stop.cost) || 0),
                0,
            );
        const warnings: string[] = [];

        for (const row of rows) {
            if (row.kind === 'stop' && row.problem) {
                warnings.push(`${row.stop.name} ${row.problem}.`);
            } else if (row.kind === 'point' && row.problem) {
                // "...leave the bags?" ends itself
                warnings.push(
                    /[.?!]$/.test(row.problem)
                        ? row.problem
                        : `${row.problem}.`,
                );
            }
        }

        // A day that ends on an evening flight is late on purpose
        if (at(seconds) > 22 * 60 && !flying) {
            warnings.push(
                `This day runs late -- it ends at ${clock(start, seconds)}.`,
            );
        }

        if (target.mode === 'pedestrian' && travel > 2.5 * 3600) {
            warnings.push(
                `${Math.round(travel / 360) / 10} h of walking -- the metro or a taxi for the longer legs?`,
            );
        }

        return {
            rows,
            weekday,
            lands: landing ? timeIn(landing.at) : null,
            ends: clock(start, seconds),
            travel,
            transfers,
            km: routes[dayId]?.route?.km ?? 0,
            cost,
            warnings,
        };
    };

    // ---- changing the trip ----

    const addDay = () => {
        const day: TripDay = {
            id: id(),
            mode: 'pedestrian',
            start: '09:00',
            stops: [],
            legs: {},
        };
        trip.days.push(day);
        selectedDayId.value = day.id;

        return day;
    };

    /** A day goes; its stops move to the day before (or after) it. */
    const removeDay = (dayId: string) => {
        const index = trip.days.findIndex((day) => day.id === dayId);
        const neighbour = trip.days[index - 1] ?? trip.days[index + 1];

        if (index < 0 || !neighbour) {
            return;
        }

        neighbour.stops.push(...trip.days[index].stops);
        trip.days.splice(index, 1);
        selectedDayId.value = neighbour.id;
    };

    const addStop = (dayId: string, place: Candidate, hours?: Hours | null) => {
        dayOf(dayId)?.stops.push({
            ...asStop(place),
            minutes: 60,
            ...(hours === undefined ? {} : { hours }),
        });
    };

    const removeStop = (dayId: string, stopId: string) => {
        const day = dayOf(dayId);

        if (day) {
            day.stops = day.stops.filter((each) => each.id !== stopId);
        }
    };

    /** Moves a stop within a day or to another, to just before `toIndex`. */
    const moveStop = (
        fromDayId: string,
        fromIndex: number,
        toDayId: string,
        toIndex: number,
    ) => {
        const from = dayOf(fromDayId);
        const to = dayOf(toDayId);

        if (!from || !to || !from.stops[fromIndex]) {
            return;
        }

        const [moved] = from.stops.splice(fromIndex, 1);
        // Taking it out of the same day shifts the places after it up one
        const at = from === to && fromIndex < toIndex ? toIndex - 1 : toIndex;
        to.stops.splice(Math.min(at, to.stops.length), 0, moved);
    };

    /**
     * Books a hotel: from where the last booking ends (or the first day) at
     * 15:00, until the morning after the last day at 12:00 -- the usual
     * hours, to be changed to the real ones.
     */
    const addStay = (place: Candidate) => {
        const last = trip.stays.at(-1);
        const from = last ? last.checkOut.slice(0, 10) : trip.startDate;
        let until = addDays(trip.startDate, trip.days.length);

        if (until <= from) {
            until = addDays(from, 1);
        }

        const stay: Stay = {
            id: id(),
            place: asStop(place),
            checkIn: `${from}T15:00`,
            checkOut: `${until}T12:00`,
            cost: 0,
        };
        trip.stays.push(stay);
        trip.stays.sort((a, b) => a.checkIn.localeCompare(b.checkIn));

        return stay;
    };

    const removeStay = (stayId: string) => {
        trip.stays = trip.stays.filter((stay) => stay.id !== stayId);
    };

    const nightsOf = (stay: Stay) =>
        daysBetween(stay.checkIn.slice(0, 10), stay.checkOut.slice(0, 10));

    /**
     * A rest at the end of a day (to be dragged where it belongs): "here"
     * pauses where you are; "hotel" goes back to the night's hotel, or the
     * morning's. False when a hotel rest has no hotel to go to.
     */
    const addRest = (dayId: string, where: 'here' | 'hotel') => {
        const day = dayOf(dayId);

        if (!day) {
            return false;
        }

        if (where === 'here') {
            day.stops.push({
                id: id(),
                name: 'Rest',
                address: '',
                kind: 'rest',
                lat: 0,
                lng: 0,
                minutes: 30,
                cost: 0,
                note: '',
                rest: 'here',
            });

            return true;
        }

        const { wake, sleep } = endsOf(day);
        const hotel = sleep ?? wake;

        if (!hotel) {
            return false;
        }

        day.stops.push({
            ...asStop(hotel.place),
            name: `Rest at ${hotel.place.name}`,
            minutes: 90,
            rest: 'hotel',
        });

        return true;
    };

    const setAirport = (
        which: 'arrival' | 'departure',
        place: Candidate | null,
    ) => {
        trip[which].airport = place ? asStop(place) : null;
    };

    /** Types in a leg -- or, given null, goes back to the routed one. */
    const setLeg = (dayId: string, key: string, leg: LegOverride | null) => {
        const day = dayOf(dayId);

        if (!day) {
            return;
        }

        const legs = { ...day.legs };

        if (leg) {
            legs[key] = leg;
        } else {
            delete legs[key];
        }

        day.legs = legs;
    };

    /**
     * What is wrong with the trip as a whole: bookings that clash or leave a
     * night without a bed, and flights that miss the trip's days.
     */
    const tripProblems = () => {
        const problems: string[] = [];
        const first = trip.startDate;
        const last = dateKey(trip.days.length - 1);
        const { arrival, departure } = trip;

        if (
            arrival.airport &&
            arrival.at &&
            (arrival.at.slice(0, 10) < first || arrival.at.slice(0, 10) > last)
        ) {
            problems.push(
                `You land on ${shortDate(arrival.at)}, which isn't one of the trip's days.`,
            );
        }

        if (
            departure.airport &&
            departure.at &&
            (departure.at.slice(0, 10) < first ||
                departure.at.slice(0, 10) > last)
        ) {
            problems.push(
                `The flight home is on ${shortDate(departure.at)}, which isn't one of the trip's days.`,
            );
        }

        if (arrival.at && departure.at && departure.at <= arrival.at) {
            problems.push('The flight home leaves before you land.');
        }

        for (const stay of trip.stays) {
            if (stay.checkOut <= stay.checkIn) {
                problems.push(
                    `${stay.place.name}: check-out is before check-in.`,
                );
            }
        }

        trip.stays.forEach((a, index) => {
            for (const b of trip.stays.slice(index + 1)) {
                // Out on the morning another begins is a hand-over, not a clash
                if (
                    a.checkIn.slice(0, 10) < b.checkOut.slice(0, 10) &&
                    b.checkIn.slice(0, 10) < a.checkOut.slice(0, 10)
                ) {
                    problems.push(
                        `${a.place.name} and ${b.place.name} overlap.`,
                    );
                }
            }
        });

        // Every night between the first day and the last wants a bed
        if (trip.fromHotel && trip.stays.length) {
            trip.days.slice(0, -1).forEach((day, index) => {
                if (!endsOf(day).sleep) {
                    problems.push(
                        `No hotel for the night of ${dateOf(index)}.`,
                    );
                }
            });
        }

        return problems;
    };

    /**
     * Puts a day's sights in the order that takes least time between them,
     * from where the day really starts to where it really ends -- never an
     * airport: that ride is a car transfer, and a walking optimisation won't
     * reach 40 km to it. Rests keep their places; only the sights move.
     */
    const quickest = async (dayId: string) => {
        const day = dayOf(dayId);

        if (!day) {
            return;
        }

        const entries = entriesOf(day);
        const firstStop = entries.findIndex(
            (entry) => entry.kind === 'stop' || entry.kind === 'pause',
        );
        const before = entries.slice(
            0,
            firstStop < 0 ? entries.length : firstStop,
        );
        const start = before.findLast(
            (entry) => 'place' in entry && entry.kind !== 'land',
        );
        const end = entries.find((entry) => entry.kind === 'night');
        const sights = day.stops.filter((stop) => !stop.rest);
        const path = [
            ...(start ? [placeOf(start)!] : []),
            ...sights,
            ...(end ? [placeOf(end)!] : []),
        ];

        // The first and last stay put, so it takes two in between to reorder
        if (path.length < 4 || sights.length < 2) {
            return;
        }

        if (path.length > MAX_ORDERED_STOPS) {
            throw new Error(
                `Only ${MAX_ORDERED_STOPS - 2} sights a day can be put in order.`,
            );
        }

        const order = await quickestOrder(path, day.mode);
        const offset = start ? 1 : 0;
        const reordered = order
            .filter(
                (index) => index >= offset && index < offset + sights.length,
            )
            .map((index) => sights[index - offset]);

        if (reordered.length === sights.length) {
            day.stops = day.stops.map((stop) =>
                stop.rest ? stop : reordered.shift()!,
            );
        }
    };

    // ---- saving, a moment after each change ----

    const saving = useTripSave({
        refId: initial.ref_id,
        firstRevision: initial.revision,
        title,
        trip,
        editable,
        // A copy loaded in place may not have the day that was open
        onApplied: () => {
            if (!dayOf(selectedDayId.value ?? '')) {
                selectedDayId.value = trip.days[0]?.id ?? null;
            }
        },
    });

    onBeforeUnmount(() => {
        clearTimeout(routeTimer);
        asking?.abort();
    });

    return {
        title,
        trip,
        selectedDayId,
        routes,
        status: saving.status,
        conflict: saving.conflict,
        saveError: saving.saveError,
        dayOf,
        colorOf,
        dateOf,
        tabDateOf,
        weekdayOf,
        endsOf,
        entriesOf,
        pathOf,
        timelineOf,
        addDay,
        removeDay,
        addStop,
        removeStop,
        moveStop,
        addStay,
        removeStay,
        nightsOf,
        addRest,
        setAirport,
        setLeg,
        tripProblems,
        quickest,
        takeTheirs: saving.takeTheirs,
        keepMine: saving.keepMine,
        canCatchUp: saving.canCatchUp,
        catchUp: saving.catchUp,
        retry: saving.retry,
        flush: saving.flush,
    };
}

export type TripPlan = ReturnType<typeof useTripPlan>;
