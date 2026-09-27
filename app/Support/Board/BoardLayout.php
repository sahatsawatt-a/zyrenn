<?php

namespace App\Support\Board;

/**
 * Where things go on a board when a client sends them without coordinates.
 *
 * Items a connector joins are laid out as a flow, rank by rank down the board
 * -- what is drawn from nothing else first, then what leads off it -- so a
 * flowchart reads top to bottom instead of running its branches through the
 * shapes beside them. Anything no connector touches is put in rows underneath.
 */
class BoardLayout
{
    /** The kind that is a line rather than a box. */
    private const CONNECTOR = 'arrow';

    /** How far apart things are spaced, and where the rows wrap. */
    private const GAP = 40;

    private const ROW_WIDTH = 1200;

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
    public static function place(array $items, array $edges): array
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
}
