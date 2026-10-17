<?php

namespace App\Support\Dashboard;

use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Board\BoardItems;
use App\Support\Board\BoardParts;
use App\Support\Table\TableStorage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the dashboard's recent cards show of each thing, small enough to send
 * with the page: a note's first lines as plain text, a board's first frame as
 * boxes and lines placed 0 to 1 across it (the card draws them), a table's
 * first columns and how many rows it has. One query for each kind, however
 * many cards there are -- and one more to count every table's rows at once.
 */
final class Previews
{
    /** How much of a note's text a card shows. */
    public const NOTE_CHARS = 140;

    /** How much of a note's Markdown is read to find that much text. */
    private const NOTE_READ = 1200;

    /** The most boxes and lines a board's card draws. */
    public const BOARD_ITEMS = 40;

    /** How many of a table's columns a card names. */
    public const TABLE_COLUMNS = 4;

    /** Room left round a board's drawing, as a share of it. */
    private const PADDING = 0.04;

    /**
     * Each recent thing's preview, by "kind:ref_id". A thing with nothing to
     * show -- an empty note, a board with nothing on it -- still has one, empty.
     *
     * @param  Collection<int, array{kind: string, ref_id: string}>  $recent
     * @return array<string, array<string, mixed>>
     */
    public static function of(Collection $recent): array
    {
        $refs = $recent
            ->groupBy('kind')
            ->map(fn (Collection $things) => array_values(array_unique(
                $things->map(fn (array $thing): string => $thing['ref_id'])->all(),
            )));

        return [
            ...self::notes($refs->get('note', [])),
            ...self::boards($refs->get('board', [])),
            ...self::tables($refs->get('table', [])),
        ];
    }

    /**
     * @param  list<string>  $refs
     * @return array<string, array{kind: string, heading: string|null, text: string}>
     */
    private static function notes(array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        // Only the start of the text: enough for a card, never a whole book
        return Note::query()
            ->whereIn('ref_id', $refs)
            ->select(['ref_id', 'title'])
            ->selectRaw('substr(plain_text, 1, ?) as opening', [self::NOTE_READ])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $note) => [
                "note:{$note->ref_id}" => ['kind' => 'note', ...self::opening((string) $note->opening, (string) $note->title)],
            ])
            ->all();
    }

    /**
     * A note's first lines as a card shows them: its first heading, unless
     * that only says the title again, then its words as plain text -- no
     * Markdown marks, no code, no pictures -- cut at a word.
     *
     * @return array{heading: string|null, text: string}
     */
    public static function opening(string $markdown, string $title = ''): array
    {
        $heading = null;
        $words = [];
        $fenced = false;
        $length = 0;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);

            // Code and diagrams say nothing a card can show
            if (preg_match('/^(```|~~~)/', $trimmed)) {
                $fenced = ! $fenced;

                continue;
            }

            if ($fenced || $trimmed === '' || preg_match('/^(\|?\s*:?-{3,}|[-*_]{3,}$)/', $trimmed)) {
                continue;
            }

            $isHeading = (bool) preg_match('/^#{1,6}\s+/', $trimmed);
            $plain = self::plain($trimmed);

            if ($plain === '') {
                continue;
            }

            // The first thing in it, when a heading, heads the card -- but the
            // card has the note's title already, and needs it only once
            if ($isHeading && $heading === null && $words === []) {
                if (mb_strtolower($plain) !== mb_strtolower(trim($title))) {
                    $heading = $plain;
                }

                continue;
            }

            $words[] = $plain;
            $length += mb_strlen($plain) + 1;

            if ($length > self::NOTE_CHARS) {
                break;
            }
        }

        return ['heading' => $heading, 'text' => self::cut(implode(' ', $words), self::NOTE_CHARS)];
    }

    /** One line of Markdown as the words a reader sees. */
    private static function plain(string $line): string
    {
        $patterns = [
            // Pictures go; a link and a page link leave their words
            '/!\[[^\]]*\]\([^)]*\)/' => '',
            '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/' => '$1',
            '/\[([^\]]*)\]\([^)]*\)/' => '$1',
            // Heading marks, quotes, list markers and to-do boxes in front
            '/^(#{1,6}|>+)\s*/' => '',
            '/^([-*+]|\d+[.)])\s+(\[[ xX]\]\s+)?/' => '',
            // A table's cells, one after another
            '/\s*\|\s*/' => ' ',
            // Emphasis, code, struck words; tags
            '/(\*\*|__|~~|`)/' => '',
            '/(?<![\w*])[*_](?=\S)|(?<=\S)[*_](?![\w*])/' => '',
            '/<[^>]+>/' => '',
        ];

        $plain = (string) preg_replace(array_keys($patterns), array_values($patterns), $line);

        return trim((string) preg_replace('/\s+/', ' ', $plain));
    }

    /** As much of a text as fits, cut at a word with an ellipsis. */
    private static function cut(string $text, int $most): string
    {
        if (mb_strlen($text) <= $most) {
            return $text;
        }

        $kept = mb_substr($text, 0, $most);
        $space = mb_strrpos($kept, ' ');

        return rtrim($space !== false && $space > $most * 0.6 ? mb_substr($kept, 0, $space) : $kept, ' ,;:.-–—').'…';
    }

    /**
     * @param  list<string>  $refs
     * @return array<string, array<string, mixed>>
     */
    private static function boards(array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        return Board::query()
            ->whereIn('ref_id', $refs)
            ->get(['ref_id', 'content'])
            ->mapWithKeys(fn (Board $board) => [
                "board:{$board->ref_id}" => self::drawing(array_values($board->content['items'] ?? [])),
            ])
            ->all();
    }

    /**
     * A board as its card draws it: what is on its first frame (or on the
     * whole board, with no frame), as boxes in their own colours and lines
     * between them, each placed 0 to 1 across the space they take up -- and
     * that space's width over its height, so the card keeps its shape. The
     * biggest few when there are more than a card can show.
     *
     * @param  array<mixed>  $content  the board's items, as saved
     * @return array{kind: 'board', aspect: float, boxes: list<array{x: float, y: float, w: float, h: float, fill: string, stroke: string, kind: string}>, lines: list<array{float, float, float, float}>}
     */
    public static function drawing(array $content): array
    {
        // Only what the canvas would draw: an item with an id, not hidden
        $items = [];

        foreach ($content as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && ! ($item['hidden'] ?? false)) {
                $items[] = $item;
            }
        }

        $frame = collect($items)->first(fn (array $item) => ($item['kind'] ?? '') === 'frame');
        $homes = BoardParts::homes($items);
        $home = $frame ? (string) $frame['id'] : null;

        $shown = array_values(array_filter($items, function (array $item) use ($homes, $home) {
            $kind = $item['kind'] ?? '';

            return $kind !== 'frame'
                && $kind !== BoardItems::CONNECTOR
                && (float) ($item['width'] ?? 0) > 0
                && (float) ($item['height'] ?? 0) > 0
                && ($home === null || ($homes[$item['id']] ?? null) === $home);
        }));

        // The biggest, kept in the order they are painted
        if (count($shown) > self::BOARD_ITEMS) {
            $areas = array_map(fn (array $item) => (float) $item['width'] * (float) $item['height'], $shown);
            arsort($areas);
            $kept = array_slice(array_keys($areas), 0, self::BOARD_ITEMS, true);
            sort($kept);
            $shown = array_map(fn (int $at) => $shown[$at], $kept);
        }

        $empty = ['kind' => 'board', 'aspect' => 16 / 9, 'boxes' => [], 'lines' => []];

        if ($shown === []) {
            return $empty;
        }

        $left = min(array_map(fn (array $item) => (float) $item['x'], $shown));
        $top = min(array_map(fn (array $item) => (float) $item['y'], $shown));
        $right = max(array_map(fn (array $item) => (float) $item['x'] + (float) $item['width'], $shown));
        $bottom = max(array_map(fn (array $item) => (float) $item['y'] + (float) $item['height'], $shown));
        $pad = max($right - $left, $bottom - $top) * self::PADDING;
        [$left, $top, $right, $bottom] = [$left - $pad, $top - $pad, $right + $pad, $bottom + $pad];
        $width = max(1.0, $right - $left);
        $height = max(1.0, $bottom - $top);

        $at = fn (float $value, float $from, float $span) => round(($value - $from) / $span, 4);
        $boxes = [];
        $centres = [];

        foreach ($shown as $item) {
            $box = [
                'x' => $at((float) $item['x'], $left, $width),
                'y' => $at((float) $item['y'], $top, $height),
                'w' => round((float) $item['width'] / $width, 4),
                'h' => round((float) $item['height'] / $height, 4),
                'fill' => self::colour($item['fill'] ?? null),
                'stroke' => self::colour($item['stroke'] ?? null),
                'kind' => (string) ($item['kind'] ?? 'rect'),
            ];
            $boxes[] = $box;
            $centres[$item['id']] = [$box['x'] + $box['w'] / 2, $box['y'] + $box['h'] / 2];
        }

        // A line for each connector whose ends are both drawn
        $lines = [];

        foreach ($items as $item) {
            if (($item['kind'] ?? '') !== BoardItems::CONNECTOR || count($lines) >= self::BOARD_ITEMS) {
                continue;
            }

            $from = $centres[(string) ($item['from']['item'] ?? '')] ?? null;
            $to = $centres[(string) ($item['to']['item'] ?? '')] ?? null;

            if ($from && $to) {
                $lines[] = [round($from[0], 4), round($from[1], 4), round($to[0], 4), round($to[1], 4)];
            }
        }

        return ['kind' => 'board', 'aspect' => round($width / $height, 4), 'boxes' => $boxes, 'lines' => $lines];
    }

    /** A colour a card can paint with: a hex or rgb() as it is, otherwise none. */
    private static function colour(mixed $colour): string
    {
        return is_string($colour) && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]+\))$/', trim($colour))
            ? trim($colour)
            : 'transparent';
    }

    /**
     * @param  list<string>  $refs
     * @return array<string, array{kind: string, columns: list<string>, rows: int}>
     */
    private static function tables(array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        $tables = Table::query()->whereIn('ref_id', $refs)->get(['id', 'ref_id']);

        if ($tables->isEmpty()) {
            return [];
        }

        $columns = TableColumn::query()
            ->whereIn('table_id', $tables->pluck('id'))
            ->where('hidden', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['table_id', 'label'])
            ->groupBy('table_id');

        $rows = self::rowCounts($tables);

        return $tables
            ->mapWithKeys(fn (Table $table) => [
                "table:{$table->ref_id}" => [
                    'kind' => 'table',
                    'columns' => array_values($columns->get($table->id, collect())
                        ->take(self::TABLE_COLUMNS)
                        ->map(fn (TableColumn $column): string => $column->label)
                        ->all()),
                    'rows' => $rows[$table->ref_id] ?? 0,
                ],
            ])
            ->all();
    }

    /**
     * How many rows each table holds, by ref_id, counted in one query across
     * the tables they are kept in. Should one of those be missing, no table
     * is counted: the cards go without a number rather than the dashboard
     * without its page.
     *
     * @param  Collection<int, Table>  $tables
     * @return array<string, int>
     */
    private static function rowCounts(Collection $tables): array
    {
        $counts = $tables
            ->map(fn (Table $table) => DB::table(TableStorage::physicalName($table))
                ->selectRaw('? as ref_id, count(*) as total', [$table->ref_id]))
            ->values();

        $query = $counts->shift();

        foreach ($counts as $next) {
            $query->unionAll($next);
        }

        try {
            return $query->get()->mapWithKeys(fn (object $row) => [(string) $row->ref_id => (int) $row->total])->all();
        } catch (QueryException $missing) {
            report($missing);

            return [];
        }
    }
}
