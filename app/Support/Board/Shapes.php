<?php

namespace App\Support\Board;

/**
 * The shapes a board draws from a path: more flowchart, UML, architecture.
 *
 * One list, resources/js/features/boards/lib/shapes.json, is read by the
 * canvas (model/shapes.ts) and here, so a shape an MCP client names is
 * one the canvas can draw, and starts as it would from the toolbar.
 */
class Shapes
{
    /**
     * Every shape in the library, by the kind it is drawn as.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        static $shapes;

        return $shapes ??= json_decode(
            (string) file_get_contents(resource_path('js/features/boards/lib/shapes.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['shapes'];
    }

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(?string $kind): ?array
    {
        return self::all()[$kind ?? ''] ?? null;
    }

    /**
     * What a new one starts with, where that is not what any shape does:
     * its size, its colours, where its words sit.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $kind): array
    {
        $defaults = self::find($kind)['defaults'] ?? [];

        // Numbers are kept as the canvas keeps them
        return array_map(fn (mixed $value) => is_int($value) ? (float) $value : $value, $defaults);
    }

    /**
     * The ways a kind can be drawn besides its usual one, by name: its own
     * ("stacked", "shaded"), and for a kind with an icon "card", "badge" and
     * "icon" -- as designsOf() in model/shapes.ts lists them.
     *
     * @return list<string>
     */
    public static function designs(string $kind): array
    {
        static $designs;

        $designs ??= json_decode(
            (string) file_get_contents(resource_path('js/features/boards/lib/shapes.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['designs'] ?? [];

        $spec = $designs[$kind] ?? null;

        if (! is_array($spec)) {
            return [];
        }

        return [
            'classic',
            ...array_column($spec['more'] ?? [], 'id'),
            ...(isset($spec['icon']) ? ['card', 'badge', 'icon'] : []),
        ];
    }

    /**
     * Where a label is written in a shape drawn in a design of its own: a
     * card's under its icon, a badge's and a bare icon's under the shape.
     *
     * @return array<string, float|int>|null
     */
    public static function designText(string $kind, ?string $variant): ?array
    {
        return match (in_array($variant, self::designs($kind), true) ? $variant : null) {
            'card' => ['top' => 0.54, 'left' => 0.06, 'right' => 0.06, 'bottom' => 0.04],
            'badge', 'icon' => ['top' => 1, 'bottom' => -0.4, 'left' => -0.6, 'right' => -0.6],
            default => null,
        };
    }

    /**
     * The kinds by the group the toolbar shows them in, for telling a client
     * what there is: "Flowchart" => "manual_input, delay, ...".
     *
     * @return array<string, string>
     */
    public static function byGroup(): array
    {
        $groups = [];

        foreach (self::all() as $kind => $shape) {
            $groups[(string) $shape['group']][] = $kind;
        }

        return array_map(fn (array $kinds) => implode(', ', $kinds), $groups);
    }
}
