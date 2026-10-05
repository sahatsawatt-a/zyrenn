<?php

namespace App\Support\Maps;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A trip a part at a time, for MCP: an outline of every day, one day in full
 * with the rides between its places, and changes made by id -- so a long
 * trip is never read, or sent back, whole to move one stop.
 *
 * Reads take the document as TripDocument leaves it. Edits work on it too,
 * and say what is wrong by throwing; what they make is normalized again when
 * the trip is saved.
 */
class TripParts
{
    /** The fields of a place a tool may give, as the document names them. */
    public const PLACE_FIELDS = ['name', 'address', 'kind', 'lat', 'lng', 'minutes', 'cost', 'note', 'hours', 'rest'];

    /**
     * The date of the day at $index, YYYY-MM-DD.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function dateOf(array $doc, int $index): string
    {
        return Carbon::parse($doc['startDate'])->addDays($index)->toDateString();
    }

    /**
     * Everything but the days' detail: the flights, the hotels, and each day
     * with its stops by id and name.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function outline(array $doc): array
    {
        return [
            'start_date' => $doc['startDate'],
            'currency' => $doc['currency'],
            'from_hotel' => $doc['fromHotel'],
            'arrival' => [
                'airport' => self::brief($doc['arrival']['airport'] ?? null),
                'at' => $doc['arrival']['at'] ?: null,
                'clear_minutes' => $doc['arrival']['clearMinutes'],
                'rest_minutes' => $doc['arrival']['restMinutes'],
            ],
            'departure' => [
                'airport' => self::brief($doc['departure']['airport'] ?? null),
                'at' => $doc['departure']['at'] ?: null,
                'early_minutes' => $doc['departure']['earlyMinutes'],
            ],
            'stays' => array_map(fn (array $stay) => [
                'id' => $stay['id'],
                ...self::brief($stay['place']),
                'check_in' => $stay['checkIn'] ?: null,
                'check_out' => $stay['checkOut'] ?: null,
                'cost' => $stay['cost'] ?? 0,
            ], $doc['stays']),
            'days' => self::days($doc),
        ];
    }

    /**
     * Each day by number, id and date, with its stops by id and name.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function days(array $doc): array
    {
        $days = [];

        foreach (array_values($doc['days']) as $index => $day) {
            $days[] = [
                'day' => $index + 1,
                'id' => $day['id'],
                'date' => self::dateOf($doc, $index),
                'mode' => $day['mode'],
                'start' => $day['start'],
                'stops' => array_map(fn (array $stop) => array_filter([
                    'id' => $stop['id'],
                    'name' => $stop['name'],
                    'minutes' => $stop['minutes'],
                    'rest' => $stop['rest'] ?? null,
                ], fn ($value) => $value !== null), $day['stops']),
            ];
        }

        return $days;
    }

    /**
     * One day in full: its stops with everything on them, the way it goes
     * from place to place -- hotel, airport and stops -- and each ride between
     * two of them, with what was typed in by hand for it.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function day(array $doc, int $index): array
    {
        $day = $doc['days'][$index];
        $legs = (array) $day['legs'];
        $route = self::route($doc, $index);
        $rides = [];

        foreach (array_slice($route, 1) as $at => $to) {
            $from = $route[$at];
            $leg = $legs["{$from['id']}>{$to['id']}"] ?? null;

            $rides[] = [
                'from' => $from['id'],
                'to' => $to['id'],
                'by_hand' => is_array($leg) ? $leg : null,
            ];
        }

        return [
            'day' => $index + 1,
            'id' => $day['id'],
            'date' => self::dateOf($doc, $index),
            'mode' => $day['mode'],
            'start' => $day['start'],
            'stops' => $day['stops'],
            'route' => $route,
            'rides' => $rides,
        ];
    }

    /**
     * A day's places in the order it goes between them, as the trip page
     * draws it: off the plane or out of the morning's hotel; on a landing
     * day, to the hotel to check in; the stops (a rest where you are has no
     * place of its own); and to the night's hotel or the flight home.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{id: string, name: string, as: string}>
     */
    public static function route(array $doc, int $index): array
    {
        $day = $doc['days'][$index];
        ['wake' => $wake, 'sleep' => $sleep, 'lands' => $lands, 'leaves' => $leaves] = self::endsOf($doc, $index);

        if (! $day['stops'] && ! $lands && ! $leaves && (! $sleep || $sleep['id'] === ($wake['id'] ?? null))) {
            return [];
        }

        $route = [];
        $dropped = false;

        if ($lands) {
            $route[] = self::step($doc['arrival']['airport'], 'arrival airport');

            if ($sleep && ! $leaves) {
                $route[] = self::step($sleep['place'], 'hotel');
                $dropped = true;
            }
        } elseif ($wake) {
            $route[] = self::step($wake['place'], 'hotel');
        }

        foreach ($day['stops'] as $stop) {
            if (($stop['rest'] ?? null) !== 'here') {
                $route[] = self::step($stop, ($stop['rest'] ?? null) === 'hotel' ? 'rest at the hotel' : 'stop');
                $dropped = false;
            }
        }

        if ($leaves) {
            $route[] = self::step($doc['departure']['airport'], 'departure airport');
        } elseif ($sleep && ! $dropped) {
            $route[] = self::step($sleep['place'], 'hotel');
        }

        return $route;
    }

    /**
     * Which hotel a day starts and ends at, and whether it lands or flies.
     *
     * @param  array<string, mixed>  $doc
     * @return array{wake: array<string, mixed>|null, sleep: array<string, mixed>|null, lands: bool, leaves: bool}
     */
    private static function endsOf(array $doc, int $index): array
    {
        $date = self::dateOf($doc, $index);
        $stays = $doc['fromHotel'] ? $doc['stays'] : [];

        return [
            'wake' => self::first($stays, fn ($stay) => substr($stay['checkIn'], 0, 10) < $date && $date <= substr($stay['checkOut'], 0, 10)),
            'sleep' => self::first($stays, fn ($stay) => substr($stay['checkIn'], 0, 10) <= $date && $date < substr($stay['checkOut'], 0, 10)),
            'lands' => isset($doc['arrival']['airport']) && substr($doc['arrival']['at'], 0, 10) === $date,
            'leaves' => isset($doc['departure']['airport']) && substr($doc['departure']['at'], 0, 10) === $date,
        ];
    }

    // ---- finding ----

    /**
     * A day's index, from its number (1 is the first) or its id.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function dayIndex(array $doc, int|string $day): int
    {
        if (is_int($day) || ctype_digit($day)) {
            $index = (int) $day - 1;

            if (isset($doc['days'][$index])) {
                return $index;
            }

            throw new InvalidArgumentException("There is no day {$day}: the trip has ".count($doc['days']).'.');
        }

        foreach ($doc['days'] as $index => $each) {
            if ($each['id'] === $day) {
                return $index;
            }
        }

        throw new InvalidArgumentException("There is no day \"{$day}\". get-trip lists the days.");
    }

    /**
     * Where a stop is, as [day index, stop index].
     *
     * @param  array<string, mixed>  $doc
     * @return array{int, int}
     */
    public static function stopAt(array $doc, string $id): array
    {
        foreach ($doc['days'] as $dayIndex => $day) {
            foreach ($day['stops'] as $stopIndex => $stop) {
                if ($stop['id'] === $id) {
                    return [$dayIndex, $stopIndex];
                }
            }
        }

        throw new InvalidArgumentException("There is no stop \"{$id}\". get-trip lists each day's stops.");
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    public static function stayAt(array $doc, string $id): int
    {
        foreach ($doc['stays'] as $index => $stay) {
            if ($stay['id'] === $id) {
                return $index;
            }
        }

        throw new InvalidArgumentException("There is no hotel stay \"{$id}\". get-trip lists the stays.");
    }

    // ---- changing ----

    /**
     * A place in the document's shape, from the fields a tool was given laid
     * over what it was. A rest "here" needs no place; one "at the hotel" is
     * at the hotel the day sleeps in, or failing that woke in.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $given
     * @param  array<string, mixed>  $was
     * @return array<string, mixed>
     */
    public static function place(array $doc, int $dayIndex, array $given, array $was = []): array
    {
        $place = [...$was, ...array_intersect_key($given, array_flip(self::PLACE_FIELDS))];
        $place['id'] ??= TripDocument::newId();

        if (($place['rest'] ?? null) === 'here') {
            return [...['name' => 'Rest', 'kind' => 'rest', 'minutes' => 30], ...$place, 'lat' => 0, 'lng' => 0];
        }

        if (($place['rest'] ?? null) === 'hotel' && ! isset($given['lat'], $given['lng']) && ! $was) {
            ['wake' => $wake, 'sleep' => $sleep] = self::endsOf([...$doc, 'fromHotel' => true], $dayIndex);
            $hotel = ($sleep ?? $wake)['place'] ?? throw new InvalidArgumentException('Day '.($dayIndex + 1).' has no hotel to rest at: book one with add_stays first.');

            return [
                ...['name' => "Rest at {$hotel['name']}", 'minutes' => 90],
                ...array_intersect_key($hotel, array_flip(['address', 'kind', 'lat', 'lng'])),
                ...$place,
            ];
        }

        if (! isset($place['lat'], $place['lng'])) {
            throw new InvalidArgumentException('"'.($place['name'] ?? 'A place').'" needs a "lat" and "lng" -- search-places finds them.');
        }

        return $place;
    }

    /**
     * Takes stops off, by id, wherever they are.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    public static function deleteStops(array $doc, array $ids): array
    {
        foreach ($ids as $id) {
            [$day, $stop] = self::stopAt($doc, $id);
            array_splice($doc['days'][$day]['stops'], $stop, 1);
        }

        return $doc;
    }

    /**
     * Puts a stop into a day at a position (1 is first), or at the end.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>
     */
    public static function insertStop(array $doc, int $dayIndex, array $stop, ?int $position = null): array
    {
        $stops = &$doc['days'][$dayIndex]['stops'];
        $at = $position === null ? count($stops) : max(0, min($position - 1, count($stops)));
        array_splice($stops, $at, 0, [$stop]);

        return $doc;
    }

    /**
     * The ride between two places, typed in by hand -- or, given null, taken
     * back to what the router says. It is kept on the day whose route runs
     * from one to the other.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>|null  $leg
     * @return array<string, mixed>
     */
    public static function setLeg(array $doc, string $from, string $to, ?array $leg, int|string|null $day = null): array
    {
        $dayIndex = $day !== null ? self::dayIndex($doc, $day) : self::dayRiding($doc, $from, $to);
        $ids = array_column(self::route($doc, $dayIndex), 'id');
        $joined = array_filter(array_keys($ids), fn ($at) => $ids[$at] === $from && ($ids[$at + 1] ?? null) === $to);

        if (! $joined) {
            throw new InvalidArgumentException('Day '.($dayIndex + 1)." doesn't go straight from \"{$from}\" to \"{$to}\": a ride joins two places one after the other on a day's route, which get-trip with \"day\" shows.");
        }

        $legs = (array) $doc['days'][$dayIndex]['legs'];

        if ($leg === null) {
            unset($legs["{$from}>{$to}"]);
        } else {
            $legs["{$from}>{$to}"] = [...['mode' => 'other', 'minutes' => 0, 'cost' => 0, 'note' => ''], ...($legs["{$from}>{$to}"] ?? []), ...$leg];
        }

        $doc['days'][$dayIndex]['legs'] = $legs;

        return $doc;
    }

    /**
     * The day a ride between two places is on: the one with either as a stop,
     * or else the only one whose route joins them.
     *
     * @param  array<string, mixed>  $doc
     */
    private static function dayRiding(array $doc, string $from, string $to): int
    {
        foreach ([$from, $to] as $id) {
            try {
                return self::stopAt($doc, $id)[0];
            } catch (InvalidArgumentException) {
                // A hotel or an airport: on no day's list of stops
            }
        }

        $days = [];

        foreach (range(0, count($doc['days']) - 1) as $index) {
            if (str_contains('>'.implode('>', array_column(self::route($doc, $index), 'id')).'>', ">{$from}>{$to}>")) {
                $days[] = $index;
            }
        }

        return match (count($days)) {
            1 => $days[0],
            0 => throw new InvalidArgumentException("No day goes straight from \"{$from}\" to \"{$to}\". get-trip with \"day\" shows a day's route."),
            default => throw new InvalidArgumentException("Several days go from \"{$from}\" to \"{$to}\"; say which with \"day\"."),
        };
    }

    // ---- small things ----

    /**
     * @param  array<string, mixed>|null  $place
     * @return array{id: string, name: string, address: string, lat: float, lng: float}|null
     */
    private static function brief(?array $place): ?array
    {
        return $place === null ? null : [
            'id' => $place['id'],
            'name' => $place['name'],
            'address' => $place['address'] ?? '',
            'lat' => $place['lat'],
            'lng' => $place['lng'],
        ];
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array{id: string, name: string, as: string}
     */
    private static function step(array $place, string $as): array
    {
        return ['id' => $place['id'], 'name' => $place['name'], 'as' => $as];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): bool  $test
     * @return array<string, mixed>|null
     */
    private static function first(array $items, callable $test): ?array
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return $item;
            }
        }

        return null;
    }
}
