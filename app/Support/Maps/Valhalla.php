<?php

namespace App\Support\Maps;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Directions from Valhalla's public server (FOSSGIS), as Prism uses: keyless,
 * the whole planet, and open to apps that name themselves in X-Client-Id.
 * Asked from the server so answers are kept -- a trip asks again for every
 * day each time it opens, and roads change on a clock of weeks.
 *
 * The route's shape comes back as an encoded polyline (six decimal places)
 * and is decoded here, so a browser gets plain [lng, lat] pairs.
 */
class Valhalla
{
    public const ENDPOINT = 'https://valhalla1.openstreetmap.de';

    public const CLIENT_ID = 'zyrenn-maps';

    public const COSTINGS = ['auto', 'bicycle', 'pedestrian'];

    /** Stops in one request: a day's worth. The server is shared; this is a courtesy. */
    public const MAX_STOPS = 25;

    /** The optimiser solves a travelling-salesman problem: kept smaller still. */
    public const MAX_ORDERED = 10;

    public const TTL = 60 * 60 * 24 * 7;

    /**
     * The way through the stops, in order.
     *
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return array{km: float, seconds: float, legs: list<array{km: float, seconds: float}>, line: list<array{float, float}>, steps: list<array{instruction: string, km: float, seconds: float, at: array{float, float}}>}
     */
    public static function route(array $stops, string $costing): array
    {
        return Cache::remember(self::routeKey($stops, $costing), self::TTL, fn () => self::shape(self::ask('/route', $stops, $costing)));
    }

    /** Jobs in one batch: a trip's days, and its rides to and from the airport. */
    public const MAX_JOBS = 30;

    /**
     * Many routes at once -- every day of a trip -- each from the cache when
     * it can be, the rest asked of Valhalla side by side rather than one
     * after another: a trip of three days waits about as long as one route.
     *
     * @param  array<string, array{stops: list<array{lat: float, lng: float}>, costing: string}>  $jobs  by the caller's own name for each
     * @return array<string, array<string, mixed>> each job's route, or ['error' => why]
     */
    public static function routes(array $jobs): array
    {
        $answers = [];
        $asking = [];

        foreach ($jobs as $name => $job) {
            $cached = Cache::get(self::routeKey($job['stops'], $job['costing']));

            if ($cached !== null) {
                $answers[$name] = $cached;
            } else {
                $asking[$name] = $job;
            }
        }

        if ($asking !== []) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $name) => $pool->as($name)
                    ->timeout(20)
                    ->withHeaders(['X-Client-Id' => self::CLIENT_ID])
                    ->post(self::ENDPOINT.'/route', self::body($asking[$name]['stops'], $asking[$name]['costing'])),
                array_keys($asking),
            ));

            foreach ($asking as $name => $job) {
                $response = $responses[$name] ?? null;
                $body = $response instanceof Response ? ($response->json() ?? []) : [];

                if (! $response instanceof Response || $response->failed() || ! isset($body['trip'])) {
                    $answers[$name] = ['error' => $body['error'] ?? 'The router could not be reached.'];

                    continue;
                }

                $answers[$name] = self::shape($body['trip']);
                Cache::put(self::routeKey($job['stops'], $job['costing']), $answers[$name], self::TTL);
            }
        }

        return $answers;
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $stops
     */
    private static function routeKey(array $stops, string $costing): string
    {
        return 'maps:valhalla:route:'.sha1((string) json_encode([$costing, self::points($stops)]));
    }

    /**
     * Valhalla's trip as the map reads it: plain [lng, lat] points, each leg's
     * time and distance, and the turns.
     *
     * @param  array<string, mixed>  $trip
     * @return array{km: float, seconds: float, legs: list<array{km: float, seconds: float}>, line: list<array{float, float}>, steps: list<array{instruction: string, km: float, seconds: float, at: array{float, float}}>}
     */
    private static function shape(array $trip): array
    {
        $line = [];
        $legs = [];
        $steps = [];

        foreach ($trip['legs'] ?? [] as $leg) {
            $shape = self::decode((string) ($leg['shape'] ?? ''));
            $offset = count($line);
            array_push($line, ...$shape);
            $legs[] = ['km' => (float) $leg['summary']['length'], 'seconds' => (float) $leg['summary']['time']];

            foreach ($leg['maneuvers'] ?? [] as $maneuver) {
                $steps[] = [
                    'instruction' => (string) ($maneuver['instruction'] ?? ''),
                    'km' => (float) ($maneuver['length'] ?? 0),
                    'seconds' => (float) ($maneuver['time'] ?? 0),
                    'at' => $line[$offset + (int) ($maneuver['begin_shape_index'] ?? 0)] ?? ($shape[0] ?? [0.0, 0.0]),
                ];
            }
        }

        return [
            'km' => (float) ($trip['summary']['length'] ?? 0),
            'seconds' => (float) ($trip['summary']['time'] ?? 0),
            'legs' => $legs,
            'line' => $line,
            'steps' => $steps,
        ];
    }

    /**
     * The quickest order to visit the stops in, as their indexes; the first
     * and last stay where they are.
     *
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return list<int>
     */
    public static function quickestOrder(array $stops, string $costing): array
    {
        $key = 'maps:valhalla:order:'.sha1((string) json_encode([$costing, self::points($stops)]));

        return Cache::remember($key, self::TTL, fn () => array_values(array_map(
            fn (array $location) => (int) $location['original_index'],
            self::ask('/optimized_route', $stops, $costing)['locations'] ?? [],
        )));
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return array<string, mixed> the trip Valhalla answers with
     */
    private static function ask(string $path, array $stops, string $costing): array
    {
        $response = Http::timeout(20)
            ->withHeaders(['X-Client-Id' => self::CLIENT_ID])
            ->post(self::ENDPOINT.$path, self::body($stops, $costing));

        $body = $response->json() ?? [];

        if ($response->failed() || ! isset($body['trip'])) {
            // Valhalla says why in words: "No path could be found for input"
            throw new RuntimeException($body['error'] ?? "The router answered {$response->status()}.");
        }

        return $body['trip'];
    }

    /**
     * What Valhalla is asked: the stops as it names them, and how to travel.
     *
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return array<string, mixed>
     */
    private static function body(array $stops, string $costing): array
    {
        return [
            'locations' => array_map(fn (array $stop) => ['lat' => $stop['lat'], 'lon' => $stop['lng']], $stops),
            'costing' => $costing,
            'directions_options' => ['units' => 'kilometers'],
        ];
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return list<array{float, float}>
     */
    private static function points(array $stops): array
    {
        return array_map(fn (array $stop) => [round($stop['lat'], 6), round($stop['lng'], 6)], $stops);
    }

    /**
     * An encoded polyline, six decimal places, as [lng, lat] pairs: zig-zag
     * varints, each the difference from the point before.
     *
     * @return list<array{float, float}>
     */
    public static function decode(string $encoded, int $precision = 6): array
    {
        $factor = 10 ** $precision;
        $points = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $length = strlen($encoded);

        $next = function () use ($encoded, &$index, $length): int {
            $result = 0;
            $shift = 0;

            do {
                $byte = $index < $length ? ord($encoded[$index++]) - 63 : 0;
                $result |= ($byte & 0x1F) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            return ($result & 1) ? ~($result >> 1) : $result >> 1;
        };

        while ($index < $length) {
            $lat += $next();
            $lng += $next();
            $points[] = [$lng / $factor, $lat / $factor];
        }

        return $points;
    }
}
