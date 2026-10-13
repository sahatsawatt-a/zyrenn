<?php

namespace App\Support\Board;

/**
 * The electrical symbols a board can draw, and where their pins are.
 *
 * One list, resources/js/features/boards/circuit/symbols.json, is read by the
 * canvas (circuit/symbols.ts) and here, so a part an MCP client names is the
 * part the canvas draws, with the pins it has there. The sums are the
 * canvas's own: a symbol is turned about the corner it is drawn from, and a
 * pin is wherever that turn takes it.
 */
class Symbols
{
    /**
     * Every symbol in the library, by id: those drawn by hand, and the
     * boards and sensors made from their lists of pins.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        static $symbols;

        if ($symbols === null) {
            $library = json_decode(
                (string) file_get_contents(resource_path('js/features/boards/circuit/symbols.json')),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $symbols = array_column([
                ...$library['symbols'],
                ...array_map(fn (array $module) => self::module($module['id'], $module['left'], $module['right']), $library['modules']),
            ], null, 'id');
        }

        return $symbols;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /**
     * A symbol by its id. A chip with pins of its own is its id and nothing
     * more -- "chip:VCC,GND|OUT" has VCC and GND down its left and OUT on its
     * right -- so it is drawn up here, as the canvas draws it up.
     *
     * @return array<string, mixed>|null
     */
    public static function find(?string $id): ?array
    {
        $id ??= '';

        if (isset(self::all()[$id]) || ! str_starts_with($id, 'chip:')) {
            return self::all()[$id] ?? null;
        }

        if (! preg_match('/^chip:[A-Za-z0-9_+\-,]*(\|[A-Za-z0-9_+\-,]*)?$/', $id)) {
            return null;
        }

        [$left, $right] = [...explode('|', substr($id, 5), 2), ''];
        $names = fn (string $side) => array_values(array_filter(explode(',', $side), fn (string $name) => $name !== ''));
        $chip = self::module($id, $names($left), $names($right));

        return $chip['pins'] === [] ? null : $chip;
    }

    /**
     * A module as the canvas draws it (circuit/symbols.ts, moduleDef): a box
     * with a lead for every pin, 20 apart down each side. Only its size and
     * its pins are worked out here; the drawing is the canvas's to make.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return array<string, mixed>
     */
    private static function module(string $id, array $left, array $right): array
    {
        $grid = 20;
        $longest = fn (array $names) => max([0, ...array_map('strlen', $names)]);
        $body = (int) ceil(max(80, ($longest($left) + $longest($right)) * 6.2 + 28) / $grid) * $grid;
        $width = ($left === [] ? 0 : $grid) + $body + ($right === [] ? 0 : $grid);
        $pins = [];

        foreach (['left' => $left, 'right' => $right] as $side => $names) {
            foreach ($names as $row => $name) {
                $pins[] = [
                    'name' => $name,
                    'x' => $side === 'left' ? 0 : $width,
                    'y' => ($row + 1) * $grid,
                    'side' => $side,
                    'aliases' => self::alsoCalled($name),
                ];
            }
        }

        return [
            'id' => $id,
            'module' => true,
            'width' => $width,
            'height' => (max(count($left), count($right), 1) + 1) * $grid,
            'pins' => $pins,
        ];
    }

    /**
     * Other names a pin answers to: GPIO4 is also D4, IO4, GP4 and 4.
     *
     * @return list<string>
     */
    private static function alsoCalled(string $name): array
    {
        if (! preg_match('/^(?:GPIO|GP|IO|D)(\d+)$/i', $name, $numbered)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $letters) => $letters.$numbered[1], ['gpio', 'gp', 'io', 'd', '']),
            fn (string $other) => $other !== strtolower($name),
        ));
    }

    /**
     * The library in a line, for a client choosing from it: each part with
     * the names of its pins, and the boards and sensors by name -- their
     * pins are what is printed on them, and a wrong one is answered with
     * the right ones.
     */
    public static function catalogue(): string
    {
        $parts = array_filter(self::all(), fn (array $symbol) => ! ($symbol['module'] ?? false));
        $modules = array_filter(self::all(), fn (array $symbol) => $symbol['module'] ?? false);

        return implode(', ', array_map(
            fn (array $symbol) => $symbol['id'].' ('.implode(' ', array_column($symbol['pins'], 'name')).')',
            $parts,
        )).'; boards and sensors: '.implode(', ', array_keys($modules));
    }

    /**
     * The name a symbol knows one of its pins by, given that or another it
     * answers to ("anode" for "a"); null when it has no such pin.
     *
     * @param  array<string, mixed>  $symbol
     */
    public static function pinName(array $symbol, string $name): ?string
    {
        $wanted = strtolower($name);

        foreach ($symbol['pins'] as $pin) {
            if (strtolower($pin['name']) === $wanted) {
                return $pin['name'];
            }
        }

        foreach ($symbol['pins'] as $pin) {
            if (in_array($wanted, $pin['aliases'] ?? [], true)) {
                return $pin['name'];
            }
        }

        return null;
    }

    /**
     * A symbol item's pins where they are on the board.
     *
     * @param  array<string, mixed>  $item  a canvas item of kind "symbol"
     * @return array<string, array{x: float, y: float}> by pin name
     */
    public static function pins(array $item): array
    {
        $symbol = self::find((string) ($item['symbol'] ?? ''));

        if ($symbol === null) {
            return [];
        }

        $across = (float) ($item['width'] ?? $symbol['width']) / $symbol['width'];
        $down = (float) ($item['height'] ?? $symbol['height']) / $symbol['height'];
        $angle = deg2rad((float) ($item['rotation'] ?? 0));
        $pins = [];

        foreach ($symbol['pins'] as $pin) {
            $x = $pin['x'] * $across;
            $y = $pin['y'] * $down;

            $pins[$pin['name']] = [
                'x' => round((float) ($item['x'] ?? 0) + $x * cos($angle) - $y * sin($angle), 3),
                'y' => round((float) ($item['y'] ?? 0) + $x * sin($angle) + $y * cos($angle), 3),
            ];
        }

        return $pins;
    }

    /**
     * The pin of a symbol item closest to a point: where an end that names
     * the part, but no pin of it, is put.
     *
     * @param  array<string, mixed>  $item
     * @param  array{x: float, y: float}  $point
     */
    public static function nearestPin(array $item, array $point): ?string
    {
        $best = null;
        $shortest = INF;

        foreach (self::pins($item) as $name => $pin) {
            $away = ($pin['x'] - $point['x']) ** 2 + ($pin['y'] - $point['y']) ** 2;

            if ($away < $shortest) {
                $shortest = $away;
                $best = $name;
            }
        }

        return $best;
    }
}
