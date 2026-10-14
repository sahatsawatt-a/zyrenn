<?php

namespace App\Support\Board;

/**
 * Diagrams a board can be started from: a flowchart, an org chart, a data model.
 *
 * One list, resources/js/features/boards/lib/templates.json, holds each as an
 * MCP client would write it, and is read by the canvas
 * (composables/templates.ts) and here -- so the template a client asks for is
 * the one the Templates button puts on a board.
 */
class Templates
{
    /**
     * Every template, by its id.
     *
     * @return array<string, array{id: string, label: string, about: string, items: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        static $templates;

        return $templates ??= array_column(json_decode(
            (string) file_get_contents(resource_path('js/features/boards/lib/templates.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['templates'], null, 'id');
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /**
     * A template's items as a client would have written them, for
     * BoardItems::fromSpec to draw.
     *
     * @return list<array<string, mixed>>
     */
    public static function specs(string $id): array
    {
        return self::all()[$id]['items'] ?? [];
    }

    /**
     * What there is, for telling a client: "flowchart (steps and a decision...), ...".
     */
    public static function described(): string
    {
        return implode('; ', array_map(
            fn (array $template) => $template['id'].' -- '.lcfirst(rtrim($template['about'], '.')),
            self::all(),
        ));
    }
}
