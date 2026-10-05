<?php

namespace App\Support\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google's facts about a place -- opening hours, rating, phone, a photo -- by
 * way of SerpAPI's Google Maps search, for the Maps demo.
 *
 * A search is a name near a point, and Google answers with whatever it thinks
 * fits: sometimes the one place, sometimes a list, sometimes a branch across
 * town. So the answer is only taken when it lies within MATCH_METRES of where
 * the place is on our map; anything further is "not found" rather than the
 * wrong place's hours.
 *
 * Every search costs one of the plan's monthly allowance, so answers are kept:
 * a month for a place found (hours change on that sort of timescale), a day
 * for one that wasn't (it may simply have been worded differently).
 *
 * Google's coordinates here are plain WGS84, even in mainland China: measured
 * at 4 m from OpenStreetMap's for Yu Garden and the Oriental Pearl Tower, so
 * no China offset is applied -- doing so would move them about 480 m.
 */
class PlaceDetails
{
    public const ENDPOINT = 'https://serpapi.com/search.json';

    public const MATCH_METRES = 400;

    public const FOUND_TTL = 60 * 60 * 24 * 30;

    public const MISSED_TTL = 60 * 60 * 24;

    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public static function configured(): bool
    {
        return filled(config('services.serpapi.key'));
    }

    /**
     * @return array<string, mixed> `found` false, or the place's details
     */
    public static function lookup(string $name, float $lat, float $lng): array
    {
        // Four decimal places is about 11 m: the same place asked twice is one search
        $key = 'maps:place-details:'.sha1(mb_strtolower(trim($name)).sprintf('|%.4f|%.4f', $lat, $lng));

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $details = self::fetch($name, $lat, $lng);
        Cache::put($key, $details, $details['found'] ? self::FOUND_TTL : self::MISSED_TTL);

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetch(string $name, float $lat, float $lng): array
    {
        $response = Http::timeout(20)->get(self::ENDPOINT, [
            'engine' => 'google_maps',
            'type' => 'search',
            'q' => $name,
            'll' => sprintf('@%.6f,%.6f,17z', $lat, $lng),
            'hl' => 'en',
            'api_key' => config('services.serpapi.key'),
        ]);

        $body = $response->json() ?? [];

        // "No results" is an answer; a refused key or an exhausted plan is not
        if (isset($body['error']) && ! str_contains($body['error'], "hasn't returned any results")) {
            throw new RuntimeException($body['error']);
        }

        if ($response->failed() && ! isset($body['error'])) {
            throw new RuntimeException("SerpAPI answered {$response->status()}");
        }

        $candidates = isset($body['place_results']) ? [$body['place_results']] : ($body['local_results'] ?? []);
        $best = null;
        $nearest = null;

        foreach ($candidates as $candidate) {
            $at = $candidate['gps_coordinates'] ?? null;

            if (! isset($at['latitude'], $at['longitude'])) {
                continue;
            }

            $metres = self::metres($lat, $lng, (float) $at['latitude'], (float) $at['longitude']);

            if ($nearest === null || $metres < $nearest) {
                [$nearest, $best] = [$metres, $candidate];
            }
        }

        if ($best === null || $nearest > self::MATCH_METRES) {
            return ['found' => false, 'nearestMetres' => $nearest === null ? null : (int) round($nearest)];
        }

        $type = $best['type'] ?? null;

        return [
            'found' => true,
            'title' => $best['title'] ?? $name,
            'type' => is_array($type) ? implode(', ', $type) : $type,
            'rating' => $best['rating'] ?? null,
            'reviews' => $best['reviews'] ?? null,
            'price' => $best['price'] ?? null,
            'address' => $best['address'] ?? null,
            'phone' => $best['phone'] ?? null,
            'website' => $best['website'] ?? null,
            'description' => is_string($best['description'] ?? null) ? $best['description'] : null,
            'thumbnail' => $best['thumbnail'] ?? null,
            'hours' => self::hours($best),
            'distanceMetres' => (int) round($nearest),
            'fetchedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Google gives hours two ways -- {"monday": "9 AM–5 PM", ...} in a list of
     * results, [{"monday": "9 AM–5 PM"}, ...] for a single place -- and sometimes
     * not at all. One shape out, Monday first, or null.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, string>|null
     */
    public static function hours(array $place): ?array
    {
        $raw = $place['operating_hours'] ?? $place['hours'] ?? null;

        if (! is_array($raw)) {
            return null;
        }

        $flat = array_is_list($raw) ? array_merge(...array_filter($raw, 'is_array')) : $raw;
        $hours = [];

        foreach (self::DAYS as $day) {
            if (isset($flat[$day]) && is_string($flat[$day])) {
                $hours[$day] = $flat[$day];
            }
        }

        return $hours ?: null;
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $h = sin(($lat2 - $lat1) * $rad / 2) ** 2
            + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 12742000 * asin(sqrt($h));
    }
}
