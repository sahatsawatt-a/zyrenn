<?php

namespace App\Support\Links;

/**
 * A place read out of a map link -- Google Maps, OpenStreetMap, Apple Maps or
 * a geo: URI -- so a note can show it as a map rather than as a link.
 *
 * Only what the link itself says is read: where it points, a name when the
 * link carries one, and how close in. A short link (maps.app.goo.gl) says
 * none of that until it is followed, which LinkPreview does first.
 */
class MapLink
{
    /** Hosts whose links are short ones, to follow before reading. */
    public const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl', 'g.co'];

    /**
     * Whether the URL is a short link to a map, which says nothing until followed.
     */
    public static function isShort(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host === 'maps.app.goo.gl'
            || ($host === 'goo.gl' && str_starts_with($path, '/maps'))
            || ($host === 'g.co' && str_starts_with($path, '/kgs'));
    }

    /**
     * The place a map link points at, or null when it is not a map link or
     * says nowhere in particular.
     *
     * @return array{name: string, lat: float, lng: float, zoom: float|null}|null
     */
    public static function place(string $url): ?array
    {
        if (preg_match('/^geo:(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i', $url, $geo)) {
            return self::at($geo[1], $geo[2], null, self::query($url)['q'] ?? '');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $query = self::query($url);
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);

        if (self::isGoogle($host, $path)) {
            $name = preg_match('#/place/([^/]+)#', $path, $place) ? str_replace('+', ' ', $place[1]) : '';
            // A place's own pin (!3d..!4d..) is where it is; the @ is only the view
            $zoom = preg_match('/@-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,(\d+(?:\.\d+)?)z/', $path, $view) ? $view[1] : null;

            if (preg_match('/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', $path, $pin)) {
                return self::at($pin[1], $pin[2], $zoom, $name);
            }

            foreach (['q', 'query', 'll', 'center', 'destination'] as $key) {
                if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $query[$key] ?? '', $pair)) {
                    return self::at($pair[1], $pair[2], $query['z'] ?? $zoom, $name);
                }
            }

            if (preg_match('/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $path, $at)) {
                return self::at($at[1], $at[2], $zoom, $name);
            }

            return null;
        }

        if (str_ends_with($host, 'openstreetmap.org')) {
            if (isset($query['mlat'], $query['mlon'])) {
                return self::at($query['mlat'], $query['mlon'], $query['zoom'] ?? null, '');
            }

            if (preg_match('#map=(\d+(?:\.\d+)?)/(-?\d+(?:\.\d+)?)/(-?\d+(?:\.\d+)?)#', $fragment, $map)) {
                return self::at($map[2], $map[3], $map[1], '');
            }

            return null;
        }

        if ($host === 'maps.apple.com') {
            foreach (['ll', 'sll', 'coordinate'] as $key) {
                if (preg_match('/^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/', $query[$key] ?? '', $pair)) {
                    return self::at($pair[1], $pair[2], $query['z'] ?? null, $query['q'] ?? $query['name'] ?? '');
                }
            }
        }

        return null;
    }

    private static function isGoogle(string $host, string $path): bool
    {
        return (bool) preg_match('/^(www\.)?google\.[a-z.]+$/', $host) && str_starts_with($path, '/maps')
            || (bool) preg_match('/^maps\.google\.[a-z.]+$/', $host);
    }

    /**
     * @return array<string, string>
     */
    private static function query(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if (str_starts_with(strtolower($url), 'geo:')) {
            $query = explode('?', $url, 2)[1] ?? '';
        }

        parse_str($query, $values);
        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $strings[(string) $key] = $value;
            }
        }

        return $strings;
    }

    /**
     * @return array{name: string, lat: float, lng: float, zoom: float|null}|null
     */
    private static function at(string $lat, string $lng, ?string $zoom, string $name): ?array
    {
        $lat = (float) $lat;
        $lng = (float) $lng;

        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return [
            'name' => mb_substr(trim($name), 0, 200),
            'lat' => $lat,
            'lng' => $lng,
            'zoom' => is_numeric($zoom) ? min(20.0, max(1.0, (float) $zoom)) : null,
        ];
    }
}
