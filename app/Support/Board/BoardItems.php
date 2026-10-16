<?php

namespace App\Support\Board;

/**
 * Translates between the board canvas's items and the short form MCP clients
 * read and write.
 *
 * A canvas item (resources/js/features/boards/model/items.ts) carries twenty-odd
 * fields, most of which matter to one kind of shape only. A client sends the
 * few that say what it wants -- a kind, a label, maybe a box -- and this fills
 * the rest in with the defaults the toolbar uses, lays out whatever arrived
 * without coordinates, and pins each connector to the edge of the shape it
 * names, facing the shape at its other end.
 */
class BoardItems
{
    public const CONNECTOR = 'arrow';

    /** A part of a circuit, drawn from the symbol library (Symbols). */
    public const SYMBOL = 'symbol';

    /** Every kind the canvas draws by itself; the shape library adds its own (kinds()). */
    /**
     * How an item is painted beyond its colours, each a number kept only when
     * said: rounded corners, how see-through, which way a gradient runs, the
     * point of a picture kept in view, and the moment of a video shown still.
     */
    public const LOOKS = [
        'radius', 'opacity', 'gradientAngle', 'focusX', 'focusY', 'posterAt',
        'fillOpacity', 'strokeOpacity', 'strokeAngle',
        'shadowBlur', 'shadowX', 'shadowY', 'shadowOpacity', 'glowSize', 'blur', 'letterSpacing',
    ];

    /**
     * The same, in words: a second colour a fill or a line fades to, which
     * way a fade runs, a shadow's and a glow's colours, how it mixes with
     * what is under it, a second colour for its words, a video's poster,
     * and a frame's notes and part of the deck.
     */
    public const WORDS = ['gradient', 'gradientType', 'strokeGradient', 'shadowColor', 'glow', 'blend', 'colorTo', 'poster', 'notes', 'section'];

    /** Fades through more colours than two: each stop where it falls, 0 to 1. */
    public const STOPS = ['gradientStops', 'strokeStops'];

    /** How an item can mix with what is under it, as the canvas names them. */
    public const BLENDS = ['source-over', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'soft-light'];

    /** Kinds that can be as tall as their words: their label sits a padding in. */
    public const HUGGING = ['rect', 'sticky', 'pill', 'process'];

    public const KINDS = [
        'frame', 'sticky', 'text', 'rect', 'pill', 'ellipse', 'triangle',
        'diamond', 'hexagon', 'star', 'cylinder', 'parallelogram', 'document',
        'process', 'cloud', 'image', 'video', 'math', self::SYMBOL, self::CONNECTOR, 'draw', self::PATH,
    ];

    /** A vector path: SVG path data, drawn scaled from its own box to the item's. */
    public const PATH = 'path';

    /** Kinds a connector may pin itself to, besides the library's shapes (connectable()). */
    public const CONNECTABLE = [
        'sticky', 'text', 'rect', 'pill', 'ellipse', 'triangle', 'diamond',
        'hexagon', 'star', 'cylinder', 'parallelogram', 'document', 'process',
        'cloud', 'image', 'video', 'math', self::SYMBOL,
    ];

    public const SIDES = BoardPins::SIDES;

    public const ROUTINGS = ['elbow', 'straight', 'curved'];

    public const LINE_STYLES = ['solid', 'dashed', 'dotted'];

    /**
     * What an end of a connector is capped with: the plain caps, the hollow
     * ones of a class diagram, and the crow's feet of a data model.
     */
    public const HEADS = [
        'none', 'arrow', 'open', 'circle', 'diamond', 'bar',
        'triangle', 'diamond_open', 'circle_open',
        'one_only', 'many', 'one_many', 'zero_one', 'zero_many',
    ];

    /**
     * Every kind the canvas can draw: its own, and each shape in the library.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [...self::KINDS, ...Shapes::kinds()];
    }

    /**
     * Every kind a connector may pin itself to.
     *
     * @return list<string>
     */
    public static function connectable(): array
    {
        return [...self::CONNECTABLE, ...Shapes::kinds()];
    }

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

            // A ready-made style first, so that a colour said as well wins
            $styled = is_string($spec['style'] ?? null) ? Styles::parse($spec['style']) : null;

            if ($styled !== null) {
                $item = [...$item, ...Styles::styled($kind, ...$styled)];
            }

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

            // A lane's title along its top, or down its side; or another of
            // the ways a kind is drawn -- a database as a card with an icon
            if (in_array($spec['variant'] ?? null, ['top', 'left', ...Shapes::designs($kind)], true)) {
                $item['variant'] = $spec['variant'];
            }

            if (is_string($spec['color'] ?? null)) {
                $item['color'] = $spec['color'];
            }

            if (is_bool($spec['shadow'] ?? null)) {
                $item['shadow'] = $spec['shadow'];
            }

            // How it is painted beyond its colours, a frame's notes and part
            // of the deck, and a video's still: each kept only when said
            foreach (self::LOOKS as $look) {
                if (is_numeric($spec[$look] ?? null)) {
                    $item[$look] = (float) $spec[$look];
                }
            }

            foreach (self::WORDS as $words) {
                if (is_string($spec[$words] ?? null)) {
                    $item[$words] = $spec[$words];
                }
            }

            foreach (self::STOPS as $stops) {
                if (is_array($spec[$stops] ?? null)) {
                    $item[$stops] = array_values(array_map(
                        fn (array $stop) => ['at' => min(1.0, max(0.0, (float) ($stop['at'] ?? 0))), 'color' => (string) ($stop['color'] ?? '#000000')],
                        array_filter($spec[$stops], 'is_array'),
                    ));
                }
            }

            foreach (['hug', 'caps'] as $flag) {
                if (is_bool($spec[$flag] ?? null)) {
                    $item[$flag] = $spec[$flag];
                }
            }

            // A slide's animations and how the show moves to it (Animations);
            // an empty list takes them off
            if ($kind === 'frame' && is_array($spec['build'] ?? null)) {
                $item['build'] = Animations::clean($spec['build']);
            }

            if ($kind === 'frame' && in_array($spec['transition'] ?? null, Animations::transitions(), true)) {
                $item['transition'] = $spec['transition'];
            }

            // A table's columns, a class's fields and methods: kept as rows,
            // and the shape made as tall as they need unless told a height
            $rows = BoardRows::kindOf($kind);

            if ($rows !== null) {
                $given = is_array($spec['rows'] ?? null);

                if ($given) {
                    $item['rows'] = BoardRows::fromSpec($spec['rows'], $rows, is_array($item['rows'] ?? null) ? $item['rows'] : []);
                }

                if (! is_numeric($spec['height'] ?? null) && ($given || ! isset($existing[$id]) || array_key_exists('text', $spec) || array_key_exists('fontSize', $spec))) {
                    $item['height'] = BoardRows::height($item);
                }
            }

            // A part is the size the library draws it, unless told otherwise:
            // when it is new, and when it becomes another part
            if ($kind === self::SYMBOL) {
                $was = $item['symbol'] ?? '';
                $symbol = Symbols::find(is_string($spec['symbol'] ?? null) ? $spec['symbol'] : $was) ?? Symbols::find('resistor');
                $item['symbol'] = $symbol['id'];

                foreach (['width', 'height'] as $side) {
                    if (! is_numeric($spec[$side] ?? null) && ($was !== $symbol['id'] || ! isset($existing[$id]))) {
                        $item[$side] = (float) $symbol[$side];
                    }
                }
            }

            foreach (['hidden', 'locked', 'rich', 'pdfHidden', 'on', 'bare'] as $flag) {
                if (is_bool($spec[$flag] ?? null)) {
                    $item[$flag] = $spec[$flag];
                }
            }

            // A path's points: measured, moved to start at its corner, and the
            // item put where they were -- or, given x and y, where those say
            if ($kind === self::PATH && is_string($spec['d'] ?? null)) {
                $segments = PathData::parse($spec['d']);
                $box = PathData::bounds($segments);
                $placed = is_numeric($spec['x'] ?? null) && is_numeric($spec['y'] ?? null);
                $item['d'] = PathData::toString(PathData::translate($segments, -$box['x'], -$box['y']));
                $item['viewWidth'] = max(1.0, $box['width']);
                $item['viewHeight'] = max(1.0, $box['height']);
                $item['x'] = ($placed ? (float) $spec['x'] : 0.0) + $box['x'];
                $item['y'] = ($placed ? (float) $spec['y'] : 0.0) + $box['y'];
                $item['width'] = is_numeric($spec['width'] ?? null) ? (float) $spec['width'] : $item['viewWidth'];
                $item['height'] = is_numeric($spec['height'] ?? null) ? (float) $spec['height'] : $item['viewHeight'];
                $spec['x'] ??= $item['x'];
                $spec['y'] ??= $item['y'];
            }

            // A pencil stroke's points, in board units -- from x and y when
            // given, else where they say -- kept from its first point, as a
            // hand's are
            // -- or drawn along path data, straight and curved as it says
            $drawn = $kind === 'draw' && ! is_array($spec['points'] ?? null) && is_string($spec['d'] ?? null)
                ? PathData::sample(PathData::parse($spec['d']))
                : null;

            if ($kind === 'draw' && (is_array($spec['points'] ?? null) || $drawn !== null)) {
                $flat = self::flatPoints($drawn ?? $spec['points']);

                if (count($flat) >= 2) {
                    $placed = is_numeric($spec['x'] ?? null) && is_numeric($spec['y'] ?? null);
                    [$firstX, $firstY] = [$flat[0], $flat[1]];
                    $item['points'] = array_map(
                        fn (float $value, int $at) => round($value - ($at % 2 === 0 ? $firstX : $firstY), 2),
                        $flat,
                        array_keys($flat),
                    );
                    $item['x'] = ($placed ? (float) $spec['x'] : 0.0) + $firstX;
                    $item['y'] = ($placed ? (float) $spec['y'] : 0.0) + $firstY;
                    $spec['x'] ??= $item['x'];
                    $spec['y'] ??= $item['y'];

                    // Drawn by hand, a little unsteady: as much as asked
                    if (is_numeric($spec['rough'] ?? null) || ($spec['rough'] ?? false) === true) {
                        $item['points'] = self::wobbled($item['points'], $spec['rough'] === true ? 1.0 : (float) $spec['rough'], (string) ($spec['id'] ?? ''));
                    }
                }
            }

            // How hard a stylus pressed at each point, 0 to 1: kept only
            // with one for every point, as the canvas draws them
            if ($kind === 'draw' && is_array($spec['pressures'] ?? null)) {
                $pressures = array_map(fn ($value) => round(max(0.0, min(1.0, (float) $value)), 2), array_values(array_filter($spec['pressures'], 'is_numeric')));

                if (count($pressures) === intdiv(count($item['points'] ?? []), 2) && $pressures !== []) {
                    $item['pressures'] = $pressures;
                }
            }

            // How heavy pencil ink is: its own width, 3 as a hand draws it
            if ($kind === 'draw' && is_numeric($spec['lineWidth'] ?? null)) {
                $item['inkWidth'] = max(0.5, min(40.0, (float) $spec['lineWidth']));
            }

            // A text item is only its words: unless told how tall it is, it is as
            // tall as they need, the way it grows on the canvas
            if ($kind === 'text' && ! is_numeric($spec['height'] ?? null)) {
                $item['height'] = max((float) $item['height'], ceil(LabelFit::needed($item)));
            }

            // One that hugs its words is as tall as they are and its padding,
            // whatever height it was given -- as on the canvas (labels.ts)
            $hugged = self::hugged($item);

            if ($hugged !== null) {
                $item['height'] = $hugged;
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
                $pinned = BoardPins::pin($item, $specs[$position], $boxes);

                // A line onto a pin is a wire, and a wire has no head: a new
                // one gets none unless it was asked for
                if (! isset($existing[$item['id']]) && ! isset($specs[$position]['endHead'])
                    && (isset($pinned['from']['pin']) || isset($pinned['to']['pin']))) {
                    $pinned['endHead'] = 'none';
                }

                $items[$position] = $pinned;
            }
        }

        // A slide's animations only of items still on the board
        $ids = array_fill_keys(array_column($items, 'id'), true);

        // Nothing left painted under the frame it sits on, as the canvas keeps it
        return BoardParts::keepOnFrames(array_map(function (array $item) use ($ids) {
            unset($item['placed']);

            if (is_array($item['build'] ?? null)) {
                $item['build'] = Animations::clean($item['build'], $ids);
            }

            return $item;
        }, $items));
    }

    /**
     * Points a little unsteady, as a hand draws: each moved across the line
     * by a smooth wobble up to `$how` units (0-3) -- the same each time for
     * one id, so writing a board again does not redraw its sketch.
     *
     * @param  list<float>  $points
     * @return list<float>
     */
    public static function wobbled(array $points, float $how, string $id): array
    {
        $how = max(0.0, min(3.0, $how));
        $seed = (crc32($id) % 1000) / 100;

        foreach ($points as $at => $value) {
            $n = intdiv($at, 2);
            $points[$at] = round($value + $how * (sin($n * 0.9 + $seed + ($at % 2) * 2.1) + 0.5 * sin($n * 0.37 + $seed * 1.7)), 2);
        }

        return $points;
    }

    /**
     * Pencil strokes asked for along path data that lifts off and goes on
     * (several M): one stroke each, as a hand draws them -- the first under
     * the id given, the rest after it (id-2, id-3 ...).
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<array<string, mixed>>
     */
    public static function strokesApart(array $specs): array
    {
        $apart = [];

        foreach ($specs as $spec) {
            if (($spec['kind'] ?? null) !== 'draw' || isset($spec['points']) || ! is_string($spec['d'] ?? null)) {
                $apart[] = $spec;

                continue;
            }

            $pieces = [];

            foreach (PathData::parse($spec['d']) as $segment) {
                if ($segment['type'] === 'M' || $pieces === []) {
                    $pieces[] = [];
                }

                $pieces[count($pieces) - 1][] = $segment;
            }

            foreach ($pieces as $n => $piece) {
                $apart[] = [
                    ...$spec,
                    'd' => PathData::toString($piece),
                    ...(is_string($spec['id'] ?? null) && $n > 0 ? ['id' => $spec['id'].'-'.($n + 1)] : []),
                ];
            }
        }

        return $apart;
    }

    /** The most points a pencil stroke keeps: a long scribble, not a picture. */
    public const MOST_POINTS = 4000;

    /**
     * Points as [x, y, x, y, ...] -- given that way, or as [x, y] pairs --
     * numbers only, at most MOST_POINTS of them.
     *
     * @param  array<mixed>  $points
     * @return list<float>
     */
    public static function flatPoints(array $points): array
    {
        $flat = [];

        foreach ($points as $point) {
            if (is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)) {
                array_push($flat, (float) $point[0], (float) $point[1]);
            } elseif (is_numeric($point)) {
                $flat[] = (float) $point;
            }
        }

        $flat = array_slice($flat, 0, self::MOST_POINTS);

        return count($flat) % 2 === 0 ? $flat : array_slice($flat, 0, -1);
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

            if ($kind === self::SYMBOL) {
                $spec['symbol'] = (string) ($item['symbol'] ?? '');
            }

            // A pencil stroke's ink, from its first point: without it, changed
            // or written again, it would be drawn as nothing -- and how heavy
            if ($kind === 'draw' && is_array($item['points'] ?? null)) {
                $spec['points'] = array_map(fn ($value) => round((float) $value, 1), array_values($item['points']));

                if (is_array($item['pressures'] ?? null) && $item['pressures'] !== []) {
                    $spec['pressures'] = array_map(fn ($value) => round((float) $value, 2), array_values($item['pressures']));
                }
            }

            // A path's points, in its own box, and how big that box is
            if ($kind === self::PATH) {
                $spec['d'] = (string) ($item['d'] ?? '');
                $spec['viewWidth'] = round((float) ($item['viewWidth'] ?? 1), 2);
                $spec['viewHeight'] = round((float) ($item['viewHeight'] ?? 1), 2);
            }

            if (trim((string) ($item['text'] ?? '')) !== '') {
                $spec['text'] = (string) $item['text'];
            }

            // What is inside a table or a class, a row at a time
            if (BoardRows::kindOf($kind) !== null && is_array($item['rows'] ?? null)) {
                $spec['rows'] = BoardRows::toSpec($item['rows'], (string) BoardRows::kindOf($kind));
            }

            $variant = $item['variant'] ?? 'top';

            if ($variant === 'left' || ($variant !== 'classic' && in_array($variant, Shapes::designs($kind), true))) {
                $spec['variant'] = $variant;
            }

            if (is_string($item['color'] ?? null) && $item['color'] !== '') {
                $spec['color'] = $item['color'];
            }

            if (($item['shadow'] ?? false) === true) {
                $spec['shadow'] = true;
            }

            // Kept to a fraction: opacity and a focus run from 0 to 1
            foreach (self::LOOKS as $look) {
                if (is_numeric($item[$look] ?? null)) {
                    $spec[$look] = round((float) $item[$look], 3);
                }
            }

            foreach (self::WORDS as $words) {
                if (is_string($item[$words] ?? null) && $item[$words] !== '') {
                    $spec[$words] = $item[$words];
                }
            }

            foreach (self::STOPS as $stops) {
                if (is_array($item[$stops] ?? null) && $item[$stops] !== []) {
                    $spec[$stops] = $item[$stops];
                }
            }

            foreach (['hug', 'caps'] as $flag) {
                if (($item[$flag] ?? false) === true) {
                    $spec[$flag] = true;
                }
            }

            if (is_array($item['build'] ?? null) && $item['build'] !== []) {
                $spec['build'] = $item['build'];
            }

            if (is_string($item['transition'] ?? null) && $item['transition'] !== 'glide') {
                $spec['transition'] = $item['transition'];
            }

            foreach (['fill', 'stroke'] as $colour) {
                $value = (string) ($item[$colour] ?? '');

                // "transparent" is said where it was chosen -- a shape made
                // see-through -- not where the kind never has one, as text
                if ($value !== '' && ($value !== 'transparent' || self::blank($kind, 0)[$colour] !== 'transparent')) {
                    $spec[$colour] = $value;
                }
            }

            // A symbol's words are set small beside it, always the same size
            foreach (['rotation' => 0, 'fontSize' => $kind === self::SYMBOL ? 13 : 16, 'lineWidth' => 2, 'headSize' => 10] as $number => $default) {
                if (isset($item[$number]) && self::round($item[$number]) !== (float) $default) {
                    $spec[$number] = self::round($item[$number]);
                }
            }

            // Pencil ink is as heavy as its own width says
            if ($kind === 'draw') {
                unset($spec['lineWidth']);

                if (is_numeric($item['inkWidth'] ?? null) && (float) $item['inkWidth'] !== 3.0) {
                    $spec['lineWidth'] = self::round($item['inkWidth']);
                }
            }

            foreach (['hidden', 'locked', 'rich', 'pdfHidden', 'on', 'bare'] as $flag) {
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

            // A style put on takes the place of the colours it had, but not
            // of any sent with it
            if (is_string($spec['style'] ?? null)) {
                foreach (['fill', 'stroke', 'color', 'lineWidth'] as $field) {
                    if (! array_key_exists($field, $spec)) {
                        unset($merged[$field]);
                    }
                }
            }

            // New words in a text item: let it grow to them, unless a height is given
            if (($merged['kind'] ?? null) === 'text' && array_key_exists('text', $spec) && ! array_key_exists('height', $spec)) {
                unset($merged['height']);
            }

            // New points for a path, or a pencil stroke, are where they say, the
            // size they say: its old place and size are kept only when sent with them
            if ((($merged['kind'] ?? null) === self::PATH && array_key_exists('d', $spec))
                || (($merged['kind'] ?? null) === 'draw' && array_key_exists('points', $spec))) {
                foreach (['x', 'y', 'width', 'height'] as $field) {
                    if (! array_key_exists($field, $spec)) {
                        unset($merged[$field]);
                    }
                }
            }

            // New rows in a table or a class, or a longer name: as tall as they now need
            if (BoardRows::kindOf((string) ($merged['kind'] ?? '')) !== null
                && array_intersect(['rows', 'text', 'fontSize'], array_keys($spec)) !== []
                && ! array_key_exists('height', $spec)) {
                unset($merged['height']);
            }

            $specs[$id] = $merged;
        }

        $added = [];
        // Past the ids taken off too, so a made-up one is never one going
        $next = self::nextNumber([...array_keys($specs), ...$delete]);

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

        $unnamed = self::symbolProblem($specs);

        if ($unnamed !== null) {
            throw new BoardItemProblem($unnamed);
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
     * The height an item that hugs its words should have -- the words and the
     * padding above and below them -- or null for one that doesn't hug.
     *
     * @param  array<string, mixed>  $item
     */
    public static function hugged(array $item): ?float
    {
        if (($item['hug'] ?? false) !== true || ! in_array($item['kind'] ?? '', self::HUGGING, true) || trim((string) ($item['text'] ?? '')) === '') {
            return null;
        }

        return ceil(LabelFit::needed($item) + 2 * (float) ($item['padding'] ?? 12));
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
        $symbols = [];
        $rows = [];

        foreach (self::withIds($specs) as $spec) {
            $kinds[$spec['id']] = is_string($spec['kind'] ?? null) ? $spec['kind'] : 'sticky';

            // The rows a table or a class is sent with, for an end that names one
            if (is_array($spec['rows'] ?? null) && BoardRows::kindOf($kinds[$spec['id']]) !== null) {
                $rows[$spec['id']] = BoardRows::fromSpec($spec['rows'], (string) BoardRows::kindOf($kinds[$spec['id']]));
            }
            $symbols[$spec['id']] = Symbols::find(is_string($spec['symbol'] ?? null) ? $spec['symbol'] : null);
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
                } elseif (! in_array($kinds[$item], self::connectable(), true)) {
                    $problems[] = "\"{$end}\" points at \"{$item}\", which is a {$kinds[$item]}; connectors can only pin to a shape, a sticky, a text or a picture.";
                } elseif (is_string($spec[$end]['pin'] ?? null) && $spec[$end]['pin'] !== '') {
                    $symbol = $symbols[$item];
                    $pin = $spec[$end]['pin'];

                    if (BoardRows::kindOf($kinds[$item]) !== null) {
                        // A row of a table or a class: one of those it is sent with
                        if (isset($rows[$item]) && BoardRows::named(['rows' => $rows[$item]], $pin) === null) {
                            $problems[] = "\"{$end}\" names row \"{$pin}\" of \"{$item}\", whose rows are ".BoardRows::names(['rows' => $rows[$item]]).'.';
                        }
                    } elseif ($symbol === null) {
                        $problems[] = "\"{$end}\" names pin \"{$pin}\" of \"{$item}\", which is a {$kinds[$item]}; only a symbol has pins, and a table or a class its rows.";
                    } elseif (Symbols::pinName($symbol, $pin) === null) {
                        $problems[] = "\"{$end}\" names pin \"{$pin}\" of \"{$item}\", a {$symbol['id']}, whose pins are ".implode(', ', array_column($symbol['pins'], 'name')).'.';
                    }
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * What is wrong with the symbols in a client's list: one that does not
     * say which part it is, and is not already one on the board.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  list<mixed>  $current  the board's items as stored
     */
    public static function symbolProblem(array $specs, array $current = []): ?string
    {
        $known = [];

        foreach ($current as $item) {
            if (is_array($item) && ($item['kind'] ?? '') === self::SYMBOL) {
                $known[(string) ($item['id'] ?? '')] = true;
            }
        }

        foreach ($specs as $spec) {
            if (($spec['kind'] ?? null) === self::SYMBOL
                && ! is_string($spec['symbol'] ?? null)
                && ! isset($known[(string) ($spec['id'] ?? '')])) {
                return 'An item of kind "symbol" needs a "symbol" to say which part it is: '.implode(', ', Symbols::ids()).' -- or "chip:VCC,GND|OUT" for a chip with pins of your own.';
            }
        }

        return null;
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
            // Drawn in ink, with its name and value set small beside it; its size is its symbol's own
            self::SYMBOL => [...$base, 'symbol' => '', 'on' => false, 'width' => 80.0, 'height' => 40.0, 'fill' => 'transparent',
                'stroke' => '#0f172a', 'fontSize' => 13.0, 'padding' => 0.0],
            self::CONNECTOR, 'draw' => [...$base, 'width' => 0.0, 'height' => 0.0, 'fill' => 'transparent', 'stroke' => '#0f172a'],
            // A line until it is given a fill: open paths are lines, closed ones shapes
            self::PATH => [...$base, 'd' => '', 'viewWidth' => 1.0, 'viewHeight' => 1.0, 'fill' => 'transparent', 'stroke' => '#0f172a', 'lineWidth' => 3.0],
            // A shape from the library starts as the library says, as it does from the toolbar
            default => self::fromLibrary($kind, $base),
        };
    }

    /**
     * A shape from the library as it starts: the library's own size, colours
     * and words, and for a table or a class the rows it begins with.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private static function fromLibrary(string $kind, array $base): array
    {
        $item = [...$base, ...Shapes::defaults($kind)];
        $rows = BoardRows::kindOf($kind);

        if ($rows !== null) {
            $item['rows'] = BoardRows::fromSpec(is_array($item['rows'] ?? null) ? $item['rows'] : [], $rows);
            $item['height'] = BoardRows::height($item);
        }

        return $item;
    }

    /** Coordinates are reported whole: nothing on a board needs a half pixel. */
    private static function round(mixed $number): float
    {
        return round((float) (is_numeric($number) ? $number : 0), 0);
    }
}
