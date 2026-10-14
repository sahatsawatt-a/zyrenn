<?php

namespace App\Support\Board;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * A circuit written as text -- "R1: 10k", "V1 -- R1 -- D1 -- V1" -- laid out
 * as items for a board: the parts on the grid, and the wires between their
 * pins, which do not run over one another.
 *
 * The reading and the layout are the browser's own (resources/js/features/
 * boards/circuit), so a circuit comes out here as it does in a note. They are
 * TypeScript, run by Node: collab/circuit.mjs takes the text and answers with
 * the items.
 */
final class CircuitLayout
{
    /** The grid parts keep to (Symbols::grid), and the room left under what is on a board. */
    private const GRID = 20;

    private const GAP = 80;

    /**
     * The circuit as a list of items in the short form, ready to go on a
     * board with the rest (BoardItems::fromSpec, ::change). Each part's id is
     * its name -- "r1", "u2" -- unless that is taken.
     *
     * @param  list<string>  $taken  ids already on the board, or in the same call
     * @param  array{x: float|int, y: float|int}  $at  where its top left corner goes
     * @return list<array<string, mixed>>
     *
     * @throws BoardItemProblem when the text cannot be read, with what is wrong with it
     */
    public static function specs(string $text, array $taken = [], array $at = ['x' => 0, 'y' => 0]): array
    {
        $specs = BoardItems::toSpec(['items' => self::items($text)]);

        // Moved as one, by whole squares of the grid, so wires stay straight
        $placed = array_filter($specs, fn (array $spec) => isset($spec['x'], $spec['y']));
        $dx = $placed === [] ? 0.0 : self::snap($at['x'] - min(array_column($placed, 'x')));
        $dy = $placed === [] ? 0.0 : self::snap($at['y'] - min(array_column($placed, 'y')));

        $ids = self::ids($specs, $taken);

        return array_map(function (array $spec) use ($ids, $dx, $dy) {
            $spec['id'] = $ids[$spec['id']];

            if (isset($spec['x'], $spec['y'])) {
                $spec['x'] += $dx;
                $spec['y'] += $dy;
            }

            foreach (['from', 'to'] as $end) {
                if (is_array($spec[$end] ?? null) && isset($spec[$end]['item'])) {
                    $spec[$end]['item'] = $ids[$spec[$end]['item']] ?? $spec[$end]['item'];
                } elseif (is_array($spec[$end] ?? null) && isset($spec[$end]['x'], $spec[$end]['y'])) {
                    $spec[$end]['x'] += $dx;
                    $spec[$end]['y'] += $dy;
                }
            }

            return $spec;
        }, $specs);
    }

    /**
     * Where a circuit goes on a board that has things on it already: under
     * them all, in line with their left edge.
     *
     * @param  list<array<string, mixed>>  $current  the board's items as stored
     * @return array{x: float, y: float}
     */
    public static function under(array $current): array
    {
        $boxes = array_filter($current, fn (array $item) => ($item['kind'] ?? '') !== BoardItems::CONNECTOR && isset($item['x'], $item['y']));

        if ($boxes === []) {
            return ['x' => 0.0, 'y' => 0.0];
        }

        return [
            'x' => self::snap(min(array_column($boxes, 'x'))),
            'y' => self::snap(max(array_map(fn (array $item) => $item['y'] + ($item['height'] ?? 0), $boxes)) + self::GAP),
        ];
    }

    /**
     * The items as the canvas would make them, from Node.
     *
     * @return list<array<string, mixed>>
     */
    private static function items(string $text): array
    {
        try {
            $run = Process::path(base_path())
                ->timeout(20)
                ->input($text)
                ->run(['node', '--import', './collab/ts-loader.mjs', 'collab/circuit.mjs']);
        } catch (Throwable $problem) {
            report($problem);

            throw new BoardItemProblem('A circuit cannot be laid out here just now. Place its parts as items of kind "symbol" instead.');
        }

        $answer = json_decode($run->output(), true);

        if (! $run->successful() || ! is_array($answer)) {
            report(new \RuntimeException('Circuit layout failed: '.mb_substr($run->errorOutput(), 0, 2000)));

            throw new BoardItemProblem('A circuit cannot be laid out here just now. Place its parts as items of kind "symbol" instead.');
        }

        if (($answer['ok'] ?? false) !== true) {
            throw new BoardItemProblem('The circuit cannot be read. '.($answer['error'] ?? ''));
        }

        return array_values(array_filter($answer['items'] ?? [], 'is_array'));
    }

    /**
     * An id for each item: a part's own name, a wire's number.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  list<string>  $taken
     * @return array<string, string> by the id the layout gave
     */
    private static function ids(array $specs, array $taken): array
    {
        $taken = array_flip($taken);
        $ids = [];
        $wires = 0;

        foreach ($specs as $spec) {
            $named = strtok((string) ($spec['text'] ?? ''), "\n");
            $base = ($spec['kind'] ?? '') === BoardItems::SYMBOL && preg_match('/^[A-Za-z][A-Za-z0-9_+-]{0,15}$/', (string) $named)
                ? mb_strtolower((string) $named)
                : 'w'.(++$wires);

            for ($id = $base, $again = 2; isset($taken[$id]); $again++) {
                $id = $base.'-'.$again;
            }

            $taken[$id] = true;
            $ids[(string) $spec['id']] = $id;
        }

        return $ids;
    }

    private static function snap(float|int $number): float
    {
        return (float) (ceil($number / self::GRID) * self::GRID);
    }
}
