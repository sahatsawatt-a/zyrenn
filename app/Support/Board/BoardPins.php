<?php

namespace App\Support\Board;

/**
 * Where a connector's ends sit.
 *
 * An end given a face keeps it however the shapes move; an end without one is
 * put on the face that looks at the other end, which is what the canvas would
 * have drawn had somebody dragged the line there by hand.
 */
class BoardPins
{
    /** The faces a connector can leave by. */
    public const SIDES = ['top', 'right', 'bottom', 'left'];

    /**
     * Pins a connector's ends. An end that names a shape is put on the edge of
     * it that faces the other end, which is what the canvas would have drawn
     * had someone dragged the connector there by hand.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $spec
     * @param  array<string, array<string, mixed>>  $boxes
     * @return array<string, mixed>
     */
    public static function pin(array $item, array $spec, array $boxes): array
    {
        $ends = [];

        foreach (['from', 'to'] as $end) {
            // Nothing said about this end: keep the one already stored
            $ends[$end] = array_key_exists($end, $spec)
                ? self::readEnd($spec[$end], $boxes)
                : (is_array($item[$end] ?? null) ? $item[$end] : null);
        }

        foreach (['from' => 'to', 'to' => 'from'] as $end => $other) {
            $host = $ends[$end]['item'] ?? null;

            if ($host === null || ! isset($boxes[$host])) {
                continue;
            }

            $facing = self::centreOf($boxes[$ends[$other]['item'] ?? ''] ?? null)
                ?? ['x' => $ends[$other]['x'] ?? 0.0, 'y' => $ends[$other]['y'] ?? 0.0];

            $chosen = in_array($ends[$end]['side'] ?? null, self::SIDES, true)
                ? $ends[$end]['side']
                : null;

            // Without a side of its own the end follows the shapes, turning to
            // face whatever is at the other end; the point is worked out the
            // same way, so a line never leaves one face while turning as
            // though it left another.
            $side = $chosen ?? self::sideFacing($boxes[$host], $facing);

            $ends[$end] = ['item' => $host, 'side' => $chosen, ...self::anchorAt($boxes[$host], $side)];
        }

        return [...$item, 'from' => $ends['from'], 'to' => $ends['to']];
    }

    /**
     * One end as a client wrote it: a shape's id, {item, side}, or {x, y}.
     *
     * @param  array<string, array<string, mixed>>  $boxes
     * @return array<string, mixed>|null
     */
    private static function readEnd(mixed $end, array $boxes): ?array
    {
        if (is_string($end) && $end !== '') {
            return ['item' => $end, 'side' => null, 'x' => 0.0, 'y' => 0.0];
        }

        if (! is_array($end)) {
            return null;
        }

        $item = is_string($end['item'] ?? null) && $end['item'] !== '' ? $end['item'] : null;

        return [
            'item' => $item,
            'side' => in_array($end['side'] ?? null, self::SIDES, true) ? $end['side'] : null,
            'x' => is_numeric($end['x'] ?? null) ? (float) $end['x'] : 0.0,
            'y' => is_numeric($end['y'] ?? null) ? (float) $end['y'] : 0.0,
        ];
    }

    /**
     * One end as a client reads it back.
     *
     * @return array<string, mixed>|null
     */
    public static function endpointSpec(mixed $end): ?array
    {
        if (! is_array($end)) {
            return null;
        }

        return is_string($end['item'] ?? null)
            ? ['item' => $end['item'], 'side' => $end['side'] ?? null]
            : ['x' => self::round($end['x'] ?? 0), 'y' => self::round($end['y'] ?? 0)];
    }

    /** The item id an end names, if it names one. */
    public static function referencedItem(mixed $end): ?string
    {
        if (is_string($end)) {
            return $end === '' ? null : $end;
        }

        return is_array($end) && is_string($end['item'] ?? null) && $end['item'] !== '' ? $end['item'] : null;
    }

    /**
     * @param  array<string, mixed>|null  $box
     * @return array{x: float, y: float}|null
     */
    private static function centreOf(?array $box): ?array
    {
        if ($box === null) {
            return null;
        }

        return [
            'x' => (float) $box['x'] + (float) $box['width'] / 2,
            'y' => (float) $box['y'] + (float) $box['height'] / 2,
        ];
    }

    /**
     * The side of a box that faces a point. Wide boxes lean towards their long
     * edges, which is how the canvas picks a side too.
     *
     * @param  array<string, mixed>  $box
     * @param  array{x: float, y: float}  $point
     */
    private static function sideFacing(array $box, array $point): string
    {
        $centre = self::centreOf($box) ?? ['x' => 0.0, 'y' => 0.0];
        $dx = $point['x'] - $centre['x'];
        $dy = $point['y'] - $centre['y'];

        $height = max((float) $box['height'], 1.0);
        $width = max((float) $box['width'], 1.0);

        if (abs($dx) * $height >= abs($dy) * $width) {
            return $dx >= 0 ? 'right' : 'left';
        }

        return $dy >= 0 ? 'bottom' : 'top';
    }

    /**
     * The middle of one of a box's edges.
     *
     * @param  array<string, mixed>  $box
     * @return array{x: float, y: float}
     */
    private static function anchorAt(array $box, string $side): array
    {
        $x = (float) $box['x'];
        $y = (float) $box['y'];
        $width = (float) $box['width'];
        $height = (float) $box['height'];

        return match ($side) {
            'top' => ['x' => $x + $width / 2, 'y' => $y],
            'bottom' => ['x' => $x + $width / 2, 'y' => $y + $height],
            'left' => ['x' => $x, 'y' => $y + $height / 2],
            default => ['x' => $x + $width, 'y' => $y + $height / 2],
        };
    }

    /** Coordinates are reported whole: nothing on a board needs a half pixel. */
    private static function round(mixed $number): float
    {
        return round((float) (is_numeric($number) ? $number : 0), 0);
    }
}
