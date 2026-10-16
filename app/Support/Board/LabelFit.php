<?php

namespace App\Support\Board;

/**
 * How much room a label on a board takes, worked out the way the canvas lays
 * it out (resources/js/features/boards/drawing/labels.ts; keep the two in step):
 * Arial, Times New Roman or Courier New, wrapped at word breaks, lines 1.3
 * font sizes apart, in the box the item leaves for its label -- plain, or
 * read as light Markdown when the item is "rich".
 *
 * The server has no font to measure with, so the words are measured with
 * Arial's own glyph widths -- close enough to say whether a label fits, and
 * how tall a text item must be to hold its words.
 */
final class LabelFit
{
    public const LINE_HEIGHT = 1.3;

    /** The room after each letter of the label being measured, in board units. */
    private static float $spacing = 0.0;

    /** Arial's advance widths, in thousandths of the font size. */
    private const WIDTHS = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
        'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722,
        'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667,
        'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
        'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333,
        'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556,
        'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556,
        'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500,
        'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
        '•' => 350, '–' => 556, '—' => 1000, '…' => 1000, '→' => 1000, '·' => 278,
    ];

    /** Bold runs this much wider, near enough, than regular. */
    private const BOLD = 1.08;

    /** Times New Roman sets this much narrower than Arial, near enough. */
    private const SERIF = 0.9;

    /** Heading sizes, against the label's own, for "#", "##" and "###". */
    private const HEADINGS = [1 => 1.6, 2 => 1.3, 3 => 1.1];

    /** The room either side of inline code, in font sizes (labels.ts). */
    private const CODE_PAD = 0.25;

    /** How tall the line a rule ("---") takes is, in font sizes. */
    private const RULE_HEIGHT = 0.7;

    /**
     * The box the label is written in, relative to the item.
     *
     * @param  array<string, mixed>  $item
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function box(array $item): array
    {
        $width = (float) ($item['width'] ?? 0);
        $height = (float) ($item['height'] ?? 0);
        $kind = (string) ($item['kind'] ?? '');
        $padding = max(0.0, (float) ($item['padding'] ?? ($kind === 'text' ? 0 : 12)));

        // A table, a class or a lane is named in its band
        if ((Shapes::find($kind)['header'] ?? false) === true) {
            $depth = BoardRows::headerDepth($item);

            return ($item['variant'] ?? 'top') === 'left' && BoardRows::kindOf($kind) === null
                ? ['x' => 0.0, 'y' => 0.0, 'width' => $depth, 'height' => $height]
                : ['x' => $padding, 'y' => 0.0, 'width' => max(1.0, $width - $padding * 2), 'height' => $depth];
        }

        // A shape from the library says where its words go, as shares of its
        // box -- or the design it is drawn in does: under a card's icon
        $room = Shapes::designText($kind, is_string($item['variant'] ?? null) ? $item['variant'] : null)
            ?? Shapes::find($kind)['text'] ?? null;

        if (is_array($room)) {
            $left = (float) ($room['left'] ?? 0);
            $above = (float) ($room['top'] ?? 0);

            return [
                'x' => $left * $width + $padding,
                'y' => $above * $height + $padding,
                'width' => max(1.0, $width * (1 - $left - (float) ($room['right'] ?? 0)) - $padding * 2),
                'height' => max(1.0, $height * (1 - $above - (float) ($room['bottom'] ?? 0)) - $padding * 2),
            ];
        }

        $top = match ($kind) {
            'cylinder' => max($padding, $height * 0.2),
            'triangle' => max($padding, $height * 0.35),
            default => $padding,
        };
        $side = $kind === 'process' ? $width * 0.14 + $padding : $padding;

        return [
            'x' => $side,
            'y' => $top,
            'width' => max(1.0, $width - $side * 2),
            'height' => max(1.0, $height - $top * 2),
        ];
    }

    /**
     * How tall the item's label is once wrapped to its box.
     *
     * @param  array<string, mixed>  $item
     */
    public static function needed(array $item): float
    {
        // As the canvas shows it: in capitals, and spread apart, when set so
        $text = ($item['caps'] ?? false) === true ? mb_strtoupper((string) ($item['text'] ?? '')) : (string) ($item['text'] ?? '');
        self::$spacing = (float) ($item['letterSpacing'] ?? 0);

        if ($text === '') {
            return 0.0;
        }

        $size = (float) ($item['fontSize'] ?? 16);
        $font = (string) ($item['fontFamily'] ?? 'sans');
        $width = self::box($item)['width'];

        if (($item['rich'] ?? false) === true) {
            return self::richHeight($text, $width, $size, $font);
        }

        // A plain text item is set in bold, as a heading usually is
        $bold = ($item['kind'] ?? '') === 'text';
        $height = 0.0;

        foreach (explode("\n", $text) as $line) {
            $height += self::lines([[$line, $bold, false]], $width, 0.0, $size, $font) * $size * self::LINE_HEIGHT;
        }

        return $height;
    }

    /**
     * Whether the label runs out of the box it has: the canvas then draws it
     * past the item's edge. Frames, connectors, formulae, ink and symbols
     * are left out; their words are laid out on other terms.
     *
     * @param  array<string, mixed>  $item
     */
    public static function overflows(array $item): bool
    {
        if (in_array($item['kind'] ?? '', ['frame', 'arrow', 'math', 'draw', 'video', 'symbol', 'path'], true)) {
            return false;
        }

        // A pixel's grace for the rounding either side does
        return self::needed($item) > self::box($item)['height'] + 1;
    }

    /**
     * A rich label's height: each line of it a heading, a list item hanging
     * off its marker, or words, read the way the canvas reads them
     * (labels.ts, paragraphsOf).
     */
    private static function richHeight(string $text, float $width, float $size, string $font): float
    {
        $height = 0.0;

        foreach (explode("\n", $text) as $line) {
            // A line of dashes is a rule across the item, not words
            if (preg_match('/^\s*-{3,}\s*$/', $line) === 1) {
                $height += $size * self::RULE_HEIGHT;

                continue;
            }

            $scale = 1.0;
            $bold = false;
            $indent = 0.0;

            if (preg_match('/^(#{1,3})\s+(.*)$/u', $line, $heading)) {
                $scale = self::HEADINGS[strlen($heading[1])];
                $bold = true;
                $line = $heading[2];
            } elseif (preg_match('/^\s*(?:[-*•]|(\d+[.)]))\s+(.*)$/u', $line, $item)) {
                $marker = $item[1] !== '' ? $item[1] : '•';
                $indent = self::measure($marker.' ', $size * $scale, $font, false);
                $line = $item[2];
            }

            $height += self::lines(self::runs($line, $bold), $width, $indent, $size * $scale, $font) * $size * $scale * self::LINE_HEIGHT;
        }

        return $height;
    }

    /**
     * A line's runs of words, each bold or not and code or not, with the
     * marks that set them taken out: `code`, a mark escaped with a backslash,
     * **, * and _ -- an underscore inside a word staying an underscore
     * (labels.ts, runsOf).
     *
     * @return list<array{string, bool, bool}>
     */
    private static function runs(string $line, bool $bold): array
    {
        $runs = [];
        // Plain words so far, which an escaped mark joins rather than splits
        $words = null;
        $parts = preg_split('/(`[^`]+`|\\\\[\\\\`*_#~[\]-]|\*\*[^*]+\*\*|\*[^*\s][^*]*\*|(?<![\p{L}\p{N}_])_[^_\s](?:[^_]*[^_\s])?_(?![\p{L}\p{N}_]))/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            if ($index % 2 === 0 || str_starts_with($part, '\\')) {
                $text = $index % 2 === 0 ? $part : substr($part, 1);

                $words = ($words ?? '').$text;

                continue;
            }

            if ($words !== null) {
                $runs[] = [$words, $bold, false];
                $words = null;
            }

            if (str_starts_with($part, '`')) {
                $runs[] = [substr($part, 1, -1), $bold, true];
            } else {
                // Read inside too: `code` and escapes in bold or italic words
                $strong = str_starts_with($part, '**');
                array_push($runs, ...self::runs($strong ? substr($part, 2, -2) : substr($part, 1, -1), $bold || $strong));
            }
        }

        if ($words !== null) {
            $runs[] = [$words, $bold, false];
        }

        return $runs;
    }

    /**
     * How many lines Konva breaks runs of words into: wrapped at spaces, each
     * line after the first starting at $indent, and a word longer than the
     * line broken where it runs out.
     *
     * @param  list<array{string, bool, bool}>  $runs
     */
    private static function lines(array $runs, float $width, float $indent, float $size, string $font): int
    {
        $count = 1;
        $used = $indent;

        foreach ($runs as [$text, $bold, $code]) {
            // Code is set in mono, with room either side for its tint
            $used += $code ? $size * self::CODE_PAD * 2 : 0;

            foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $wide = self::measure($piece, $size, $code ? 'mono' : $font, $bold);

                if (trim($piece) === '') {
                    if ($used > $indent) {
                        $used += $wide;
                    }

                    continue;
                }

                if ($used + $wide > $width && $used > $indent) {
                    $count++;
                    $used = $indent;
                }

                // Longer than a whole line: broken letter by letter
                while ($wide > $width - $indent && $width - $indent > 0) {
                    $count++;
                    $wide -= $width - $indent;
                }

                $used += $wide;
            }
        }

        return $count;
    }

    private static function measure(string $text, float $size, string $font, bool $bold): float
    {
        // Spread apart: the room after each letter, on top of its own width
        $spread = self::$spacing * mb_strlen($text);

        if ($font === 'mono') {
            // Courier New: every letter the same, bold or not
            return mb_strlen($text) * 0.6 * $size + $spread;
        }

        $total = 0;

        foreach (mb_str_split($text) as $character) {
            $total += self::WIDTHS[$character] ?? (strlen($character) >= 3 && preg_match('/\p{Han}|\p{Hangul}|\p{Hiragana}|\p{Katakana}/u', $character) ? 1000 : 600);
        }

        return $total / 1000 * $size * ($bold ? self::BOLD : 1) * ($font === 'serif' ? self::SERIF : 1) + $spread;
    }
}
