<?php

namespace App\Support\Board;

use App\Models\Board\BoardShape;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shapes of one's own (BoardShape): the items picked on a board kept as one
 * shape, set at the top left of their own box -- and put on a board again,
 * as one group, each item made new and every line among them joined to the
 * new copies. The canvas does the same sums when it places one
 * (boards/model/myShapes.ts), so a shape lands the same whichever puts it.
 */
final class BoardShapes
{
    /** The most items one shape may be made of. */
    public const MOST_ITEMS = 300;

    /** How large its items may be, all told, as JSON: pictures go by address, not inline. */
    private const MOST_BYTES = 1_000_000;

    /**
     * What is kept of the items picked: copies, moved so the shape's box
     * starts at 0,0; lines that end on something left out end where they did.
     *
     * @param  array<mixed>  $items  as sent: anything that is not an item is left out
     * @return array{items: list<array<string, mixed>>, width: int, height: int}
     */
    public static function fromItems(array $items): array
    {
        $items = array_values(array_filter($items, fn ($item) => is_array($item) && is_string($item['kind'] ?? null)));

        if ($items === [] || count($items) > self::MOST_ITEMS) {
            throw ValidationException::withMessages(['items' => $items === []
                ? 'Pick what the shape is made of first.'
                : 'A shape is made of at most '.self::MOST_ITEMS.' things.']);
        }

        if (strlen((string) json_encode($items)) > self::MOST_BYTES) {
            throw ValidationException::withMessages(['items' => 'That is too much for one shape: leave out a large picture.']);
        }

        $ids = array_flip(array_filter(array_map(fn (array $item) => $item['id'] ?? null, $items), 'is_string'));
        $box = self::box($items);

        $kept = array_map(function (array $item) use ($box, $ids) {
            // The shape is its own group once it is placed
            unset($item['group']);

            if ($item['kind'] === 'arrow') {
                foreach (['from', 'to'] as $end) {
                    if (is_array($item[$end] ?? null)) {
                        $item[$end] = self::moved($item[$end], -$box['x'], -$box['y']);

                        // On something not in the shape: where it was, on nothing
                        if (! isset($ids[$item[$end]['item'] ?? ''])) {
                            $item[$end]['item'] = null;
                            unset($item[$end]['side'], $item[$end]['pin'], $item[$end]['along']);
                        }
                    }
                }

                return $item;
            }

            return [...$item, 'x' => round((float) ($item['x'] ?? 0) - $box['x'], 2), 'y' => round((float) ($item['y'] ?? 0) - $box['y'], 2)];
        }, $items);

        return ['items' => $kept, 'width' => max(1, (int) ceil($box['width'])), 'height' => max(1, (int) ceil($box['height']))];
    }

    /**
     * The shape's items to put on a board: each with an id the board has not
     * got (`taken`), all in one new group, the shape's top left at `x`,`y`
     * -- and, given a `width`, made that wide, its height in step.
     *
     * @param  list<string>  $taken
     * @return list<array<string, mixed>>
     */
    public static function placed(BoardShape $shape, array $taken, float $x, float $y, ?float $width = null): array
    {
        $scale = $width !== null && $width > 0 ? $width / max(1, $shape->width) : 1.0;
        $group = 'g'.Str::lower(Str::random(10));

        $fresh = BoardItems::withIds(array_map(fn (array $item) => [...$item, 'id' => null], $shape->items), $taken);
        $ids = [];

        foreach ($shape->items as $at => $item) {
            if (is_string($item['id'] ?? null)) {
                $ids[$item['id']] = $fresh[$at]['id'];
            }
        }

        return array_map(function (array $item) use ($ids, $scale, $group, $x, $y) {
            $item['group'] = $group;

            if (($item['kind'] ?? '') === 'arrow') {
                foreach (['from', 'to'] as $end) {
                    if (is_array($item[$end] ?? null)) {
                        $item[$end] = self::moved(self::scaled($item[$end], $scale), $x, $y);
                        $item[$end]['item'] = isset($ids[$item[$end]['item'] ?? '']) ? $ids[$item[$end]['item']] : null;
                    }
                }

                return $item;
            }

            $item = self::scaled($item, $scale);

            return [...$item, 'x' => round((float) $item['x'] + $x, 2), 'y' => round((float) $item['y'] + $y, 2)];
        }, $fresh);
    }

    /**
     * The box round the items, lines' loose ends and all.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{x: float, y: float, width: float, height: float}
     */
    private static function box(array $items): array
    {
        $xs = [];
        $ys = [];

        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'arrow') {
                foreach (['from', 'to'] as $end) {
                    if (is_array($item[$end] ?? null)) {
                        $xs[] = (float) ($item[$end]['x'] ?? 0);
                        $ys[] = (float) ($item[$end]['y'] ?? 0);
                    }
                }

                continue;
            }

            $xs[] = (float) ($item['x'] ?? 0);
            $ys[] = (float) ($item['y'] ?? 0);
            $xs[] = (float) ($item['x'] ?? 0) + (float) ($item['width'] ?? 0);
            $ys[] = (float) ($item['y'] ?? 0) + (float) ($item['height'] ?? 0);
        }

        // Only lines with no ends to go by: a box of nothing, where it is
        if ($xs === [] || $ys === []) {
            return ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0];
        }

        return ['x' => min($xs), 'y' => min($ys), 'width' => max($xs) - min($xs), 'height' => max($ys) - min($ys)];
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>
     */
    private static function moved(array $point, float $dx, float $dy): array
    {
        return [...$point, 'x' => round((float) ($point['x'] ?? 0) + $dx, 2), 'y' => round((float) ($point['y'] ?? 0) + $dy, 2)];
    }

    /**
     * An item, or a line's end, made `$scale` times as large: its place and
     * size, a freehand line's points, its words.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function scaled(array $item, float $scale): array
    {
        if ($scale === 1.0) {
            return $item;
        }

        foreach (['x', 'y', 'width', 'height'] as $field) {
            if (is_numeric($item[$field] ?? null)) {
                $item[$field] = round((float) $item[$field] * $scale, 2);
            }
        }

        if (is_array($item['points'] ?? null)) {
            $item['points'] = array_map(fn ($value) => round((float) $value * $scale, 2), $item['points']);
        }

        if (is_numeric($item['fontSize'] ?? null)) {
            $item['fontSize'] = max(6, round((float) $item['fontSize'] * $scale, 1));
        }

        return $item;
    }
}
