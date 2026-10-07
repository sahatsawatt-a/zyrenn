<?php

namespace App\Support\Links;

use App\Support\RemoteDownload;
use App\Support\RemoteDownloadFailed;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * What a link in a note points at, to show it as a card or offer it as a map:
 * the page's title, description, site, picture and icon from its <head>; or
 * the place a map link names; or that it is a picture or a video file.
 *
 * Fetched by the server through RemoteDownload, which reaches only the public
 * internet, and kept for a day. The pictures it finds are fetched here too and
 * served back from the app, so opening a note never sends the reader to some
 * other site -- and a page that blocks reading by others still has a card.
 */
class LinkPreview
{
    /** Enough of a page for its <head>; the rest is not read. */
    private const PAGE_BYTES = 512 * 1024;

    private const IMAGE_BYTES = 3 * 1024 * 1024;

    /** Pictures a card may show: raster only, never SVG, which can carry script. */
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/x-icon', 'image/vnd.microsoft.icon'];

    private const DISK = 'local';

    /**
     * @return array{url: string, kind: 'page'|'image'|'video'|'map', site: string, title: string|null, description: string|null, image: string|null, icon: string|null, place: array{name: string, lat: float, lng: float, zoom: float|null}|null}
     */
    public static function of(string $url): array
    {
        return Cache::remember('link-preview:'.sha1($url), now()->addDay(), fn () => self::read($url));
    }

    /**
     * @return array{url: string, kind: 'page'|'image'|'video'|'map', site: string, title: string|null, description: string|null, image: string|null, icon: string|null, place: array{name: string, lat: float, lng: float, zoom: float|null}|null}
     */
    private static function read(string $url): array
    {
        $preview = [
            'url' => $url,
            'kind' => 'page',
            'site' => self::siteOf($url),
            'title' => null,
            'description' => null,
            'image' => null,
            'icon' => null,
            'place' => MapLink::place($url),
        ];

        if ($preview['place'] === null && MapLink::isShort($url)) {
            try {
                [, $preview['url']] = RemoteDownload::start($url, 8);
                $preview['place'] = MapLink::place($preview['url']);
            } catch (RemoteDownloadFailed) {
                // It stays a link
            }
        }

        if ($preview['place'] !== null) {
            return [...$preview, 'kind' => 'map', 'title' => $preview['place']['name'] ?: null];
        }

        try {
            $page = RemoteDownload::peek($url, self::PAGE_BYTES);
        } catch (RemoteDownloadFailed) {
            return $preview;
        }

        $preview['url'] = $page['url'];
        $preview['site'] = self::siteOf($page['url']);

        if (str_starts_with($page['type'], 'image/')) {
            return [...$preview, 'kind' => 'image'];
        }

        if (str_starts_with($page['type'], 'video/')) {
            return [...$preview, 'kind' => 'video'];
        }

        if (! str_contains($page['type'], 'html')) {
            return $preview;
        }

        $head = self::head($page['body']);

        return [
            ...$preview,
            'title' => self::clean($head['og:title'] ?? $head['twitter:title'] ?? $head['title'] ?? null, 300),
            'description' => self::clean($head['og:description'] ?? $head['twitter:description'] ?? $head['description'] ?? null, 500),
            'site' => self::clean($head['og:site_name'] ?? null, 100) ?? $preview['site'],
            'image' => self::absolute($head['og:image'] ?? $head['twitter:image'] ?? null, $page['url']),
            'icon' => self::absolute($head['icon'] ?? '/favicon.ico', $page['url']),
        ];
    }

    /**
     * A picture from a page, fetched and kept here so the app can serve it:
     * its bytes and type, or null when it is not a picture a card may show.
     *
     * @return array{bytes: string, type: string}|null
     */
    public static function image(string $url): ?array
    {
        $key = 'link-previews/'.sha1($url);
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($key)) {
            [$type, $bytes] = explode("\n", (string) $disk->get($key), 2) + ['', ''];

            return $type === '' ? null : ['bytes' => $bytes, 'type' => $type];
        }

        try {
            // Briefly: a picture that doesn't come soon isn't worth holding the app for
            $fetched = RemoteDownload::peek($url, self::IMAGE_BYTES + 1, 4);
            $ok = in_array($fetched['type'], self::IMAGE_TYPES, true) && strlen($fetched['body']) <= self::IMAGE_BYTES;
        } catch (RemoteDownloadFailed) {
            $fetched = null;
            $ok = false;
        }

        // A miss is kept too, so a missing icon isn't asked for on every view
        $disk->put($key, $ok ? $fetched['type']."\n".$fetched['body'] : "\n");

        return $ok ? ['bytes' => $fetched['body'], 'type' => $fetched['type']] : null;
    }

    /**
     * The site's own icon: the one its home page names when that page has
     * been read already, or /favicon.ico. The home page isn't read for it --
     * a note full of links would have the server reading a page a link,
     * while the app waits on it.
     */
    public static function iconOf(string $host): ?string
    {
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
            return null;
        }

        $read = Cache::get('link-preview:'.sha1("https://{$host}/"));

        return $read['icon'] ?? "https://{$host}/favicon.ico";
    }

    /**
     * The title, the descriptions and the pictures a page's <head> gives,
     * by name: og:title, description, icon...
     *
     * @return array<string, string>
     */
    private static function head(string $html): array
    {
        $html = preg_replace('/<body\b.*/is', '', $html) ?? $html;
        $found = [];

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $title)) {
            $found['title'] = $title[1];
        }

        preg_match_all('/<meta\b[^>]*>/i', $html, $metas);

        foreach ($metas[0] as $meta) {
            $attributes = self::attributes($meta);
            $name = strtolower($attributes['property'] ?? $attributes['name'] ?? '');

            if ($name !== '' && isset($attributes['content']) && ! isset($found[$name])) {
                $found[$name] = $attributes['content'];
            }
        }

        preg_match_all('/<link\b[^>]*>/i', $html, $links);

        // The plain icon over the larger touch one: it is shown small
        foreach (['icon', 'shortcut icon', 'apple-touch-icon'] as $wanted) {
            foreach ($links[0] as $link) {
                $attributes = self::attributes($link);

                if (strtolower(trim($attributes['rel'] ?? '')) === $wanted && ! empty($attributes['href'])
                    && ! str_ends_with(strtolower((string) parse_url($attributes['href'], PHP_URL_PATH)), '.svg')) {
                    $found['icon'] ??= $attributes['href'];
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(string $tag): array
    {
        preg_match_all('/([a-z:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $pairs, PREG_SET_ORDER);
        $attributes = [];

        foreach ($pairs as $pair) {
            // Double-quoted, single-quoted or bare: whichever of the three it was
            $value = ($pair[2] ?? '') !== '' ? $pair[2] : (($pair[3] ?? '') !== '' ? $pair[3] : ($pair[4] ?? ''));
            $attributes[strtolower($pair[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        }

        return $attributes;
    }

    private static function clean(?string $text, int $length): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    /**
     * An address a page gives, made whole against the page's own -- and only
     * an http(s) one.
     */
    private static function absolute(?string $href, string $base): ?string
    {
        $href = trim((string) $href);

        if ($href === '' || str_starts_with($href, 'data:')) {
            return null;
        }

        $resolved = (string) UriResolver::resolve(new Uri($base), new Uri($href));

        return preg_match('#^https?://#i', $resolved) ? $resolved : null;
    }

    private static function siteOf(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }
}
