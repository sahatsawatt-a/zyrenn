<?php

namespace App\Support;

/**
 * A board in parts, so MCP can read one frame of it, or all of it in outline,
 * rather than every item with every colour for one sticky note.
 *
 * What belongs to a frame is worked out as the canvas works it out for its
 * layers list (resources/js/composables/board/layers.ts): an item sits in the
 * smallest frame its middle falls inside, and a connector goes with what it
 * joins.
 */
final class BoardParts
{
    /** What no frame holds is filed under this, as the canvas files it. */
    public const LOOSE = 'board';

    /**
     * Which frame each item belongs to, by id: a frame's id, or "board".
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    public static function homes(array $items): array
    {
        $frames = array_values(array_filter($items, fn (array $item) => ($item['kind'] ?? '') === 'frame'));
        $homes = [];

        foreach ($items as $item) {
            $kind = $item['kind'] ?? '';

            if ($kind === 'frame' || $kind === BoardItems::CONNECTOR) {
                continue;
            }

            $holding = array_filter($frames, fn (array $frame) => self::sitsIn($item, $frame));
            usort($holding, fn (array $a, array $b) => ($a['width'] * $a['height']) <=> ($b['width'] * $b['height']));

            $homes[(string) $item['id']] = isset($holding[0]) ? (string) $holding[0]['id'] : self::LOOSE;
        }

        foreach ($items as $item) {
            if (($item['kind'] ?? '') === BoardItems::CONNECTOR) {
                $homes[(string) $item['id']] = $homes[(string) ($item['from']['item'] ?? '')]
                    ?? $homes[(string) ($item['to']['item'] ?? '')]
                    ?? self::LOOSE;
            }
        }

        return $homes;
    }

    /**
     * The board's frames, each with how many things sit on it.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{id: string, title: string, items: int}>
     */
    public static function frames(array $items): array
    {
        $counts = array_count_values(self::homes($items));

        return array_values(array_map(
            fn (array $frame) => [
                'id' => (string) $frame['id'],
                'title' => (string) ($frame['text'] ?? ''),
                'items' => $counts[(string) $frame['id']] ?? 0,
            ],
            array_filter($items, fn (array $item) => ($item['kind'] ?? '') === 'frame'),
        ));
    }

    /**
     * The frame named by id or title (any case), or null.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    public static function frame(array $items, string $named): ?array
    {
        $frames = array_values(array_filter($items, fn (array $item) => ($item['kind'] ?? '') === 'frame'));

        foreach ($frames as $frame) {
            if ($frame['id'] === $named) {
                return $frame;
            }
        }

        foreach ($frames as $frame) {
            if (mb_strtolower(trim((string) ($frame['text'] ?? ''))) === mb_strtolower(trim($named))) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * A frame and everything on it.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function inFrame(array $items, string $frame): array
    {
        $homes = self::homes($items);

        return array_values(array_filter(
            $items,
            fn (array $item) => $item['id'] === $frame || ($homes[(string) $item['id']] ?? null) === $frame,
        ));
    }

    /**
     * Items in outline: what each is, what it says, where, and what a
     * connector joins -- without colours, line styles or the points of ink.
     *
     * @param  list<array<string, mixed>>  $items  as the canvas keeps them
     * @return list<array<string, mixed>>
     */
    public static function outline(array $items): array
    {
        $homes = self::homes($items);

        return array_map(function (array $item) use ($homes) {
            $connector = ($item['kind'] ?? '') === BoardItems::CONNECTOR;
            $home = $homes[(string) $item['id']] ?? self::LOOSE;

            return array_filter([
                'id' => (string) $item['id'],
                'kind' => (string) ($item['kind'] ?? ''),
                'text' => trim((string) ($item['text'] ?? '')) !== '' ? mb_strimwidth((string) $item['text'], 0, 80, '…') : null,
                'x' => $connector ? null : (int) round((float) ($item['x'] ?? 0)),
                'y' => $connector ? null : (int) round((float) ($item['y'] ?? 0)),
                'width' => $connector ? null : (int) round((float) ($item['width'] ?? 0)),
                'height' => $connector ? null : (int) round((float) ($item['height'] ?? 0)),
                'from' => $connector ? ($item['from']['item'] ?? null) : null,
                'to' => $connector ? ($item['to']['item'] ?? null) : null,
                'frame' => $home !== self::LOOSE && ($item['kind'] ?? '') !== 'frame' ? $home : null,
            ], fn ($value) => $value !== null);
        }, $items);
    }

    /**
     * Whether the middle of an item falls inside a frame.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $frame
     */
    private static function sitsIn(array $item, array $frame): bool
    {
        $box = self::bounds($item);
        $x = $box['x'] + $box['width'] / 2;
        $y = $box['y'] + $box['height'] / 2;

        return $x >= $frame['x'] && $x <= $frame['x'] + $frame['width']
            && $y >= $frame['y'] && $y <= $frame['y'] + $frame['height'];
    }

    /**
     * The box an item takes up; ink by its points, which run from x and y.
     *
     * @param  array<string, mixed>  $item
     * @return array{x: float, y: float, width: float, height: float}
     */
    private static function bounds(array $item): array
    {
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);
        $points = is_array($item['points'] ?? null) ? array_map('floatval', array_values($item['points'])) : [];
        $xs = array_values(array_filter($points, fn ($key) => $key % 2 === 0, ARRAY_FILTER_USE_KEY));
        $ys = array_values(array_filter($points, fn ($key) => $key % 2 === 1, ARRAY_FILTER_USE_KEY));

        if (($item['kind'] ?? '') !== 'draw' || $xs === [] || $ys === []) {
            return ['x' => $x, 'y' => $y, 'width' => (float) ($item['width'] ?? 0), 'height' => (float) ($item['height'] ?? 0)];
        }

        return [
            'x' => $x + min($xs),
            'y' => $y + min($ys),
            'width' => max($xs) - min($xs),
            'height' => max($ys) - min($ys),
        ];
    }
}
