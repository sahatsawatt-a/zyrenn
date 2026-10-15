<?php

namespace App\Support\Board;

/**
 * A board's frames made into a deck: one look for every slide, a header and
 * footer written on each, the slide's number, and slides laid out from a
 * layout.
 *
 * One list, resources/js/features/boards/lib/deck.json, holds the themes and
 * layouts, read here and by the canvas (composables/deck.ts), so a slide is
 * laid out the same wherever it is asked for. What a board keeps is its
 * content's "deck": a theme by its id, any of its colours or typefaces said
 * otherwise, the header and footer, and whether slides are numbered. Those
 * are written on each slide where it is drawn, never saved onto it.
 */
class Deck
{
    /** A deck's colours, each a hex colour when said. */
    public const COLOURS = ['background', 'ink', 'muted', 'accent'];

    /** The words a header or footer can say, filled in on each slide. */
    public const NAMES = ['n', 'count', 'section', 'title', 'slide'];

    /** What a slide can be filled with, by its layout's parts. */
    public const SLOTS = ['kicker', 'title', 'subtitle', 'body', 'left', 'right', 'picture', 'number', 'caption', 'quote', 'by'];

    /** No deck needs more slides than this added in one call. */
    public const MAX_SLIDES = 60;

    /**
     * @return array<string, mixed>
     */
    private static function library(): array
    {
        /** @var array<string, mixed>|null $library */
        static $library = null;

        return $library ??= json_decode(
            (string) file_get_contents(resource_path('js/features/boards/lib/deck.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<array<string, string>>
     */
    public static function themes(): array
    {
        return self::library()['themes'];
    }

    /**
     * @return list<string>
     */
    public static function themeIds(): array
    {
        return array_column(self::themes(), 'id');
    }

    /**
     * @return list<array{id: string, label: string, about: string, bare?: bool, section?: string, parts: list<array<string, mixed>>}>
     */
    public static function layouts(): array
    {
        return self::library()['layouts'];
    }

    /**
     * @return list<string>
     */
    public static function layoutIds(): array
    {
        return array_column(self::layouts(), 'id');
    }

    /**
     * Each layout in a line, with what it is filled from: for a tool's
     * description.
     */
    public static function described(): string
    {
        return collect(self::layouts())
            ->map(fn (array $layout) => "\"{$layout['id']}\" ({$layout['about']}"
                .($layout['parts'] !== [] ? ' Slots: '.implode(', ', array_column($layout['parts'], 'slot')) : '').')')
            ->implode('; ');
    }

    /**
     * The validation rules for a deck as a client sends it.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $key = 'deck'): array
    {
        $fonts = implode(',', BoardItems::FONTS);

        return [
            $key => ['sometimes', 'nullable', 'array'],
            "{$key}.theme" => ['nullable', 'string', 'in:'.implode(',', self::themeIds())],
            ...collect(self::COLOURS)->mapWithKeys(fn (string $colour) => ["{$key}.{$colour}" => ['nullable', 'string', 'max:32']])->all(),
            "{$key}.font" => ['nullable', 'string', 'in:'.$fonts],
            "{$key}.headingFont" => ['nullable', 'string', 'in:'.$fonts],
            "{$key}.header" => ['nullable', 'string', 'max:200'],
            "{$key}.footer" => ['nullable', 'string', 'max:200'],
            "{$key}.numbers" => ['nullable', 'boolean'],
        ];
    }

    /**
     * A board's deck, as it keeps it; null when it has none.
     *
     * @param  array<string, mixed>|null  $content
     * @return array<string, mixed>|null
     */
    public static function of(?array $content): ?array
    {
        $deck = $content['deck'] ?? null;

        return is_array($deck) && $deck !== [] ? self::clean($deck) : null;
    }

    /**
     * A board's content: its items, and its deck when it has one.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $deck
     * @return array<string, mixed>
     */
    public static function content(array $items, ?array $deck): array
    {
        return $deck === null ? ['items' => $items] : ['items' => $items, 'deck' => $deck];
    }

    /**
     * A deck changed: what is said over what it was. A field sent as null or
     * "" is taken away; one left out keeps what it had. Null when nothing is
     * left.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>|null
     */
    public static function merged(?array $current, array $given): ?array
    {
        $deck = [...($current ?? []), ...$given];

        return self::clean($deck) ?: null;
    }

    /**
     * Only what a deck can hold, with nothing empty in it.
     *
     * @param  array<string, mixed>  $deck
     * @return array<string, mixed>
     */
    private static function clean(array $deck): array
    {
        $kept = [];

        foreach (['theme', ...self::COLOURS, 'font', 'headingFont', 'header', 'footer'] as $field) {
            if (is_string($deck[$field] ?? null) && trim($deck[$field]) !== '') {
                $kept[$field] = $deck[$field];
            }
        }

        if (($deck['numbers'] ?? false) === true) {
            $kept['numbers'] = true;
        }

        return $kept;
    }

    /**
     * A deck's colours and typefaces: its theme's, with what it says over them.
     *
     * @param  array<string, mixed>|null  $deck
     * @return array<string, string>
     */
    public static function look(?array $deck): array
    {
        $themes = self::themes();
        $theme = collect($themes)->firstWhere('id', $deck['theme'] ?? null) ?? $themes[0];

        foreach ([...self::COLOURS, 'font', 'headingFont'] as $field) {
            if (is_string($deck[$field] ?? null) && $deck[$field] !== '') {
                $theme[$field] = $deck[$field];
            }
        }

        return $theme;
    }

    /**
     * Where the next slide goes: to the right of the right-most frame, level
     * with it; at the board's corner on a board with none.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{x: float, y: float}
     */
    public static function nextSlideAt(array $items): array
    {
        $last = null;

        foreach ($items as $item) {
            if (($item['kind'] ?? null) !== 'frame' || ! is_numeric($item['x'] ?? null)) {
                continue;
            }

            $right = (float) $item['x'] + (float) ($item['width'] ?? 0);

            if ($last === null || $right > $last['right']) {
                $last = ['right' => $right, 'y' => (float) ($item['y'] ?? 0)];
            }
        }

        return $last === null ? ['x' => 0.0, 'y' => 0.0] : ['x' => $last['right'] + 80, 'y' => $last['y']];
    }

    /**
     * Slides as a client asks for them, as the items to put on the board: a
     * frame for each, in the deck's background, and a text for each of its
     * layout's parts -- set in the deck's typefaces and colours -- filled
     * with what the slide says for it. A part said nothing for is left off.
     *
     * Each slide goes to the right of the last; a frame gets the slide's
     * "id" (made up when left out), and its parts that id and their slot:
     * "intro-title", "intro-body", to change later with update_items.
     *
     * @param  list<array<string, mixed>>  $slides
     * @param  list<array<string, mixed>>  $items  what is on the board already, or about to be
     * @param  array<string, mixed>|null  $deck
     * @return list<array<string, mixed>>
     */
    public static function slideSpecs(array $slides, array $items, ?array $deck): array
    {
        $look = self::look($deck);
        $layouts = array_column(self::layouts(), null, 'id');
        $roles = self::library()['roles'];
        $taken = array_flip(array_filter(array_column($items, 'id'), 'is_string'));
        $specs = [];
        $made = 0;

        foreach ($slides as $slide) {
            $layout = $layouts[$slide['layout'] ?? 'title-body'] ?? $layouts['title-body'];
            $at = self::nextSlideAt([...$items, ...$specs]);

            // The client's own id, or the next "slideN" nothing has
            $id = is_string($slide['id'] ?? null) && $slide['id'] !== '' ? $slide['id'] : null;

            while ($id === null) {
                $id = isset($taken['slide'.(++$made)]) ? null : 'slide'.$made;
            }

            $taken[$id] = true;
            $words = fn (string $slot) => is_string($slide[$slot] ?? null) ? trim($slide[$slot]) : '';

            $frame = [
                'id' => $id,
                'kind' => 'frame',
                'x' => $at['x'],
                'y' => $at['y'],
                'width' => (float) self::library()['frame']['width'],
                'height' => (float) self::library()['frame']['height'],
                'fill' => $look['background'],
                'text' => $words('title') ?: $layout['label'],
            ];

            if ($layout['bare'] ?? false) {
                $frame['bare'] = true;
            }

            if (isset($layout['section']) && $words($layout['section']) !== '') {
                $frame['section'] = $words($layout['section']);
            }

            foreach (['notes', 'section', 'src'] as $field) {
                if (is_string($slide[$field] ?? null) && $slide[$field] !== '') {
                    $frame[$field] = $slide[$field];
                }
            }

            if (is_bool($slide['bare'] ?? null)) {
                $frame['bare'] = $slide['bare'];
            }

            $specs[] = $frame;

            foreach ($layout['parts'] as $part) {
                $text = $words($part['slot']);

                if ($text === '') {
                    continue;
                }

                $box = [
                    'id' => "{$id}-{$part['slot']}",
                    'x' => $at['x'] + $part['x'],
                    'y' => $at['y'] + $part['y'],
                    'width' => (float) $part['width'],
                    'height' => (float) $part['height'],
                ];

                // A picture's part takes a picture's address, and fills its box
                if ($part['role'] === 'picture') {
                    $specs[] = [...$box, 'kind' => 'image', 'src' => $text, 'fit' => 'cover'];

                    continue;
                }

                $role = $roles[$part['role']] ?? [];
                $specs[] = [
                    ...$box,
                    'kind' => 'text',
                    'text' => $text,
                    'fontSize' => (float) ($role['fontSize'] ?? 20),
                    'fontFamily' => ($role['font'] ?? 'body') === 'heading' ? $look['headingFont'] : $look['font'],
                    'color' => $look[$role['color'] ?? 'ink'],
                    'rich' => (bool) ($role['rich'] ?? false),
                    ...(($role['caps'] ?? false) ? ['caps' => true] : []),
                    ...(isset($role['letterSpacing']) ? ['letterSpacing' => (float) $role['letterSpacing']] : []),
                ];
            }
        }

        return $specs;
    }
}
