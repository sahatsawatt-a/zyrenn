<?php

namespace App\Support;

/**
 * Translates between the board canvas's items and the short form MCP clients
 * read and write.
 *
 * A canvas item (resources/js/components/Board/board.ts) carries twenty-odd
 * fields, most of which matter to one kind of shape only. A client sends the
 * few that say what it wants -- a kind, a label, maybe a box -- and this fills
 * the rest in with the defaults the toolbar uses, lays out whatever arrived
 * without coordinates, and pins each connector to the edge of the shape it
 * names, facing the shape at its other end.
 */
class BoardItems
{
    public const CONNECTOR = 'arrow';

    /** Every kind the canvas can draw. */
    public const KINDS = [
        'frame', 'sticky', 'text', 'rect', 'pill', 'ellipse', 'triangle',
        'diamond', 'hexagon', 'star', 'cylinder', 'parallelogram', 'document',
        'process', 'cloud', 'image', 'math', self::CONNECTOR, 'draw',
    ];

    /** Kinds a connector may pin itself to. */
    public const CONNECTABLE = [
        'sticky', 'text', 'rect', 'pill', 'ellipse', 'triangle', 'diamond',
        'hexagon', 'star', 'cylinder', 'parallelogram', 'document', 'process',
        'cloud', 'image', 'math',
    ];

    public const SIDES = ['top', 'right', 'bottom', 'left'];

    public const ROUTINGS = ['elbow', 'straight', 'curved'];

    public const LINE_STYLES = ['solid', 'dashed', 'dotted'];

    public const HEADS = ['none', 'arrow', 'open', 'circle', 'diamond', 'bar'];

    /** Where a label sits in whatever it is written on. */
    public const ALIGNS = ['left', 'center', 'right'];

    public const VERTICAL_ALIGNS = ['top', 'middle', 'bottom'];

    /** Sticky notes are dealt out of this pack, as they are on the canvas. */
    private const STICKY_COLOURS = ['#fde68a', '#bbf7d0', '#bfdbfe', '#fbcfe8', '#ddd6fe'];

    /** Where an automatic layout starts, how far apart it spaces things, and where it wraps. */
    private const GAP = 40;

    private const ROW_WIDTH = 1200;

    /**
     * The canvas items for a client's list.
     *
     * An item whose id is already on the board keeps every field the client
     * does not mention -- its ink, its colours, the picture it holds -- so a
     * client can read a board, change one label and send the list back without
     * flattening the rest of it. Items left out of the list are gone.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  list<mixed>  $current  the board's items as they came out of the database
     * @return list<array<string, mixed>>
     */
    public static function fromSpec(array $specs, array $current = []): array
    {
        $existing = [];

        foreach ($current as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $existing[$item['id']] = $item;
            }
        }

        $items = [];
        $index = 0;

        foreach ($specs as $spec) {
            $kind = is_string($spec['kind'] ?? null) ? $spec['kind'] : 'sticky';
            $id = is_string($spec['id'] ?? null) && $spec['id'] !== '' ? $spec['id'] : 'i'.($index + 1);

            $item = isset($existing[$id])
                ? [...self::blank($kind, $index), ...$existing[$id]]
                : self::blank($kind, $index);

            $item['id'] = $id;
            $item['kind'] = $kind;

            foreach (['x', 'y', 'width', 'height', 'rotation', 'fontSize', 'lineWidth', 'headSize'] as $number) {
                if (is_numeric($spec[$number] ?? null)) {
                    $item[$number] = (float) $spec[$number];
                }
            }

            foreach (['text', 'fill', 'stroke', 'src', 'align', 'verticalAlign', 'routing', 'lineStyle', 'startHead', 'endHead'] as $string) {
                if (is_string($spec[$string] ?? null)) {
                    $item[$string] = $spec[$string];
                }
            }

            foreach (['hidden', 'locked'] as $flag) {
                if (is_bool($spec[$flag] ?? null)) {
                    $item[$flag] = $spec[$flag];
                }
            }

            // Coordinates are missing far more often than not: a client says
            // what goes on the board and leaves the arranging to us
            $item['placed'] = array_key_exists('x', $spec) && array_key_exists('y', $spec);

            $items[] = $item;
            $index++;
        }

        $edges = [];

        foreach ($specs as $position => $spec) {
            if (($spec['kind'] ?? null) !== self::CONNECTOR) {
                continue;
            }

            $from = self::referencedItem($spec['from'] ?? null);
            $to = self::referencedItem($spec['to'] ?? null);

            if ($from !== null && $to !== null) {
                $edges[] = [$from, $to];
            }
        }

        $items = self::layout($items, $edges);
        $boxes = array_column($items, null, 'id');

        foreach ($items as $position => $item) {
            if ($item['kind'] === self::CONNECTOR) {
                $items[$position] = self::pin($item, $specs[$position], $boxes);
            }
        }

        return array_map(function (array $item) {
            unset($item['placed']);

            return $item;
        }, $items);
    }

    /**
     * The board's items in the short form, small enough to read and safe to
     * send back: a field left out keeps whatever the canvas has stored.
     *
     * @param  array<string, mixed>|null  $content
     * @return list<array<string, mixed>>
     */
    public static function toSpec(?array $content): array
    {
        $items = is_array($content['items'] ?? null) ? $content['items'] : [];
        $specs = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $kind = (string) ($item['kind'] ?? '');
            $connector = $kind === self::CONNECTOR;

            $spec = [
                'id' => (string) ($item['id'] ?? ''),
                'kind' => $kind,
            ];

            if (! $connector) {
                $spec += [
                    'x' => self::round($item['x'] ?? 0),
                    'y' => self::round($item['y'] ?? 0),
                    'width' => self::round($item['width'] ?? 0),
                    'height' => self::round($item['height'] ?? 0),
                ];
            }

            if (trim((string) ($item['text'] ?? '')) !== '') {
                $spec['text'] = (string) $item['text'];
            }

            foreach (['fill', 'stroke'] as $colour) {
                $value = (string) ($item[$colour] ?? '');

                if ($value !== '' && $value !== 'transparent') {
                    $spec[$colour] = $value;
                }
            }

            foreach (['rotation' => 0, 'fontSize' => 16, 'lineWidth' => 2, 'headSize' => 10] as $number => $default) {
                if (isset($item[$number]) && self::round($item[$number]) !== (float) $default) {
                    $spec[$number] = self::round($item[$number]);
                }
            }

            foreach (['hidden', 'locked'] as $flag) {
                if (($item[$flag] ?? false) === true) {
                    $spec[$flag] = true;
                }
            }

            $default = self::blank($kind, 0);

            foreach (['align', 'verticalAlign'] as $placing) {
                if (isset($item[$placing]) && $item[$placing] !== $default[$placing]) {
                    $spec[$placing] = (string) $item[$placing];
                }
            }

            // A picture is a data URL of its own bytes, far too big to report.
            // Saying it is there is enough: send the item back without a "src"
            // and it keeps the picture it has.
            if (($item['src'] ?? '') !== '') {
                $src = (string) $item['src'];
                $spec += str_starts_with($src, 'data:') ? ['has_picture' => true] : ['src' => $src];
            }

            if ($connector) {
                $spec['from'] = self::endpointSpec($item['from'] ?? null);
                $spec['to'] = self::endpointSpec($item['to'] ?? null);

                foreach (['routing' => 'elbow', 'lineStyle' => 'solid', 'startHead' => 'none', 'endHead' => 'arrow'] as $field => $default) {
                    if (($item[$field] ?? $default) !== $default) {
                        $spec[$field] = (string) $item[$field];
                    }
                }
            }

            $specs[] = $spec;
        }

        return $specs;
    }

    /**
     * The ids that appear more than once in a client's list.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<string>
     */
    public static function duplicateIds(array $specs): array
    {
        $ids = array_filter(
            array_map(fn (array $spec) => is_string($spec['id'] ?? null) ? $spec['id'] : null, $specs),
            fn (?string $id) => $id !== null && $id !== '',
        );

        return array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));
    }

    /**
     * Every connector end that names an item the list does not hold, or holds
     * as something a connector cannot pin itself to.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<string>
     */
    public static function badReferences(array $specs): array
    {
        $kinds = [];

        foreach ($specs as $position => $spec) {
            $id = is_string($spec['id'] ?? null) && $spec['id'] !== '' ? $spec['id'] : 'i'.($position + 1);
            $kinds[$id] = is_string($spec['kind'] ?? null) ? $spec['kind'] : 'sticky';
        }

        $problems = [];

        foreach ($specs as $spec) {
            if (($spec['kind'] ?? null) !== self::CONNECTOR) {
                continue;
            }

            foreach (['from', 'to'] as $end) {
                $item = self::referencedItem($spec[$end] ?? null);

                if ($item === null) {
                    continue;
                }

                if (! array_key_exists($item, $kinds)) {
                    $problems[] = "\"{$end}\" points at \"{$item}\", which is not an item on this board.";
                } elseif (! in_array($kinds[$item], self::CONNECTABLE, true)) {
                    $problems[] = "\"{$end}\" points at \"{$item}\", which is a {$kinds[$item]}; connectors can only pin to a shape, a sticky, a text or a picture.";
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * A full canvas item of this kind, with nothing said about it yet.
     *
     * @return array<string, mixed>
     */
    private static function blank(string $kind, int $index): array
    {
        $base = [
            'id' => '',
            'kind' => $kind,
            'x' => 0.0,
            'y' => 0.0,
            'width' => 200.0,
            'height' => 140.0,
            'rotation' => 0.0,
            'fill' => '#ffffff',
            'stroke' => '#cbd5e1',
            'text' => '',
            'fontSize' => 16.0,
            'align' => 'center',
            'verticalAlign' => 'middle',
            'points' => [],
            'hidden' => false,
            'locked' => false,
            'src' => '',
            'from' => null,
            'to' => null,
            'routing' => 'elbow',
            'lineStyle' => 'solid',
            'lineWidth' => 2.0,
            'startHead' => 'none',
            'endHead' => 'arrow',
            'headSize' => 10.0,
        ];

        return match ($kind) {
            // 16:9, so a frame reads as a slide
            'frame' => [...$base, 'width' => 960.0, 'height' => 540.0, 'stroke' => '#94a3b8'],
            'sticky' => [...$base, 'width' => 180.0, 'height' => 180.0, 'stroke' => 'transparent',
                'fill' => self::STICKY_COLOURS[$index % count(self::STICKY_COLOURS)]],
            'text' => [...$base, 'width' => 260.0, 'height' => 40.0, 'fill' => 'transparent',
                'stroke' => 'transparent', 'fontSize' => 28.0, 'align' => 'left', 'verticalAlign' => 'top'],
            'ellipse' => [...$base, 'width' => 200.0, 'height' => 200.0],
            'star' => [...$base, 'width' => 180.0, 'height' => 180.0, 'fill' => '#fde68a'],
            'image' => [...$base, 'width' => 200.0, 'height' => 200.0, 'fill' => 'transparent', 'stroke' => 'transparent'],
            // A formula is its own picture: no box, no outline, just the maths
            'math' => [...$base, 'width' => 260.0, 'height' => 90.0, 'fill' => 'transparent',
                'stroke' => 'transparent', 'fontSize' => 24.0],
            'cylinder' => [...$base, 'width' => 180.0, 'height' => 200.0, 'fill' => '#e0e7ff', 'stroke' => '#6366f1'],
            self::CONNECTOR, 'draw' => [...$base, 'width' => 0.0, 'height' => 0.0, 'fill' => 'transparent', 'stroke' => '#0f172a'],
            default => $base,
        };
    }

    /**
     * Places everything that came without coordinates.
     *
     * Items a connector joins are laid out as a flow, rank by rank down the
     * board -- what is drawn from nothing else first, then what leads off it --
     * so a flowchart reads top to bottom instead of running its branches
     * through the shapes beside them. Anything no connector touches is put in
     * rows underneath. A client that sent its own x and y is left alone.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{0: string, 1: string}>  $edges
     * @return list<array<string, mixed>>
     */
    private static function layout(array $items, array $edges): array
    {
        $boxes = [];

        foreach ($items as $position => $item) {
            // A connector has no box of its own, and ink carries its own points
            if (! in_array($item['kind'], [self::CONNECTOR, 'draw'], true)) {
                $boxes[$item['id']] = $position;
            }
        }

        $edges = array_values(array_filter(
            $edges,
            fn (array $edge) => $edge[0] !== $edge[1] && isset($boxes[$edge[0]], $boxes[$edge[1]]),
        ));

        $ranks = self::ranks($boxes, $edges);
        $loose = array_diff_key($boxes, $ranks);

        [$items, $y] = self::placeFlow($items, $boxes, $ranks);

        return self::placeRows($items, $loose, $y);
    }

    /**
     * How far down the flow each connected item sits: one more than whatever
     * leads into it. Anything caught in a loop is put after the rest.
     *
     * @param  array<string, int>  $boxes  item id => its place in the list
     * @param  list<array{0: string, 1: string}>  $edges
     * @return array<string, int> item id => rank, for connected items only
     */
    private static function ranks(array $boxes, array $edges): array
    {
        if ($edges === []) {
            return [];
        }

        $connected = [];
        $out = [];
        $incoming = [];

        foreach ($edges as [$from, $to]) {
            $connected[$from] = true;
            $connected[$to] = true;
            $out[$from][] = $to;
            $incoming[$to] = ($incoming[$to] ?? 0) + 1;
        }

        $rank = array_fill_keys(array_keys($connected), 0);
        $queue = array_keys(array_filter(
            array_intersect_key($connected, $boxes),
            fn (string $id) => ($incoming[$id] ?? 0) === 0,
            ARRAY_FILTER_USE_KEY,
        ));

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ($out[$id] ?? [] as $next) {
                $rank[$next] = max($rank[$next], $rank[$id] + 1);

                if (--$incoming[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        // A loop has no rank of its own; it goes below everything that has one
        foreach ($incoming as $id => $left) {
            if ($left > 0) {
                $rank[$id] = max($rank) + 1;
            }
        }

        return $rank;
    }

    /**
     * Lays the ranks out down the board, each one centred on the last, and
     * says how far down the flow reached.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $boxes
     * @param  array<string, int>  $ranks
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private static function placeFlow(array $items, array $boxes, array $ranks): array
    {
        $y = 0.0;

        if ($ranks === []) {
            return [$items, $y];
        }

        $rows = [];

        foreach ($ranks as $id => $rank) {
            $rows[$rank][] = $id;
        }

        ksort($rows);

        foreach ($rows as $row) {
            $row = array_values(array_filter($row, fn (string $id) => ! $items[$boxes[$id]]['placed']));
            $widths = array_map(fn (string $id) => (float) $items[$boxes[$id]]['width'], $row);
            $height = 0.0;

            // Centred on the middle of the board, so the flow runs straight down
            $x = -(array_sum($widths) + self::GAP * max(count($row) - 1, 0)) / 2;

            foreach ($row as $id) {
                $items[$boxes[$id]]['x'] = $x;
                $items[$boxes[$id]]['y'] = $y;

                $x += (float) $items[$boxes[$id]]['width'] + self::GAP;
                $height = max($height, (float) $items[$boxes[$id]]['height']);
            }

            // Room between the ranks for the connectors to turn in
            $y += $height + self::GAP * 2;
        }

        return [$items, $y];
    }

    /**
     * Lays whatever is left out in rows, left to right, wrapping at the width
     * of a slide or so.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $loose  item id => its place in the list
     * @return list<array<string, mixed>>
     */
    private static function placeRows(array $items, array $loose, float $y): array
    {
        $x = 0.0;
        $rowHeight = 0.0;

        foreach ($loose as $position) {
            $item = $items[$position];

            if ($item['placed']) {
                continue;
            }

            if ($x > 0 && $x + $item['width'] > self::ROW_WIDTH) {
                $x = 0.0;
                $y += $rowHeight + self::GAP;
                $rowHeight = 0.0;
            }

            $items[$position]['x'] = $x;
            $items[$position]['y'] = $y;

            $x += (float) $item['width'] + self::GAP;
            $rowHeight = max($rowHeight, (float) $item['height']);

            // A frame is a slide, so it gets a row to itself
            if ($item['kind'] === 'frame') {
                $x = 0.0;
                $y += $rowHeight + self::GAP;
                $rowHeight = 0.0;
            }
        }

        return $items;
    }

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
    private static function pin(array $item, array $spec, array $boxes): array
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
    private static function endpointSpec(mixed $end): ?array
    {
        if (! is_array($end)) {
            return null;
        }

        return is_string($end['item'] ?? null)
            ? ['item' => $end['item'], 'side' => $end['side'] ?? null]
            : ['x' => self::round($end['x'] ?? 0), 'y' => self::round($end['y'] ?? 0)];
    }

    /** The item id an end names, if it names one. */
    private static function referencedItem(mixed $end): ?string
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
