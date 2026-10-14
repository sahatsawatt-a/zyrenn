<?php

namespace App\Support\Board;

use Illuminate\Support\Str;

/**
 * What is inside a shape that has a structure: a table's columns, a class's
 * fields and methods.
 *
 * The canvas keeps them as rows on the item and draws them a row to a line
 * under a titled band (resources/js/features/boards/composables/rows.ts). The
 * sums here are the canvas's own, so a board an MCP client writes is as tall
 * as its rows need and its connectors hold on where the canvas would have put
 * them; tests keep the two to the same numbers.
 */
class BoardRows
{
    /** A column's keys: primary, foreign, unique. */
    public const KEYS = ['pk', 'fk', 'uk'];

    /** A member's visibility: public, private, protected, package. */
    public const VISIBILITIES = ['+', '-', '#', '~'];

    /** How many rows one shape may hold. */
    public const MAX = 80;

    private const FOOT = 6;

    private const SECTION = 8;

    /** Which kind of rows a shape holds -- "table" or "class" -- if it holds any. */
    public static function kindOf(string $kind): ?string
    {
        $rows = Shapes::find($kind)['rows'] ?? null;

        return is_string($rows) ? $rows : null;
    }

    /**
     * Rows as the canvas keeps them, from what a client wrote. A row keeps the
     * id it was given, or the id of the row already there under its name; a new
     * one is named by its own name, so a connector can say "customer_id".
     *
     * @param  array<mixed>  $rows
     * @param  array<mixed>  $existing  the rows the item has now
     * @return list<array{id: string, name: string, type: string, mark: string, nullable: bool, method: bool}>
     */
    public static function fromSpec(array $rows, string $kind, array $existing = []): array
    {
        $known = [];

        foreach ($existing as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $known[mb_strtolower(trim((string) ($row['name'] ?? '')))] = $row['id'];
            }
        }

        $made = [];
        $used = [];

        foreach (array_slice($rows, 0, self::MAX) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $mark = (string) ($row['mark'] ?? $row['key'] ?? $row['visibility'] ?? ($kind === 'class' ? '+' : ''));
            $mark = $kind === 'table' ? mb_strtolower($mark) : $mark;

            $id = is_string($row['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $row['id']) === 1
                ? $row['id']
                : ($known[mb_strtolower($name)] ?? (Str::slug($name, '_') ?: 'row'));

            // Two rows cannot answer to one id
            for ($base = $id, $n = 2; isset($used[$id]); $n++) {
                $id = "{$base}_{$n}";
            }

            $used[$id] = true;
            $made[] = [
                'id' => $id,
                'name' => mb_substr($name, 0, 120),
                'type' => mb_substr(trim((string) ($row['type'] ?? '')), 0, 80),
                'mark' => in_array($mark, $kind === 'table' ? self::KEYS : self::VISIBILITIES, true) ? $mark : '',
                'nullable' => $kind === 'table' && ($row['nullable'] ?? false) === true,
                // A method is one said to be, or one written with brackets
                'method' => $kind === 'class' && (($row['method'] ?? null) === true
                    || (! array_key_exists('method', $row) && preg_match('/\(.*\)/', $name) === 1)),
            ];
        }

        return $made;
    }

    /**
     * Rows in the short form a client reads: what is plain is left unsaid.
     *
     * @param  array<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    public static function toSpec(array $rows, string $kind): array
    {
        $specs = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $specs[] = array_filter([
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                ($kind === 'table' ? 'key' : 'visibility') => (string) ($row['mark'] ?? ''),
                'nullable' => ($row['nullable'] ?? false) === true,
                'method' => ($row['method'] ?? false) === true,
            ], fn (mixed $value) => $value !== '' && $value !== false);
        }

        return $specs;
    }

    /**
     * How deep the titled band is: a line of the title, or as many as it has.
     *
     * @param  array<string, mixed>  $item
     */
    public static function headerDepth(array $item): float
    {
        $lines = max(1, count(explode("\n", (string) ($item['text'] ?? ''))));

        return round(max(30, ((float) ($item['fontSize'] ?? 13) + 1) * 1.3 * $lines + 14));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function rowDepth(array $item): float
    {
        return round((float) ($item['fontSize'] ?? 13) * 1.8);
    }

    /**
     * How tall the shape has to be to show its title and every row.
     *
     * @param  array<string, mixed>  $item
     */
    public static function height(array $item): float
    {
        $rows = self::rowsOf($item);
        $class = self::kindOf((string) ($item['kind'] ?? '')) === 'class';
        $methods = array_filter($rows, fn (array $row) => ($row['method'] ?? false) === true);
        // A class with no methods still shows the room they would go in
        $empty = $class && $methods === [] ? self::rowDepth($item) * 0.6 : 0;

        return round(self::headerDepth($item) + 2 + count($rows) * self::rowDepth($item) + ($class ? self::SECTION : 0) + $empty + self::FOOT);
    }

    /**
     * A row by what a connector's end calls it: its id, or failing that its name.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public static function named(array $item, string $pin): ?array
    {
        $rows = self::rowsOf($item);
        $wanted = mb_strtolower(trim($pin));

        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $pin) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if (mb_strtolower(trim((string) ($row['name'] ?? ''))) === $wanted) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Where a connector holds on to a row: at the row's height, on one edge.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $row
     * @return array{x: float, y: float}
     */
    public static function anchor(array $item, array $row, string $side): array
    {
        $class = self::kindOf((string) ($item['kind'] ?? '')) === 'class';
        $rows = self::rowsOf($item);

        // A class's fields come first, and its methods under a rule
        if ($class) {
            $rows = [
                ...array_filter($rows, fn (array $each) => ($each['method'] ?? false) !== true),
                ...array_filter($rows, fn (array $each) => ($each['method'] ?? false) === true),
            ];
        }

        $index = (int) array_search($row['id'] ?? null, array_column($rows, 'id'), true);
        $down = self::headerDepth($item) + 2 + $index * self::rowDepth($item)
            + ($class && ($row['method'] ?? false) === true ? self::SECTION : 0)
            + self::rowDepth($item) / 2;

        return [
            'x' => (float) $item['x'] + ($side === 'left' ? 0.0 : (float) $item['width']),
            'y' => (float) $item['y'] + min($down, (float) $item['height']),
        ];
    }

    /**
     * The names of an item's rows, for saying what a connector could have named.
     *
     * @param  array<string, mixed>  $item
     */
    public static function names(array $item): string
    {
        return implode(', ', array_column(self::rowsOf($item), 'name'));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(array $item): array
    {
        return array_values(array_filter(is_array($item['rows'] ?? null) ? $item['rows'] : [], 'is_array'));
    }
}
