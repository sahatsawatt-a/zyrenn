<?php

namespace App\Support\Maps;

/**
 * What a trip's document may hold, and the one place that makes it so.
 *
 * The page writes it, and so does MCP; either may send something odd -- a
 * stale shape, a stray key, a number as text, a list of a thousand stops.
 * Whatever comes in leaves here in the shape the page reads, with every
 * string, number and list held to a size a trip could really need:
 *
 *     startDate   YYYY-MM-DD, the first day
 *     currency    what costs are shown in, "¥"
 *     fromHotel   whether days start and end at the hotel
 *     arrival     {airport, at, clearMinutes, restMinutes}
 *     departure   {airport, at, earlyMinutes}
 *     stays       [{id, place, checkIn, checkOut, cost}] -- cost for the whole stay
 *     days        [{id, mode, start, stops: [stop], legs: {from>to: leg}}]
 *
 * A place (a stop, a hotel, an airport) is {id, name, address, kind, lat,
 * lng, minutes, cost, note}, with `hours` once looked up and `rest` for a
 * rest ("here" or "hotel"). Times are local, YYYY-MM-DDTHH:MM or HH:MM.
 */
class TripDocument
{
    public const MAX_DAYS = 60;

    public const MAX_STOPS = 40;

    public const MAX_STAYS = 30;

    public const MAX_LEGS = 80;

    public const TRAVEL_MODES = ['pedestrian', 'auto', 'bicycle'];

    public const LEG_MODES = ['metro', 'bus', 'taxi', 'train', 'walk', 'other'];

    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * The document in its known shape.
     *
     * @return array<string, mixed>
     */
    public static function normalize(mixed $content): array
    {
        $doc = is_array($content) ? $content : [];

        return [
            'startDate' => self::date($doc['startDate'] ?? null) ?? now()->toDateString(),
            'currency' => self::text($doc['currency'] ?? '', 8),
            'fromHotel' => (bool) ($doc['fromHotel'] ?? true),
            'arrival' => [
                'airport' => self::place($doc['arrival']['airport'] ?? null),
                'at' => self::moment($doc['arrival']['at'] ?? null),
                'clearMinutes' => self::minutes($doc['arrival']['clearMinutes'] ?? 60),
                'restMinutes' => self::minutes($doc['arrival']['restMinutes'] ?? 0),
            ],
            'departure' => [
                'airport' => self::place($doc['departure']['airport'] ?? null),
                'at' => self::moment($doc['departure']['at'] ?? null),
                'earlyMinutes' => self::minutes($doc['departure']['earlyMinutes'] ?? 180),
            ],
            'stays' => self::inOrderOfStay(self::listOf($doc['stays'] ?? [], self::MAX_STAYS, function (array $stay) {
                $place = self::place($stay['place'] ?? null);

                return $place === null ? null : [
                    'id' => self::id($stay['id'] ?? null),
                    'place' => $place,
                    'checkIn' => self::moment($stay['checkIn'] ?? null),
                    'checkOut' => self::moment($stay['checkOut'] ?? null),
                    'cost' => self::money($stay['cost'] ?? 0),
                ];
            })),
            // A trip always has a day to plan in
            'days' => self::listOf($doc['days'] ?? [], self::MAX_DAYS, fn (array $day) => self::day($day))
                ?: [self::day([])],
        ];
    }

    /**
     * Hotels in the order they are stayed in, as the trip page keeps them --
     * whoever added them, in whatever order -- so the first is always the
     * first: formulas name a stay by its place. One with no check-in yet
     * goes last.
     *
     * @param  list<array<string, mixed>>  $stays
     * @return list<array<string, mixed>>
     */
    private static function inOrderOfStay(array $stays): array
    {
        // usort is stable: stays checking in together keep the order given
        usort($stays, fn (array $a, array $b) => [$a['checkIn'] === '', $a['checkIn']] <=> [$b['checkIn'] === '', $b['checkIn']]);

        return $stays;
    }

    /**
     * What a trip comes to: the one reckoning that get-trip, the trip page's
     * header and formulas all read, so they never disagree.
     *
     * A day costs its stops plus the rides typed in by hand along its route
     * (as the page counts them: a ride left over from a route since changed
     * is not on it); a hotel costs what its stay does, once.
     *
     * @param  array<string, mixed>  $doc  as normalize leaves it
     * @return array{
     *     currency: string,
     *     start_date: string,
     *     end_date: string,
     *     day_count: int,
     *     nights: int,
     *     stops_cost: float,
     *     rides_cost: float,
     *     stays_cost: float,
     *     total_cost: float,
     *     days: list<array{day: int, id: string, date: string, stops: int, cost: float}>,
     *     stays: list<array{id: string, name: string, nights: int, cost: float}>,
     * }
     */
    public static function totals(array $doc): array
    {
        $days = [];
        $stopsCost = $ridesCost = 0.0;

        foreach (array_values($doc['days']) as $index => $day) {
            $stops = round(array_sum(array_map(fn (array $stop) => (float) ($stop['cost'] ?? 0), $day['stops'])), 2);
            $legs = (array) $day['legs'];
            $ids = array_column(TripParts::route($doc, $index), 'id');
            $rides = 0.0;

            foreach (array_slice($ids, 1) as $at => $to) {
                $rides += (float) ($legs["{$ids[$at]}>{$to}"]['cost'] ?? 0);
            }

            $stopsCost += $stops;
            $ridesCost += $rides;
            $days[] = [
                'day' => $index + 1,
                'id' => $day['id'],
                'date' => TripParts::dateOf($doc, $index),
                'stops' => count(array_filter($day['stops'], fn (array $stop) => ! isset($stop['rest']))),
                'cost' => round($stops + $rides, 2),
            ];
        }

        $stays = array_map(fn (array $stay) => [
            'id' => $stay['id'],
            'name' => $stay['place']['name'],
            'nights' => self::nights($stay),
            'cost' => (float) ($stay['cost'] ?? 0),
        ], array_values($doc['stays']));
        $staysCost = round(array_sum(array_column($stays, 'cost')), 2);

        return [
            'currency' => $doc['currency'],
            'start_date' => $doc['startDate'],
            'end_date' => TripParts::dateOf($doc, max(0, count($days) - 1)),
            'day_count' => count($days),
            'nights' => array_sum(array_column($stays, 'nights')),
            'stops_cost' => round($stopsCost, 2),
            'rides_cost' => round($ridesCost, 2),
            'stays_cost' => $staysCost,
            'total_cost' => round($stopsCost + $ridesCost + $staysCost, 2),
            'days' => $days,
            'stays' => $stays,
        ];
    }

    /**
     * Nights a stay books: check-in day to check-out day.
     *
     * @param  array<string, mixed>  $stay
     */
    private static function nights(array $stay): int
    {
        $in = substr((string) $stay['checkIn'], 0, 10);
        $out = substr((string) $stay['checkOut'], 0, 10);

        return $in !== '' && $out !== '' ? max(0, (int) round((strtotime("{$out} 12:00 UTC") - strtotime("{$in} 12:00 UTC")) / 86400)) : 0;
    }

    /**
     * Every place a trip names -- stops, hotels, airports -- and the notes on
     * them, as one searchable string.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function writtenText(array $doc): string
    {
        $places = [
            $doc['arrival']['airport'] ?? null,
            $doc['departure']['airport'] ?? null,
            ...array_column($doc['stays'] ?? [], 'place'),
            ...array_merge(...array_map(fn ($day) => $day['stops'] ?? [], $doc['days'] ?? [[]])),
        ];

        $words = [];

        foreach ($places as $place) {
            if (! is_array($place) || ($place['rest'] ?? null) === 'here') {
                continue;
            }

            $words[] = trim(($place['name'] ?? '').' '.($place['note'] ?? ''));
        }

        return implode(' · ', array_unique(array_filter($words)));
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private static function day(array $day): array
    {
        $legs = [];

        foreach (array_slice(is_array($day['legs'] ?? null) ? $day['legs'] : [], 0, self::MAX_LEGS, true) as $key => $leg) {
            // A leg is named by the two places it joins: "fromId>toId"
            if (! is_string($key) || ! preg_match('/^[\w-]{1,32}>[\w-]{1,32}$/', $key) || ! is_array($leg)) {
                continue;
            }

            $legs[$key] = [
                'mode' => in_array($leg['mode'] ?? null, self::LEG_MODES, true) ? $leg['mode'] : 'other',
                'minutes' => self::minutes($leg['minutes'] ?? 0),
                'cost' => self::money($leg['cost'] ?? 0),
                'note' => self::text($leg['note'] ?? '', 200),
            ];
        }

        return [
            'id' => self::id($day['id'] ?? null),
            'mode' => in_array($day['mode'] ?? null, self::TRAVEL_MODES, true) ? $day['mode'] : 'pedestrian',
            'start' => self::clock($day['start'] ?? null) ?? '09:00',
            'stops' => self::listOf($day['stops'] ?? [], self::MAX_STOPS, fn (array $stop) => self::place($stop)),
            'legs' => (object) $legs,
        ];
    }

    /**
     * A place on the trip, or null when it has nowhere to be. A rest "here"
     * has no place of its own and keeps no position.
     *
     * @return array<string, mixed>|null
     */
    private static function place(mixed $place): ?array
    {
        if (! is_array($place)) {
            return null;
        }

        $rest = in_array($place['rest'] ?? null, ['here', 'hotel'], true) ? $place['rest'] : null;
        $lat = self::number($place['lat'] ?? null, -90, 90);
        $lng = self::number($place['lng'] ?? null, -180, 180);

        if ($rest !== 'here' && ($lat === null || $lng === null)) {
            return null;
        }

        return array_filter([
            'id' => self::id($place['id'] ?? null),
            'name' => self::text($place['name'] ?? '', 200) ?: 'Unnamed place',
            'address' => self::text($place['address'] ?? '', 300),
            'kind' => self::text($place['kind'] ?? '', 120),
            'lat' => $lat ?? 0.0,
            'lng' => $lng ?? 0.0,
            'minutes' => self::minutes($place['minutes'] ?? 60),
            'cost' => self::money($place['cost'] ?? 0),
            'note' => self::text($place['note'] ?? '', 2000),
            'savedId' => is_string($place['savedId'] ?? null) ? self::text($place['savedId'], 32) : null,
            'hours' => array_key_exists('hours', $place) ? self::hours($place['hours']) : false,
            'rest' => $rest,
        ], fn ($value) => $value !== null && $value !== false);
    }

    /**
     * Opening hours, a day at a time as Google words them; null when looked
     * up and none were found (kept, so they aren't looked up again).
     *
     * @return array<string, string>|null
     */
    private static function hours(mixed $hours): ?array
    {
        if (! is_array($hours)) {
            return null;
        }

        $kept = [];

        foreach (self::WEEKDAYS as $weekday) {
            if (is_string($hours[$weekday] ?? null)) {
                $kept[$weekday] = self::text($hours[$weekday], 80);
            }
        }

        return $kept ?: null;
    }

    /**
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $each
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $items, int $max, callable $each): array
    {
        if (! is_array($items)) {
            return [];
        }

        $kept = [];

        foreach (array_slice(array_values($items), 0, $max) as $item) {
            if (is_array($item) && ($made = $each($item)) !== null) {
                $kept[] = $made;
            }
        }

        return $kept;
    }

    /**
     * An id for something new on a trip -- a day, a stop, a hotel.
     */
    public static function newId(): string
    {
        return strtolower(str()->random(8));
    }

    private static function id(mixed $id): string
    {
        return is_string($id) && preg_match('/^[\w-]{1,32}$/', $id) ? $id : self::newId();
    }

    private static function text(mixed $text, int $max): string
    {
        return is_scalar($text) ? mb_substr(trim((string) $text), 0, $max) : '';
    }

    private static function number(mixed $value, float $min, float $max): ?float
    {
        return is_numeric($value) && (float) $value >= $min && (float) $value <= $max ? (float) $value : null;
    }

    private static function minutes(mixed $value): int
    {
        return is_numeric($value) ? (int) min(max(round((float) $value), 0), 24 * 60 * 3) : 0;
    }

    private static function money(mixed $value): float
    {
        return is_numeric($value) ? round(min(max((float) $value, 0), 1e9), 2) : 0.0;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }

    /** YYYY-MM-DDTHH:MM, or '' for not set. */
    private static function moment(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) ? $value : '';
    }

    private static function clock(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }
}
