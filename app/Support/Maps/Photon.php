<?php

namespace App\Support\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Place search through Photon (Komoot's OpenStreetMap geocoder), asked from
 * the server so the app is named in the request -- as OpenStreetMap services
 * ask -- and every answer is kept: the second keystroke of a word usually
 * asks again what the first did, and "what is at this point" barely changes.
 *
 * Photon is built for search-as-you-type, which Nominatim's policy forbids.
 */
class Photon
{
    public const ENDPOINT = 'https://photon.komoot.io';

    /** A search a day old is the same search: places move on OpenStreetMap's clock. */
    public const SEARCH_TTL = 60 * 60 * 24;

    /** What is at a point changes even more rarely. */
    public const REVERSE_TTL = 60 * 60 * 24 * 30;

    /**
     * Places matching what has been typed, nearest the point first -- or,
     * with no point, the best known first.
     *
     * @return list<array{name: string, address: string, label: string, lat: float, lng: float, kind: string}>
     */
    public static function search(string $query, ?float $lat, ?float $lng, int $limit = 6): array
    {
        $near = $lat !== null && $lng !== null;
        // Rounded to about a kilometre: panning a little shouldn't cost a new search
        $key = 'maps:photon:search:'.sha1(mb_strtolower(trim($query)).($near ? sprintf('|%.2f|%.2f', $lat, $lng) : '|anywhere')."|{$limit}");

        return Cache::remember($key, self::SEARCH_TTL, fn () => array_values(array_map(
            self::hit(...),
            self::ask('/api/', ['q' => $query, 'limit' => $limit, ...($near ? ['lat' => $lat, 'lon' => $lng] : [])])['features'] ?? [],
        )));
    }

    /**
     * The nearest named place or address to a point, or null.
     *
     * @return array{name: string, address: string, label: string, lat: float, lng: float, kind: string}|null
     */
    public static function reverse(float $lat, float $lng): ?array
    {
        // About a metre: the same pin dropped twice is one question
        $key = 'maps:photon:reverse:'.sprintf('%.5f|%.5f', $lat, $lng);

        return Cache::remember($key, self::REVERSE_TTL, function () use ($lat, $lng) {
            $feature = self::ask('/reverse', ['lat' => $lat, 'lon' => $lng, 'limit' => 1])['features'][0] ?? null;

            return is_array($feature) ? self::hit($feature) : null;
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private static function ask(string $path, array $query): array
    {
        $response = Http::timeout(10)
            ->withUserAgent(self::userAgent())
            ->get(self::ENDPOINT.$path, $query);

        if ($response->failed()) {
            throw new RuntimeException("Place search answered {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    /** OpenStreetMap services ask to be told which app is asking. */
    public static function userAgent(): string
    {
        return 'ZyrenN-Maps/1.0 (+'.config('app.url').')';
    }

    /**
     * A Photon feature as the map reads it: what it's called, and the rest of
     * where it is, each part once.
     *
     * @param  array<string, mixed>  $feature
     * @return array{name: string, address: string, label: string, lat: float, lng: float, kind: string}
     */
    private static function hit(array $feature): array
    {
        $p = $feature['properties'] ?? [];
        $street = trim(($p['housenumber'] ?? '').' '.($p['street'] ?? ''));
        $parts = array_values(array_unique(array_filter([
            $street,
            $p['locality'] ?? $p['district'] ?? null,
            $p['city'] ?? $p['county'] ?? null,
            $p['state'] ?? null,
            $p['country'] ?? null,
        ])));
        $name = $p['name'] ?? array_shift($parts) ?? 'Dropped pin';

        return [
            'name' => (string) $name,
            'address' => implode(', ', $parts),
            'label' => implode(', ', [$name, ...$parts]),
            'lat' => (float) ($feature['geometry']['coordinates'][1] ?? 0),
            'lng' => (float) ($feature['geometry']['coordinates'][0] ?? 0),
            'kind' => ($p['osm_key'] ?? '').'/'.($p['osm_value'] ?? ''),
        ];
    }
}
