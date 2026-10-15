<?php

namespace App\Support\Board;

/**
 * Ready-made looks for what is drawn on a board: colour sets, and styles to
 * put a colour on with -- soft, solid, outline, bold, light.
 *
 * One list, resources/js/features/boards/lib/styles.json, is read by the
 * canvas (composables/styles.ts) and here, so "solid:indigo" from an MCP
 * client paints what the canvas's own Style picker would.
 */
class Styles
{
    /** Kinds a style says nothing about: a picture, a formula, a part. */
    private const UNSTYLED = ['image', 'video', 'math', 'symbol'];

    /**
     * @return array{colours: array<string, array{label: string, shades: array<string, string>, solid: string}>, sets: list<array{id: string, label: string, colours: list<string>}>, styles: list<array{id: string, label: string, fill: string, stroke: string, color: string, lineWidth: float|int}>}
     */
    public static function all(): array
    {
        static $styles;

        return $styles ??= json_decode(
            (string) file_get_contents(resource_path('js/features/boards/lib/styles.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<string> */
    public static function styleIds(): array
    {
        return array_column(self::all()['styles'], 'id');
    }

    /** @return list<string> */
    public static function colourNames(): array
    {
        return array_keys(self::all()['colours']);
    }

    /**
     * What "style:colour" is short for, or null when it is not one: a style
     * alone takes indigo.
     *
     * @return array{string, string}|null
     */
    public static function parse(string $said): ?array
    {
        [$style, $colour] = array_pad(explode(':', strtolower(trim($said)), 2), 2, 'indigo');

        return in_array($style, self::styleIds(), true) && in_array($colour, self::colourNames(), true)
            ? [$style, $colour]
            : null;
    }

    /**
     * What putting a colour on in a style changes about an item of a kind:
     * fill, line and words for a shape; the line for a connector or ink; the
     * words for a text. The same sums as styled() in composables/styles.ts.
     *
     * @return array<string, mixed>
     */
    public static function styled(string $kind, string $style, string $colour): array
    {
        $look = collect(self::all()['styles'])->firstWhere('id', $style) ?? self::all()['styles'][0];

        if (in_array($kind, self::UNSTYLED, true)) {
            return [];
        }

        if ($kind === BoardItems::CONNECTOR || $kind === 'draw') {
            return ['stroke' => self::shade($colour, $look['stroke'] === 'white' ? 'solid' : $look['stroke'])];
        }

        if ($kind === 'text') {
            return ['color' => self::shade($colour, $look['color'] === 'white' ? 'solid' : $look['color'])];
        }

        return [
            'fill' => self::shade($colour, $look['fill']),
            'stroke' => self::shade($colour, $look['stroke']),
            'color' => self::shade($colour, $look['color']),
            'lineWidth' => (float) $look['lineWidth'],
        ];
    }

    /** A shade of a colour as a style names it: "500", "solid" or "white". */
    private static function shade(string $colour, string $shade): string
    {
        $family = self::all()['colours'][$colour] ?? self::all()['colours']['slate'];

        if ($shade === 'white') {
            return '#ffffff';
        }

        return $family['shades'][$shade === 'solid' ? $family['solid'] : $shade];
    }
}
