<?php

namespace App\Support\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenFreeMap's map styles, trimmed to load faster and served from the app.
 *
 * Measured on the demo: the stock style is a chain of requests (the style,
 * then the TileJSON it names, then tiles) plus sixteen shaded-relief rasters
 * and a third font family -- 43 requests before a map is drawn. Trimmed, it is
 * 20, and the style itself comes from here, on the page's own origin.
 *
 * The tile address in OpenFreeMap's TileJSON carries a weekly version
 * ("planet/20260927_080001_pt") and old ones are retired, so a style can't be
 * written once and shipped: it is rebuilt from theirs once a day. If they
 * can't be reached, the last one built is used -- a day-old map beats none.
 */
class MapStyle
{
    public const SOURCE = 'https://tiles.openfreemap.org';

    /** The styles the app offers, by our name and theirs. */
    public const NAMES = ['liberty' => 'liberty', 'positron' => 'positron', 'dark' => 'dark'];

    public const TTL = 60 * 60 * 24;

    /**
     * The trimmed style, ready for MapLibre.
     *
     * @return array<string, mixed>
     */
    public static function get(string $name): array
    {
        $theirs = self::NAMES[$name] ?? throw new RuntimeException("No map style called \"{$name}\".");
        $fresh = "maps:style:{$name}";
        $kept = "maps:style:{$name}:last";

        if (($style = Cache::get($fresh)) !== null) {
            return $style;
        }

        try {
            $style = self::build($theirs);
        } catch (RuntimeException $failure) {
            return Cache::get($kept) ?? throw $failure;
        }

        Cache::put($fresh, $style, self::TTL);
        Cache::forever($kept, $style);

        return $style;
    }

    /**
     * Their style, with the tiles' address written in (no TileJSON to fetch
     * first), the shaded relief left out, and italic labels set upright so a
     * whole font family needn't be downloaded.
     *
     * @return array<string, mixed>
     */
    private static function build(string $theirs): array
    {
        $style = self::fetch(self::SOURCE."/styles/{$theirs}");
        $sources = $style['sources'] ?? [];

        foreach ($sources as $id => $source) {
            if (($source['type'] ?? null) === 'raster' && str_contains((string) json_encode($source['tiles'] ?? ''), 'natural_earth')) {
                unset($sources[$id]);
            } elseif (($source['type'] ?? null) === 'vector' && isset($source['url'])) {
                $tileJson = self::fetch($source['url']);
                $sources[$id] = array_filter([
                    'type' => 'vector',
                    'tiles' => $tileJson['tiles'] ?? throw new RuntimeException('OpenFreeMap gave no tile address.'),
                    'minzoom' => $tileJson['minzoom'] ?? 0,
                    'maxzoom' => $tileJson['maxzoom'] ?? 14,
                    'attribution' => $tileJson['attribution'] ?? null,
                ], fn ($value) => $value !== null);
            }
        }

        $style['sources'] = $sources;
        $style['layers'] = array_values(array_map(function (array $layer) {
            if (isset($layer['layout']['text-font']) && is_array($layer['layout']['text-font'])) {
                $layer['layout']['text-font'] = array_map(
                    fn ($font) => $font === 'Noto Sans Italic' ? 'Noto Sans Regular' : $font,
                    $layer['layout']['text-font'],
                );
            }

            if (isset($layer['filter'])) {
                $layer['filter'] = self::onlyWhereSet($layer['filter']);
            }

            return $layer;
        }, array_filter(
            $style['layers'] ?? [],
            fn (array $layer) => isset($sources[$layer['source'] ?? '']) || ! isset($layer['source']),
        )));

        return $style;
    }

    /**
     * A comparison of a number some features lack, e.g. a road shield's
     * ["<=", ["get", "ref_length"], 6], asked only of features that have
     * it. One without was no match already, but MapLibre warns of every such
     * road as it draws it.
     */
    private static function onlyWhereSet(mixed $expression): mixed
    {
        if (! is_array($expression) || ! array_is_list($expression)) {
            return $expression;
        }

        $expression = array_map(self::onlyWhereSet(...), $expression);

        if (in_array($expression[0] ?? null, ['<', '<=', '>', '>='], true)) {
            foreach ([$expression[1] ?? null, $expression[2] ?? null] as $operand) {
                if (is_array($operand) && ($operand[0] ?? null) === 'get' && count($operand) === 2 && is_string($operand[1])) {
                    // Asked only of features that have it
                    return ['all', ['has', $operand[1]], $expression];
                }
            }
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetch(string $url): array
    {
        $response = Http::timeout(10)->withUserAgent(Photon::userAgent())->get($url);

        if ($response->failed() || ! is_array($response->json())) {
            throw new RuntimeException("OpenFreeMap answered {$response->status()} for {$url}.");
        }

        return $response->json();
    }
}
