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

        // Where an end that names a pin is: what the other end turns to face
        $named = [];

        foreach (['from', 'to'] as $end) {
            $host = $boxes[$ends[$end]['item'] ?? ''] ?? null;
            $symbol = ($host['kind'] ?? '') === BoardItems::SYMBOL ? Symbols::find((string) ($host['symbol'] ?? '')) : null;
            $pin = $symbol === null ? null : Symbols::pinName($symbol, (string) ($ends[$end]['pin'] ?? ''));

            if ($pin !== null) {
                $named[$end] = Symbols::pins($host)[$pin];
            }
        }

        foreach (['from' => 'to', 'to' => 'from'] as $end => $other) {
            $host = $ends[$end]['item'] ?? null;

            if ($host === null || ! isset($boxes[$host])) {
                continue;
            }

            $facing = $named[$other]
                ?? self::centreOf($boxes[$ends[$other]['item'] ?? ''] ?? null)
                ?? ['x' => $ends[$other]['x'] ?? 0.0, 'y' => $ends[$other]['y'] ?? 0.0];

            $chosen = in_array($ends[$end]['side'] ?? null, self::SIDES, true)
                ? $ends[$end]['side']
                : null;

            // On a symbol an end is on a pin: the one it names, or failing
            // that the one nearest the far end. Which pins a wire joins is
            // the circuit, so the pin is kept and never worked out again.
            $symbol = ($boxes[$host]['kind'] ?? '') === BoardItems::SYMBOL
                ? Symbols::find((string) ($boxes[$host]['symbol'] ?? ''))
                : null;

            if ($symbol !== null) {
                $pin = Symbols::pinName($symbol, (string) ($ends[$end]['pin'] ?? ''))
                    ?? Symbols::nearestPin($boxes[$host], $facing);

                if ($pin !== null) {
                    $ends[$end] = ['item' => $host, 'side' => $chosen, 'pin' => $pin, ...Symbols::pins($boxes[$host])[$pin]];

                    continue;
                }
            }

            // On a table's row, or part way down a lifeline: held there
            $held = self::held($ends[$end], $boxes[$host], $facing, $chosen);

            if ($held !== null) {
                $ends[$end] = ['item' => $host, ...$held];

                continue;
            }

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
     * Where an end is held on a shape with a structure of its own, as the
     * canvas holds it (connectors.ts, heldFor): on the row of a table or a
     * class it names, at that row's height, or as far along a lifeline as it
     * says. It leaves by whichever edge faces the far end, unless given one.
     *
     * @param  array<string, mixed>  $end
     * @param  array<string, mixed>  $host
     * @param  array{x: float, y: float}  $facing
     * @return array<string, mixed>|null
     */
    private static function held(array $end, array $host, array $facing, ?string $chosen): ?array
    {
        $edge = in_array($chosen, ['left', 'right'], true)
            ? $chosen
            : ($facing['x'] >= (float) $host['x'] + (float) $host['width'] / 2 ? 'right' : 'left');
        $kept = in_array($chosen, ['left', 'right'], true) ? $chosen : null;
        $kind = (string) ($host['kind'] ?? '');

        if (is_string($end['pin'] ?? null) && BoardRows::kindOf($kind) !== null) {
            $row = BoardRows::named($host, $end['pin']);

            return $row === null ? null : ['side' => $kept, 'pin' => $row['id'], ...BoardRows::anchor($host, $row, $edge)];
        }

        if (is_numeric($end['along'] ?? null) && (Shapes::find($kind)['slide'] ?? false) === true) {
            $along = max(0.0, min(1.0, (float) $end['along']));
            $inset = (float) (Shapes::find($kind)['insets'][$edge] ?? 0);

            return [
                'side' => $kept,
                'along' => $along,
                'x' => (float) $host['x'] + (float) $host['width'] * ($edge === 'left' ? $inset : 1 - $inset),
                'y' => (float) $host['y'] + (float) $host['height'] * $along,
            ];
        }

        return null;
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
            ...(is_string($end['pin'] ?? null) && $end['pin'] !== '' ? ['pin' => $end['pin']] : []),
            ...(is_numeric($end['along'] ?? null) ? ['along' => (float) $end['along']] : []),
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

        if (! is_string($end['item'] ?? null)) {
            return ['x' => self::round($end['x'] ?? 0), 'y' => self::round($end['y'] ?? 0)];
        }

        // Part way down a lifeline, that is what says where the end is
        if (is_numeric($end['along'] ?? null)) {
            return ['item' => $end['item'], 'along' => round((float) $end['along'], 3), ...(isset($end['side']) ? ['side' => $end['side']] : [])];
        }

        // On a symbol the pin is, and on a table or a class the row
        return is_string($end['pin'] ?? null)
            ? ['item' => $end['item'], 'pin' => $end['pin'], ...(isset($end['side']) ? ['side' => $end['side']] : [])]
            : ['item' => $end['item'], 'side' => $end['side'] ?? null];
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
