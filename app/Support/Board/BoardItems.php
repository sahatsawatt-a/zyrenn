<?php

namespace App\Support\Board;

/**
 * Translates between the board canvas's items and the short form MCP clients
 * read and write.
 *
 * A canvas item (resources/js/features/boards/composables/items.ts) carries twenty-odd
 * fields, most of which matter to one kind of shape only. A client sends the
 * few that say what it wants -- a kind, a label, maybe a box -- and this fills
 * the rest in with the defaults the toolbar uses, lays out whatever arrived
 * without coordinates, and pins each connector to the edge of the shape it
 * names, facing the shape at its other end.
 */
class BoardItems
{
    public const CONNECTOR = 'arrow';

    /** Every kind the canvas can draw. */
    public const KINDS = [
        'frame', 'sticky', 'text', 'rect', 'pill', 'ellipse', 'triangle',
        'diamond', 'hexagon', 'star', 'cylinder', 'parallelogram', 'document',
        'process', 'cloud', 'image', 'video', 'math', self::CONNECTOR, 'draw',
    ];

    /** Kinds a connector may pin itself to. */
    public const CONNECTABLE = [
        'sticky', 'text', 'rect', 'pill', 'ellipse', 'triangle', 'diamond',
        'hexagon', 'star', 'cylinder', 'parallelogram', 'document', 'process',
        'cloud', 'image', 'video', 'math',
    ];

    public const SIDES = BoardPins::SIDES;

    public const ROUTINGS = ['elbow', 'straight', 'curved'];

    public const LINE_STYLES = ['solid', 'dashed', 'dotted'];

    public const HEADS = ['none', 'arrow', 'open', 'circle', 'diamond', 'bar'];

    /** Where a label sits in whatever it is written on. */
    public const ALIGNS = ['left', 'center', 'right'];

    public const VERTICAL_ALIGNS = ['top', 'middle', 'bottom'];

    /** How a picture fills its box: stretched, whole inside it, or covering it. */
    public const FITS = ['fill', 'contain', 'cover'];

    /** A label's typeface: Arial, Times New Roman or Courier New. */
    public const FONTS = ['sans', 'serif', 'mono'];

    /** Sticky notes are dealt out of this pack, as they are on the canvas. */
    private const STICKY_COLOURS = ['#fde68a', '#bbf7d0', '#bfdbfe', '#fbcfe8', '#ddd6fe'];

    /**
     * The canvas items for a client's list.
     *
     * An item whose id is already on the board, as the same kind of thing,
     * keeps every field the client does not mention -- its ink, its colours,
     * the picture it holds -- so a client can read a board, change one label
     * and send the list back without flattening the rest of it. Items left out
     * of the list are gone. An item sent without an id is new: the id it is
     * given is one nothing on the board, or in the list, already has.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  list<mixed>  $current  the board's items as they came out of the database
     * @return list<array<string, mixed>>
     */
    public static function fromSpec(array $specs, array $current = []): array
    {
        $existing = [];

        foreach ($current as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $existing[$item['id']] = $item;
            }
        }

        $specs = self::withIds($specs, array_keys($existing));
        $items = [];
        $index = 0;

        foreach ($specs as $spec) {
            $kind = is_string($spec['kind'] ?? null) ? $spec['kind'] : 'sticky';
            $id = $spec['id'];

            // Another kind under an old id is a new thing, not the old one changed
            $item = ($existing[$id]['kind'] ?? null) === $kind
                ? [...self::blank($kind, $index), ...$existing[$id]]
                : self::blank($kind, $index);

            $item['id'] = $id;
            $item['kind'] = $kind;

            foreach (['x', 'y', 'width', 'height', 'rotation', 'fontSize', 'padding', 'lineWidth', 'headSize'] as $number) {
                if (is_numeric($spec[$number] ?? null)) {
                    $item[$number] = (float) $spec[$number];
                }
            }

            foreach (['text', 'fill', 'stroke', 'src', 'fit', 'fontFamily', 'align', 'verticalAlign', 'routing', 'lineStyle', 'startHead', 'endHead'] as $string) {
                if (is_string($spec[$string] ?? null)) {
                    $item[$string] = $spec[$string];
                }
            }

            foreach (['hidden', 'locked', 'rich', 'pdfHidden'] as $flag) {
                if (is_bool($spec[$flag] ?? null)) {
                    $item[$flag] = $spec[$flag];
                }
            }

            // A text item is only its words: unless told how tall it is, it is as
            // tall as they need, the way it grows on the canvas
            if ($kind === 'text' && ! is_numeric($spec['height'] ?? null)) {
                $item['height'] = max((float) $item['height'], ceil(LabelFit::needed($item)));
            }

            // Coordinates are missing far more often than not: a client says
            // what goes on the board and leaves the arranging to us
            $item['placed'] = array_key_exists('x', $spec) && array_key_exists('y', $spec);

            $items[] = $item;
            $index++;
        }

        $edges = [];

        foreach ($specs as $position => $spec) {
            if (($spec['kind'] ?? null) !== self::CONNECTOR) {
                continue;
            }

            $from = BoardPins::referencedItem($spec['from'] ?? null);
            $to = BoardPins::referencedItem($spec['to'] ?? null);

            if ($from !== null && $to !== null) {
                $edges[] = [$from, $to];
            }
        }

        $items = BoardLayout::place($items, $edges);
        $boxes = array_column($items, null, 'id');

        foreach ($items as $position => $item) {
            if ($item['kind'] === self::CONNECTOR) {
                $items[$position] = BoardPins::pin($item, $specs[$position], $boxes);
            }
        }

        // Nothing left painted under the frame it sits on, as the canvas keeps it
        return BoardParts::keepOnFrames(array_map(function (array $item) {
            unset($item['placed']);

            return $item;
        }, $items));
    }

    /**
     * The board's items in the short form, small enough to read and safe to
     * send back: a field left out keeps whatever the canvas has stored.
     *
     * @param  array<string, mixed>|null  $content
     * @return list<array<string, mixed>>
     */
    public static function toSpec(?array $content): array
    {
        $items = is_array($content['items'] ?? null) ? $content['items'] : [];
        $specs = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $kind = (string) ($item['kind'] ?? '');
            $connector = $kind === self::CONNECTOR;

            $spec = [
                'id' => (string) ($item['id'] ?? ''),
                'kind' => $kind,
            ];

            if (! $connector) {
                $spec += [
                    'x' => self::round($item['x'] ?? 0),
                    'y' => self::round($item['y'] ?? 0),
                    'width' => self::round($item['width'] ?? 0),
                    'height' => self::round($item['height'] ?? 0),
                ];
            }

            if (trim((string) ($item['text'] ?? '')) !== '') {
                $spec['text'] = (string) $item['text'];
            }

            foreach (['fill', 'stroke'] as $colour) {
                $value = (string) ($item[$colour] ?? '');

                if ($value !== '' && $value !== 'transparent') {
                    $spec[$colour] = $value;
                }
            }

            foreach (['rotation' => 0, 'fontSize' => 16, 'lineWidth' => 2, 'headSize' => 10] as $number => $default) {
                if (isset($item[$number]) && self::round($item[$number]) !== (float) $default) {
                    $spec[$number] = self::round($item[$number]);
                }
            }

            foreach (['hidden', 'locked', 'rich', 'pdfHidden'] as $flag) {
                if (($item[$flag] ?? false) === true) {
                    $spec[$flag] = true;
                }
            }

            $default = self::blank($kind, 0);

            foreach (['align', 'verticalAlign', 'fit', 'fontFamily'] as $placing) {
                if (isset($item[$placing]) && $item[$placing] !== $default[$placing]) {
                    $spec[$placing] = (string) $item[$placing];
                }
            }

            if (isset($item['padding']) && self::round($item['padding']) !== $default['padding']) {
                $spec['padding'] = self::round($item['padding']);
            }

            // A picture is a data URL of its own bytes, far too big to report.
            // Saying it is there is enough: send the item back without a "src"
            // and it keeps the picture it has.
            if (($item['src'] ?? '') !== '') {
                $src = (string) $item['src'];
                $spec += str_starts_with($src, 'data:') ? ['has_picture' => true] : ['src' => $src];
            }

            if ($connector) {
                $spec['from'] = BoardPins::endpointSpec($item['from'] ?? null);
                $spec['to'] = BoardPins::endpointSpec($item['to'] ?? null);

                foreach (['routing' => 'elbow', 'lineStyle' => 'solid', 'startHead' => 'none', 'endHead' => 'arrow'] as $field => $default) {
                    if (($item[$field] ?? $default) !== $default) {
                        $spec[$field] = (string) $item[$field];
                    }
                }
            }

            $specs[] = $spec;
        }

        return $specs;
    }

    /**
     * Changes by item: new items added on top, some changed by id -- only the
     * fields given -- and others deleted. They go the way a whole list does
     * (fromSpec), so whatever a change leaves out keeps what the canvas has,
     * and connectors pin to anything on the board, old or new.
     *
     * Answers with every item as it now is, those that differ from before
     * (all a live copy needs sent), and the ids added, changed and deleted.
     *
     * @param  list<array<string, mixed>>  $current  the board's items as stored
     * @param  list<array<string, mixed>>  $add
     * @param  list<array<string, mixed>>  $update  each with the item's "id"
     * @param  list<string>  $delete
     * @return array{items: list<array<string, mixed>>, set: list<array<string, mixed>>, added: list<string>, changed: list<string>, deleted: list<string>}
     *
     * @throws BoardItemProblem when a change can't be made; then none are
     */
    public static function change(array $current, array $add = [], array $update = [], array $delete = []): array
    {
        $specs = [];

        foreach (self::toSpec(['items' => $current]) as $spec) {
            $specs[$spec['id']] = $spec;
        }

        $missing = fn (string $id) => new BoardItemProblem("There is no item \"{$id}\" on this board. get-board lists its items.");

        foreach ($delete as $id) {
            if (! isset($specs[$id])) {
                throw $missing($id);
            }

            unset($specs[$id]);
        }

        foreach ($update as $spec) {
            $id = (string) ($spec['id'] ?? '');

            if (! isset($specs[$id])) {
                throw $missing($id);
            }

            $merged = [...$specs[$id], ...$spec];

            // New words in a text item: let it grow to them, unless a height is given
            if (($merged['kind'] ?? null) === 'text' && array_key_exists('text', $spec) && ! array_key_exists('height', $spec)) {
                unset($merged['height']);
            }

            $specs[$id] = $merged;
        }

        $added = [];
        $next = self::nextNumber(array_keys($specs));

        foreach ($add as $spec) {
            $id = is_string($spec['id'] ?? null) && $spec['id'] !== '' ? $spec['id'] : 'i'.$next++;

            if (in_array($id, $delete, true)) {
                throw new BoardItemProblem("\"{$id}\" is both taken off and added. To change it, send it in update_items; to put something new in its place, give that a new id or leave \"id\" out.");
            }

            if (isset($specs[$id])) {
                throw new BoardItemProblem("An item \"{$id}\" is on the board already; leave \"id\" out and one is made up, or change it with update_items.");
            }

            $specs[$id] = [...$spec, 'id' => $id];
            $added[] = $id;
        }

        $specs = array_values($specs);
        $problems = self::badReferences($specs);

        if ($problems !== []) {
            throw new BoardItemProblem('A connector points at something it cannot pin to. '.implode(' ', $problems));
        }

        $items = self::fromSpec($specs, $current);
        $before = [];

        foreach ($current as $item) {
            $before[(string) ($item['id'] ?? '')] = $item;
        }

        return [
            'items' => $items,
            'set' => array_values(array_filter($items, fn (array $item) => ($before[$item['id']] ?? null) != $item)),
            'added' => $added,
            'changed' => array_map(fn (array $spec) => (string) $spec['id'], $update),
            'deleted' => $delete,
        ];
    }

    /**
     * The client's items, each with an id: its own, or one made up that
     * nothing in the list -- and nothing in $taken, the ids already on the
     * board -- has. A made-up id never lands on an old item and picks up
     * what it had.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  list<string>  $taken
     * @return list<array<string, mixed>> each with a string "id"
     */
    public static function withIds(array $specs, array $taken = []): array
    {
        $given = fn (array $spec) => is_string($spec['id'] ?? null) && $spec['id'] !== '' ? $spec['id'] : null;
        $next = self::nextNumber([...$taken, ...array_filter(array_map($given, $specs))]);

        foreach ($specs as $position => $spec) {
            $specs[$position]['id'] = $given($spec) ?? 'i'.$next++;
        }

        return $specs;
    }

    /**
     * The number after the highest "i<n>" id in use, so a made-up id is new.
     *
     * @param  list<string>  $ids
     */
    private static function nextNumber(array $ids): int
    {
        $highest = 0;

        foreach ($ids as $id) {
            if (preg_match('/^i(\d+)$/', $id, $number)) {
                $highest = max($highest, (int) $number[1]);
            }
        }

        return $highest + 1;
    }

    /**
     * What deleting these items takes with it: the items, and every
     * connector with an end on one of them -- it would be left pointing at
     * nothing.
     *
     * @param  list<array<string, mixed>>  $items  canvas items
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function withConnectors(array $items, array $ids): array
    {
        $gone = array_flip($ids);

        foreach ($items as $item) {
            if (($item['kind'] ?? '') === self::CONNECTOR
                && (isset($gone[(string) ($item['from']['item'] ?? '')]) || isset($gone[(string) ($item['to']['item'] ?? '')]))) {
                $gone[(string) $item['id']] = true;
            }
        }

        return array_keys($gone);
    }

    /**
     * The board with its frames -- the slides, presented in the order they
     * are drawn -- in a new order. Each frame takes the place in the drawing
     * order that one of them had; whatever is on a frame that lands above it
     * comes up with it, so no slide is drawn over its own contents.
     *
     * @param  list<array<string, mixed>>  $items  canvas items
     * @param  list<string>  $order  every frame's id, once each
     * @return list<array<string, mixed>>
     *
     * @throws BoardItemProblem when the order doesn't name every frame once
     */
    public static function orderFrames(array $items, array $order): array
    {
        $frames = [];

        foreach ($items as $position => $item) {
            if (($item['kind'] ?? '') === 'frame') {
                $frames[$position] = $item;
            }
        }

        $ids = array_map(fn (array $frame) => (string) $frame['id'], $frames);
        $missing = array_diff($ids, $order);
        $unknown = array_diff($order, $ids);

        if ($missing !== [] || $unknown !== [] || count($order) !== count(array_unique($order))) {
            throw new BoardItemProblem('frame_order needs every frame\'s id, once each. The frames are: "'.implode('", "', $ids).'".');
        }

        $byId = array_column($frames, null, 'id');

        foreach (array_keys($frames) as $slot => $position) {
            $items[$position] = $byId[$order[$slot]];
        }

        // A frame moved into a later slot would be drawn over what is on it
        return BoardParts::keepOnFrames($items);
    }

    /**
     * The items whose label has more words than room, and how tall each would
     * have to be to hold them. The canvas draws such a label running out past
     * the item's edge.
     *
     * @param  list<array<string, mixed>>  $items  canvas items
     * @return list<array{id: string, kind: string, height: float, needs_height: float}>
     */
    public static function overflowing(array $items): array
    {
        $found = [];

        foreach ($items as $item) {
            if (LabelFit::overflows($item)) {
                $box = LabelFit::box($item);
                $found[] = [
                    'id' => (string) $item['id'],
                    'kind' => (string) $item['kind'],
                    'height' => self::round($item['height'] ?? 0),
                    'needs_height' => (float) ceil(LabelFit::needed($item) + $box['y'] * 2),
                ];
            }
        }

        return $found;
    }

    /**
     * The ids that appear more than once in a client's list.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<string>
     */
    public static function duplicateIds(array $specs): array
    {
        $ids = array_filter(
            array_map(fn (array $spec) => is_string($spec['id'] ?? null) ? $spec['id'] : null, $specs),
            fn (?string $id) => $id !== null && $id !== '',
        );

        return array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));
    }

    /**
     * Every connector end that names an item the list does not hold, or holds
     * as something a connector cannot pin itself to.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<string>
     */
    public static function badReferences(array $specs): array
    {
        $kinds = [];

        foreach (self::withIds($specs) as $spec) {
            $kinds[$spec['id']] = is_string($spec['kind'] ?? null) ? $spec['kind'] : 'sticky';
        }

        $problems = [];

        foreach ($specs as $spec) {
            if (($spec['kind'] ?? null) !== self::CONNECTOR) {
                continue;
            }

            foreach (['from', 'to'] as $end) {
                $item = BoardPins::referencedItem($spec[$end] ?? null);

                if ($item === null) {
                    continue;
                }

                if (! array_key_exists($item, $kinds)) {
                    $problems[] = "\"{$end}\" points at \"{$item}\", which is not an item on this board.";
                } elseif (! in_array($kinds[$item], self::CONNECTABLE, true)) {
                    $problems[] = "\"{$end}\" points at \"{$item}\", which is a {$kinds[$item]}; connectors can only pin to a shape, a sticky, a text or a picture.";
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * A full canvas item of this kind, with nothing said about it yet.
     *
     * @return array<string, mixed>
     */
    private static function blank(string $kind, int $index): array
    {
        $base = [
            'id' => '',
            'kind' => $kind,
            'x' => 0.0,
            'y' => 0.0,
            'width' => 200.0,
            'height' => 140.0,
            'rotation' => 0.0,
            'fill' => '#ffffff',
            'stroke' => '#cbd5e1',
            'text' => '',
            'fontSize' => 16.0,
            'fontFamily' => 'sans',
            'padding' => 12.0,
            'rich' => false,
            'align' => 'center',
            'verticalAlign' => 'middle',
            'points' => [],
            'hidden' => false,
            'locked' => false,
            'pdfHidden' => false,
            'src' => '',
            'fit' => 'fill',
            'from' => null,
            'to' => null,
            'routing' => 'elbow',
            'lineStyle' => 'solid',
            'lineWidth' => 2.0,
            'startHead' => 'none',
            'endHead' => 'arrow',
            'headSize' => 10.0,
        ];

        return match ($kind) {
            // 16:9, so a frame reads as a slide
            'frame' => [...$base, 'width' => 960.0, 'height' => 540.0, 'stroke' => '#94a3b8'],
            'sticky' => [...$base, 'width' => 180.0, 'height' => 180.0, 'stroke' => 'transparent',
                'fill' => self::STICKY_COLOURS[$index % count(self::STICKY_COLOURS)]],
            'text' => [...$base, 'width' => 260.0, 'height' => 40.0, 'fill' => 'transparent',
                'stroke' => 'transparent', 'fontSize' => 28.0, 'padding' => 0.0, 'align' => 'left', 'verticalAlign' => 'top'],
            'ellipse' => [...$base, 'width' => 200.0, 'height' => 200.0],
            'star' => [...$base, 'width' => 180.0, 'height' => 180.0, 'fill' => '#fde68a'],
            'image' => [...$base, 'width' => 200.0, 'height' => 200.0, 'fill' => 'transparent', 'stroke' => 'transparent'],
            // 16:9, the shape most videos are; it plays on the board with black bars round any other
            'video' => [...$base, 'width' => 480.0, 'height' => 270.0, 'fill' => '#000000', 'stroke' => 'transparent'],
            // A formula is its own picture: no box, no outline, just the maths
            'math' => [...$base, 'width' => 260.0, 'height' => 90.0, 'fill' => 'transparent',
                'stroke' => 'transparent', 'fontSize' => 24.0],
            'cylinder' => [...$base, 'width' => 180.0, 'height' => 200.0, 'fill' => '#e0e7ff', 'stroke' => '#6366f1'],
            self::CONNECTOR, 'draw' => [...$base, 'width' => 0.0, 'height' => 0.0, 'fill' => 'transparent', 'stroke' => '#0f172a'],
            default => $base,
        };
    }

    /** Coordinates are reported whole: nothing on a board needs a half pixel. */
    private static function round(mixed $number): float
    {
        return round((float) (is_numeric($number) ? $number : 0), 0);
    }
}
