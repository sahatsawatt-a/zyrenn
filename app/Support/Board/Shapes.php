<?php

namespace App\Support\Board;

/**
 * The shapes a board draws from a path: more flowchart, UML, architecture.
 *
 * One list, resources/js/features/boards/lib/shapes.json, is read by the
 * canvas (composables/shapes.ts) and here, so a shape an MCP client names is
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
