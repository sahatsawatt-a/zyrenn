<?php

namespace App\Mcp\Tools;

use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use App\Models\Owner;
use App\Support\Board\Animations;
use App\Support\Board\BoardActions;
use App\Support\Board\BoardItems;
use App\Support\Board\BoardRows;
use App\Support\Board\Shapes;
use App\Support\Board\Styles;
use App\Support\Board\Symbols;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

/**
 * Base for the board tools shared by both MCP servers. Finding, listing and
 * folders are FiledTool's; boards add their items.
 *
 * @extends FiledTool<BoardFolder, Board>
 */
abstract class BoardTool extends FiledTool
{
    /** No board needs more on it than this in one call. */
    protected const MAX_ITEMS = 300;

    /** Nor a circuit longer than this, written as text. */
    protected const MAX_CIRCUIT = 8000;

    protected function noun(): string
    {
        return 'board';
    }

    /**
     * @return HasMany<BoardFolder, covariant Model&Owner>
     */
    protected function folders(Owner $owner): HasMany
    {
        return $owner->boardFolders();
    }

    /**
     * @return HasMany<Board, covariant Model&Owner>
     */
    protected function things(Owner $owner): HasMany
    {
        // A prototype's screens are a board's frames: the same tools edit them
        return $owner->boardsAndPrototypes();
    }

    protected function searchIn(): array
    {
        return ['title' => 'title', 'plain_text' => 'labels'];
    }

    /**
     * @param  Board  $thing
     */
    protected function details(Model $thing): array
    {
        return [
            'item_count' => $thing->itemCount(),
            // A prototype says so, and what its screens are sized for
            ...($thing->kind === Board::PROTOTYPE
                ? ['kind' => Board::PROTOTYPE, 'device' => $thing->prototype['device'] ?? null]
                : []),
        ];
    }

    /**
     * A prototype is opened among its project's prototypes, not as a board.
     *
     * @param  Board  $thing
     */
    protected function urlOf(Model $thing): string
    {
        return $thing->kind === Board::PROTOTYPE ? route('prototypes.show', $thing) : parent::urlOf($thing);
    }

    /**
     * Schema for one action in a prototype's Play. Steps inside an "if" or
     * "all" are actions too; BoardActions reads them, whatever their depth.
     */
    protected function actionArgument(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'to' => $schema->string()->max(255)->description('Go to a screen: a frame\'s id or title.'),
            'overlay' => $schema->string()->enum(BoardActions::OVERLAY_PLACES)->description('With "to": open that screen over this one instead, placed here (center, bottom, top, left, right), dimmed behind.'),
            'transition' => $schema->string()->enum(BoardActions::TRANSITIONS)->description('With "to" or "back": how the move looks. Left out, slide.'),
            'back' => $schema->boolean()->description('true: back to the screen before.'),
            'close' => $schema->boolean()->description('true: close the overlay on top.'),
            'toggle' => $schema->array()->items($schema->string())->description('Items on this screen, by id, to show if hidden and hide if shown.'),
            'show' => $schema->array()->items($schema->string())->description('Items to show, by id.'),
            'hide' => $schema->array()->items($schema->string())->description('Items to hide, by id.'),
            'set' => $schema->string()->max(40)->description('A value\'s name to set, with "value".'),
            'value' => $schema->string()->description('With "set": a word, or true or false.'),
            'if' => $schema->string()->max(40)->description('A value\'s name: do "then" when it is set (or, with "equals", equal to that), else "else".'),
            'equals' => $schema->string()->description('With "if": what the value has to be.'),
            'then' => $schema->object()->description('With "if": an action.'),
            'else' => $schema->object()->description('With "if": an action, when the condition fails.'),
            'all' => $schema->array()->items($schema->object())->description('Several actions, in order -- a move, if any, last.'),
            'save' => $schema->string()->max(255)->description('A table of this project (ref_id or title) to save a row to: a new one, or with "row": "current" the current one.'),
            'row' => $schema->string()->enum(['new', 'current'])->description('With "save": add a row (the default) or change the current one.'),
            'values' => $schema->object()->description('With "save": column label => words, which may say {name}; columns left out take the fields named like them.'),
            'remove' => $schema->string()->max(255)->description('A table of this project (ref_id or title): its current row deleted.'),
        ])->description('One of: {"to": screen, "transition"?, "overlay"?} · {"back": true} · {"close": true} · {"toggle"|"show"|"hide": [item ids]} · {"set": name, "value": text or true/false} · {"if": name, "equals"?, "then": action, "else"?: action} · {"all": [actions]} · {"save": table, "row"?: "new"|"current", "values"?: {column: words}} · {"remove": table}.');
    }

    /**
     * Schema for the list of things to put on a board.
     */
    protected function itemsArgument(JsonSchema $schema, string $description, bool $kindRequired = true): Type
    {
        return $schema->array()
            ->items($this->itemArgument($schema, $kindRequired))
            ->max(self::MAX_ITEMS)
            ->description($description);
    }

    /**
     * A list of items whose fields are written out once, in `$like` beside
     * it, rather than again here: an item's fields are most of what a board
     * tool says, so each copy is thousands of tokens read for nothing. Kept
     * to the same rules all the same (itemRules).
     */
    protected function itemsLike(JsonSchema $schema, string $description, bool $withId = false): Type
    {
        return $schema->array()
            ->items($withId ? $schema->object(['id' => $schema->string()->max(64)->required()]) : $schema->object())
            ->max(self::MAX_ITEMS)
            ->description($description);
    }

    /**
     * Schema for a circuit written as text, which is laid out on the board.
     */
    protected function circuitArgument(JsonSchema $schema, string $description): Type
    {
        return $schema->string()->max(self::MAX_CIRCUIT)->description($description);
    }

    /**
     * Validation rules for that list, keyed for `items.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(bool $sometimes = false, string $key = 'items', bool $kindRequired = true): array
    {
        $kinds = implode(',', BoardItems::kinds());
        $sides = implode(',', BoardItems::SIDES);
        $heads = implode(',', BoardItems::HEADS);

        return [
            $key => [$sometimes ? 'sometimes' : 'nullable', 'array', 'max:'.self::MAX_ITEMS],
            "{$key}.*" => ['array'],
            "{$key}.*.id" => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            "{$key}.*.kind" => [$kindRequired ? 'required' : 'nullable', 'string', 'in:'.$kinds],
            "{$key}.*.x" => ['nullable', 'numeric', 'between:-100000,100000'],
            "{$key}.*.y" => ['nullable', 'numeric', 'between:-100000,100000'],
            "{$key}.*.width" => ['nullable', 'numeric', 'between:1,20000'],
            "{$key}.*.height" => ['nullable', 'numeric', 'between:1,20000'],
            "{$key}.*.rotation" => ['nullable', 'numeric', 'between:-360,360'],
            "{$key}.*.text" => ['nullable', 'string', 'max:5000'],
            "{$key}.*.fill" => ['nullable', 'string', 'max:32'],
            "{$key}.*.stroke" => ['nullable', 'string', 'max:32'],
            "{$key}.*.fontSize" => ['nullable', 'numeric', 'between:6,400'],
            "{$key}.*.align" => ['nullable', 'string', 'in:'.implode(',', BoardItems::ALIGNS)],
            "{$key}.*.verticalAlign" => ['nullable', 'string', 'in:'.implode(',', BoardItems::VERTICAL_ALIGNS)],
            "{$key}.*.hidden" => ['nullable', 'boolean'],
            "{$key}.*.locked" => ['nullable', 'boolean'],
            "{$key}.*.src" => ['nullable', 'string', 'max:200', 'regex:#^(https?://|/drive/files/)#'],
            "{$key}.*.fit" => ['nullable', 'string', 'in:'.implode(',', BoardItems::FITS)],
            "{$key}.*.fontFamily" => ['nullable', 'string', 'in:'.implode(',', BoardItems::FONTS)],
            "{$key}.*.padding" => ['nullable', 'numeric', 'between:0,200'],
            "{$key}.*.rich" => ['nullable', 'boolean'],
            "{$key}.*.on" => ['nullable', 'boolean'],
            "{$key}.*.symbol" => ['nullable', 'string', 'max:600', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && Symbols::find($value) === null) {
                    $fail('"'.$value.'" is not a symbol. Pick one of: '.implode(', ', Symbols::ids()).'; or write a chip with your own pins as "chip:VCC,GND|OUT".');
                }
            }],
            "{$key}.*.from.pin" => ['nullable', 'string', 'max:120'],
            "{$key}.*.to.pin" => ['nullable', 'string', 'max:120'],
            "{$key}.*.from.along" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.to.along" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.variant" => ['nullable', 'string', 'max:32'],
            "{$key}.*.style" => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && Styles::parse($value) === null) {
                    $fail('"'.$value.'" is not a style. Write it as style:colour -- styles '.implode(', ', Styles::styleIds()).'; colours '.implode(', ', Styles::colourNames()).'.');
                }
            }],
            "{$key}.*.color" => ['nullable', 'string', 'max:32'],
            "{$key}.*.shadow" => ['nullable', 'boolean'],
            "{$key}.*.sketch" => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_bool($value) && ! in_array($value, BoardItems::SKETCHES, false)) {
                    $fail('"sketch" is 0 (crisp), 1 (a little rough) or 2 (a lot), or true for 1.');
                }
            }],
            "{$key}.*.hatch" => ['nullable', 'string', 'in:'.implode(',', BoardItems::HATCHES)],
            "{$key}.*.d" => ['nullable', 'string', 'max:20000'],
            "{$key}.*.points" => ['nullable', 'array', 'max:'.BoardItems::MOST_POINTS],
            "{$key}.*.rough" => ['nullable'],
            "{$key}.*.pressures" => ['nullable', 'array', 'max:'.intdiv(BoardItems::MOST_POINTS, 2)],
            "{$key}.*.radius" => ['nullable', 'numeric', 'between:0,2000'],
            "{$key}.*.opacity" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.gradient" => ['nullable', 'string', 'max:32'],
            "{$key}.*.gradientAngle" => ['nullable', 'numeric', 'between:0,360'],
            "{$key}.*.focusX" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.focusY" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.hug" => ['nullable', 'boolean'],
            "{$key}.*.caps" => ['nullable', 'boolean'],
            "{$key}.*.gradientType" => ['nullable', 'string', 'in:linear,radial'],
            "{$key}.*.gradientStops" => ['nullable', 'array', 'max:12'],
            "{$key}.*.gradientStops.*.at" => ['required', 'numeric', 'between:0,1'],
            "{$key}.*.gradientStops.*.color" => ['required', 'string', 'max:32'],
            "{$key}.*.strokeStops" => ['nullable', 'array', 'max:12'],
            "{$key}.*.strokeStops.*.at" => ['required', 'numeric', 'between:0,1'],
            "{$key}.*.strokeStops.*.color" => ['required', 'string', 'max:32'],
            "{$key}.*.strokeGradient" => ['nullable', 'string', 'max:32'],
            "{$key}.*.strokeAngle" => ['nullable', 'numeric', 'between:0,360'],
            "{$key}.*.fillOpacity" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.strokeOpacity" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.shadowColor" => ['nullable', 'string', 'max:32'],
            "{$key}.*.shadowBlur" => ['nullable', 'numeric', 'between:0,200'],
            "{$key}.*.shadowX" => ['nullable', 'numeric', 'between:-500,500'],
            "{$key}.*.shadowY" => ['nullable', 'numeric', 'between:-500,500'],
            "{$key}.*.shadowOpacity" => ['nullable', 'numeric', 'between:0,1'],
            "{$key}.*.glow" => ['nullable', 'string', 'max:32'],
            "{$key}.*.glowSize" => ['nullable', 'numeric', 'between:0,200'],
            "{$key}.*.blur" => ['nullable', 'numeric', 'between:0,100'],
            "{$key}.*.blend" => ['nullable', 'string', 'in:'.implode(',', BoardItems::BLENDS)],
            "{$key}.*.letterSpacing" => ['nullable', 'numeric', 'between:-20,100'],
            "{$key}.*.colorTo" => ['nullable', 'string', 'max:32'],
            "{$key}.*.poster" => ['nullable', 'string', 'max:200', 'regex:#^(https?://|/drive/files/)#'],
            "{$key}.*.posterAt" => ['nullable', 'numeric', 'between:0,86400'],
            "{$key}.*.notes" => ['nullable', 'string', 'max:10000'],
            "{$key}.*.section" => ['nullable', 'string', 'max:120'],
            "{$key}.*.bare" => ['nullable', 'boolean'],
            "{$key}.*.build" => ['nullable', 'array', 'max:'.Animations::most()],
            "{$key}.*.build.*" => ['array'],
            "{$key}.*.build.*.item" => ['required', 'string', 'max:64'],
            "{$key}.*.build.*.kind" => ['required', 'string', 'in:'.implode(',', Animations::kinds())],
            "{$key}.*.build.*.effect" => ['nullable', 'string', 'in:'.implode(',', Animations::allEffects())],
            "{$key}.*.build.*.start" => ['nullable', 'string', 'in:'.implode(',', Animations::starts())],
            "{$key}.*.build.*.duration" => ['nullable', 'integer', 'between:50,10000'],
            "{$key}.*.build.*.delay" => ['nullable', 'integer', 'between:0,30000'],
            "{$key}.*.build.*.from" => ['nullable', 'string', 'in:'.implode(',', Animations::froms())],
            "{$key}.*.transition" => ['nullable', 'string', 'in:'.implode(',', Animations::transitions())],
            "{$key}.*.rows" => ['nullable', 'array', 'max:'.BoardRows::MAX],
            "{$key}.*.rows.*" => ['array'],
            "{$key}.*.rows.*.id" => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            "{$key}.*.rows.*.name" => ['required', 'string', 'max:120'],
            "{$key}.*.rows.*.type" => ['nullable', 'string', 'max:80'],
            "{$key}.*.rows.*.key" => ['nullable', 'string', 'in:'.implode(',', BoardRows::KEYS)],
            "{$key}.*.rows.*.visibility" => ['nullable', 'string', 'in:'.implode(',', BoardRows::VISIBILITIES)],
            "{$key}.*.rows.*.nullable" => ['nullable', 'boolean'],
            "{$key}.*.rows.*.method" => ['nullable', 'boolean'],
            // What it does in Play: each an action, read by BoardActions
            "{$key}.*.link" => ['nullable', 'array'],
            "{$key}.*.when" => ['nullable', 'array'],
            "{$key}.*.when.*" => ['nullable', 'array'],
            "{$key}.*.screen" => ['nullable', 'array'],
            "{$key}.*.screen.after" => ['nullable', 'array'],
            "{$key}.*.screen.after.seconds" => ['required_with:'."{$key}.*.screen.after", 'numeric', 'between:0.5,30'],
            "{$key}.*.screen.after.do" => ['required_with:'."{$key}.*.screen.after", 'array'],
            "{$key}.*.repeat" => ['nullable', 'array'],
            "{$key}.*.repeat.table" => ['required_with:'."{$key}.*.repeat", 'string', 'max:255'],
            "{$key}.*.repeat.where" => ['nullable', 'array', 'max:10'],
            "{$key}.*.repeat.where.*.column" => ['required', 'string', 'max:255'],
            "{$key}.*.repeat.where.*.is" => ['present', 'nullable', 'string', 'max:255'],
            "{$key}.*.repeat.sort" => ['nullable', 'array'],
            "{$key}.*.repeat.sort.column" => ['required_with:'."{$key}.*.repeat.sort", 'string', 'max:255'],
            "{$key}.*.repeat.sort.desc" => ['nullable', 'boolean'],
            "{$key}.*.repeat.limit" => ['nullable', 'integer', 'between:'.BoardActions::ROWS[0].','.BoardActions::ROWS[1]],
            "{$key}.*.repeat.gap" => ['nullable', 'numeric', 'between:0,400'],
            "{$key}.*.repeat.across" => ['nullable', 'boolean'],
            "{$key}.*.fixed" => ['nullable', 'string', 'in:'.implode(',', BoardItems::FIXED)],
            "{$key}.*.startsHidden" => ['nullable', 'boolean'],
            "{$key}.*.field" => ['nullable', 'string', 'max:40'],
            "{$key}.*.choice" => ['nullable', 'string', 'max:40'],
            "{$key}.*.from" => ['nullable'],
            "{$key}.*.to" => ['nullable'],
            "{$key}.*.from.item" => ['nullable', 'string', 'max:64'],
            "{$key}.*.from.side" => ['nullable', 'string', 'in:'.$sides],
            "{$key}.*.from.x" => ['nullable', 'numeric', 'between:-100000,100000'],
            "{$key}.*.from.y" => ['nullable', 'numeric', 'between:-100000,100000'],
            "{$key}.*.to.item" => ['nullable', 'string', 'max:64'],
            "{$key}.*.to.side" => ['nullable', 'string', 'in:'.$sides],
            "{$key}.*.to.x" => ['nullable', 'numeric', 'between:-100000,100000'],
            "{$key}.*.to.y" => ['nullable', 'numeric', 'between:-100000,100000'],
            "{$key}.*.routing" => ['nullable', 'string', 'in:'.implode(',', BoardItems::ROUTINGS)],
            "{$key}.*.lineStyle" => ['nullable', 'string', 'in:'.implode(',', BoardItems::LINE_STYLES)],
            "{$key}.*.lineWidth" => ['nullable', 'numeric', 'between:0,40'],
            "{$key}.*.startHead" => ['nullable', 'string', 'in:'.$heads],
            "{$key}.*.endHead" => ['nullable', 'string', 'in:'.$heads],
            "{$key}.*.headSize" => ['nullable', 'numeric', 'between:4,80'],
        ];
    }

    /**
     * The client's items, back in the order they sent them.
     *
     * Validated data is rebuilt rule by rule, so "items.*.id" reaches the items
     * that carry an id before anything else reaches the rest, and they come out
     * shuffled. The original keys survive that, and a board's order is the
     * order it is painted in, so they are put back in it here.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    protected function specs(array $validated, string $key = 'items'): array
    {
        $items = is_array($validated[$key] ?? null) ? $validated[$key] : [];
        ksort($items);

        // A pencil path that lifts off and goes on: one stroke for each stretch
        /** @var list<array<string, mixed>> */
        return BoardItems::strokesApart(array_values($items));
    }

    /**
     * Labels with more words than room, said in the answer, so a client can
     * give the item the height it needs before anyone sees it run over.
     *
     * @param  list<array<string, mixed>>  $items  canvas items
     * @return array<string, mixed>
     */
    protected function overflow(array $items): array
    {
        $over = BoardItems::overflowing($items);

        return $over === [] ? [] : [
            'overflowing' => $over,
            'note' => 'These labels have more words than room, and run out past the edge of their item. Give each the height in "needs_height", widen it, or say less.',
        ];
    }

    /**
     * The trouble with a client's list that the field rules cannot see: two
     * items sharing an id, or a connector pinned to something that isn't there.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  list<mixed>  $current  what is on the board already, when it is being redrawn
     */
    protected function itemProblem(array $specs, array $current = []): ?string
    {
        $duplicates = BoardItems::duplicateIds($specs);

        if ($duplicates !== []) {
            return 'Every item needs its own id. Used twice: "'.implode('", "', $duplicates).'".';
        }

        $references = BoardItems::badReferences($specs);

        if ($references !== []) {
            return 'A connector points at something it cannot pin to. '.implode(' ', $references);
        }

        return BoardItems::symbolProblem($specs, $current);
    }

    /**
     * One item, as a client writes it. Everything but the kind is optional:
     * what is left out gets the same default the toolbar would have given it.
     */
    private function itemArgument(JsonSchema $schema, bool $kindRequired = true): Type
    {
        // The library's shapes, a group to a sentence: "UML: class, actor, ..."
        $library = collect(Shapes::byGroup())->map(fn (string $kinds, string $group) => "{$group}: {$kinds}")->implode('. ');

        $kind = $schema->string()->enum(BoardItems::kinds())->description('What to draw. Shapes: rect, pill, ellipse, triangle, diamond, hexagon, star. Flowchart: cylinder (a database), parallelogram (data), document, process (a predefined process: a box with a rail down each side -- an ordinary step is a rect), cloud. More, from the shape library -- '.$library.'. A table (of a schema) and a class hold "rows" under their name in "text"; a lane is a titled column or swimlane to put steps in; a figure such as actor, user or server is named under it; a lifeline is a participant in a sequence, with messages drawn between two of them at the same "along". Also path (a vector line or shape from SVG path data in "d": curves, waves, blobs, icons -- with a fill, gradients and effects it draws illustration), sticky, text (grows to fit its words unless given a height), frame (a 16:9 slide for present mode), image, video (plays on the board), math (a formula, with LaTeX in "text"), symbol (a part of a circuit: say which in "symbol"), arrow (a connector, or between symbols\' pins a wire) and draw (freehand ink).');

        return $schema->object([
            'id' => $schema->string()->max(64)->description('Your name for this item, so a connector can point at it (letters, digits, "-" and "_"). Made up for you if you leave it out.'),
            'kind' => $kindRequired ? $kind->required() : $kind,
            'x' => $schema->number()->description('Left edge on the board. Leave x and y out and items are laid out in rows for you.'),
            'y' => $schema->number()->description('Top edge on the board.'),
            'width' => $schema->number()->description('Width in board units; each kind has a sensible default.'),
            'height' => $schema->number()->description('Height in board units.'),
            'text' => $schema->string()->max(5000)->description('The label written on it. On a "math" item this is LaTeX, set with KaTeX, e.g. "e^{i\\pi} + 1 = 0". On a "symbol" it is the part\'s designator, then its value on a second line: "R1\n10 kΩ" (a ground, rail or terminal has one line, the net\'s name). Ink takes no label.'),
            'symbol' => $schema->string()->max(600)->description('For kind "symbol": which part, each listed with its pins -- '.Symbols::catalogue().'. A chip with pins of your own is "chip:" then the pins down its left and, after "|", those down its right: "chip:VCC,GND,SDA,SCL" or "chip:SER,CLK|QA,QB". A part is drawn at its own size with its pins on a 20-unit grid: give x and y in multiples of 20 so wires run straight, and "rotation" 90, 180 or 270 to turn it. "label" is a net label: two with the same "text" are joined without a wire between them.'),
            'fill' => $schema->string()->max(32)->description('Fill colour as hex, e.g. "#fde68a", or "transparent" for a shape with nothing behind its words.'),
            'stroke' => $schema->string()->max(32)->description('Outline (or, for a connector, line) colour as hex.'),
            'fontSize' => $schema->number()->description('Label size in board units (16 on shapes, 28 on text).'),
            'fontFamily' => $schema->string()->enum(BoardItems::FONTS)->description('The label\'s typeface: "sans" (Arial, the default), "serif" (Times New Roman), "mono" (Courier New), or written by hand -- "hand" (Caveat, an easy handwriting) or "marker" (Permanent Marker, bold felt-tip). Thai is written in a Thai hand in both.'),
            'padding' => $schema->number()->description('Room between the item\'s edge and its label, in board units: 12 on shapes, 0 on text.'),
            'rich' => $schema->boolean()->description('true: the label is light Markdown, a line at a time -- "# " / "## " / "### " headings, "- " bullets, "1. " numbered items, **bold** and *italic*, and a line of "---" for a rule across the item -- so one card can hold a heading and its points. A text item\'s words are then regular weight, not bold.'),
            'align' => $schema->string()->enum(BoardItems::ALIGNS)->description('Where the label sits across the item: left, center or right. Shapes centre it, a text item starts at the left.'),
            'verticalAlign' => $schema->string()->enum(BoardItems::VERTICAL_ALIGNS)->description('Where the label sits down the item: top, middle or bottom.'),
            'rotation' => $schema->number()->description('Degrees clockwise.'),
            'src' => $schema->string()->max(200)->description('For kind "image": the URL of the picture; for kind "video", of the video (MP4 or WebM); for kind "frame", a picture filling the slide behind everything on it. Upload it first -- request-upload for a file on your disk, or upload-file -- and pass the "url" from the response.'),
            'fit' => $schema->string()->enum(BoardItems::FITS)->description('For kind "image": "fill" stretches the picture to the box (the default), "contain" fits it whole inside, "cover" fills the box and cuts off what spills over. Its "stroke" and "lineWidth" draw a border round it.'),
            'on' => $schema->boolean()->description('For a symbol "input" (a logic input): whether it is set to 1. The board works out what its gates (and, or, not, nand, nor, xor, xnor, buffer, dff) put out from there, and lights each "output" that is fed a 1.'),
            'rows' => $schema->array()->items($schema->object([
                'id' => $schema->string()->max(64)->description('Your name for the row, for a connector\'s "pin"; its own name is used if left out.'),
                'name' => $schema->string()->max(120)->description('A column\'s name ("customer_id"), or a member\'s ("total()", with brackets for a method).')->required(),
                'type' => $schema->string()->max(80)->description('What it holds: "int", "varchar(255)", "Money".'),
                'key' => $schema->string()->enum(BoardRows::KEYS)->description('On a table: pk (primary key), fk (foreign key) or uk (unique).'),
                'nullable' => $schema->boolean()->description('On a table: true when the column may be left empty.'),
                'visibility' => $schema->string()->enum(BoardRows::VISIBILITIES)->description('On a class: + public (the default), - private, # protected, ~ package.'),
                'method' => $schema->boolean()->description('On a class: true for a method, listed under the fields. A name with brackets is taken for one.'),
            ]))->description('For kind "table" or "class": what is inside it, a row to each column or member, with the table\'s or class\'s name in "text". The item is made as tall as its rows need. Sent again, the list replaces the one there; a row keeps its id by its name, so connectors on it stay. A foreign key is drawn as an arrow from {"item": "orders", "pin": "customer_id"} to {"item": "customers", "pin": "id"}, with "endHead" and "startHead" saying how many (one_only, zero_many, ...).'),
            'variant' => $schema->string()->max(32)->description('For kind "lane": where its title runs -- "top" for a column, "left" for a swimlane lying on its side. For a database (cylinder), a server, a user and the other architecture shapes: which of their designs -- "classic" (the default), "card" (a rounded card with the thing\'s icon over its label), "badge" (the icon on a disc, named under it) or "icon" (the icon alone); a database also "stacked" or "shaded", a server "shaded".'),
            'style' => $schema->string()->max(40)->description('A ready-made look, as "style:colour": style one of '.implode(', ', Styles::styleIds()).' -- soft (a pale fill, a coloured line, dark words), solid (a strong fill, white words), outline, bold (a dark fill), light -- and colour one of '.implode(', ', Styles::colourNames()).'. It sets fill, stroke, the words\' "color" and the line width the way the board\'s Style picker does; any of those sent as well wins. Keep to a few colours across a board and one style for things of a kind: "solid:indigo" for services, "soft:emerald" for stores, "soft:slate" for the rest.'),
            'color' => $schema->string()->max(32)->description('The colour the label is written in, as hex. Left out, it is whatever reads best on the fill.'),
            'shadow' => $schema->boolean()->description('true: a soft shadow under it, lifting it off the board.'),
            'sketch' => $schema->integer()->min(0)->max(2)->description('Drawn as by hand, with wobbly, doubled lines, as a whiteboard sketch or a rough wireframe that no one takes for the finished design: 1 a little rough, 2 a lot, 0 crisp again (the default). On shapes, stickies, frames (their edge), paths and connectors. With "fontFamily": "hand" on everything it is the board\'s "Sketch look".'),
            'hatch' => $schema->string()->enum(BoardItems::HATCHES)->description('On a shape with "sketch": what fills it -- "solid" (its fill colour, the default), "hachure" (lines across in its fill colour, as if shaded with a pencil) or "cross" (lines both ways).'),
            'points' => $schema->array()->items($schema->number())->description('For kind "draw" (pencil ink, which the eraser takes): the stroke as [x, y, x, y, ...] in board units, drawn smooth as a hand\'s -- from x and y when given, else where they say. Or give "d".'),
            'pressures' => $schema->array()->items($schema->number())->description('For kind "draw" with "points": how hard each point was pressed, 0 to 1, one for each -- the ink heavier where pressed (0.5 is as heavy as plain ink), as a stylus draws.'),
            'rough' => $schema->number()->min(0)->max(3)->description('For kind "draw": how unsteady the hand, 0 (exact) to 3 -- a sketched look for lines drawn by "d".'),
            'd' => $schema->string()->max(20000)->description('For kind "draw": ink drawn along SVG path data; each M lifts the pencil and starts another stroke (ids id, id-2 ...). A circle: "M50 0 A50 50 0 1 1 49.9 0"; a wave: "M0 0 Q25 -30 50 0 T100 0 T150 0"; a spiral or star: many points in one "d". Its "lineWidth" is how heavy the ink (3 by default), its "opacity" how faint (a highlighter: 12 and 0.35). For kind "path": SVG path data -- M, L, H, V, C, S, Q, T, A and Z, upper case absolute or lower case relative, e.g. "M100 300 C200 100 400 100 500 300". Open, it is a line; closed with Z and given a fill, a shape. In board units: with no x and y the path sits where its points say, with them its points are measured from that corner. Its box is worked out from the curve; a width or height scales it.'),
            'radius' => $schema->number()->description('How round its corners are, in board units: a rect, sticky, frame, image or video. Left out, its kind\'s own (8 on a rect, 0 on a frame).'),
            'opacity' => $schema->number()->description('How solid it is, 0 (unseen) to 1 (the default).'),
            'gradient' => $schema->string()->max(32)->description('A second colour, as hex, its fill fades to -- on shapes, stickies and frames. "" for a plain fill again.'),
            'gradientAngle' => $schema->number()->description('Which way the gradient runs, in degrees as CSS writes it: 180 (the default) top to bottom, 90 left to right, 135 corner to corner.'),
            'focusX' => $schema->number()->description('For a picture filling its box ("cover", and a frame\'s background): the point kept in view, 0 (left) to 1 (right); 0.5 by default.'),
            'focusY' => $schema->number()->description('As focusX, down: 0 keeps the top -- an app screenshot\'s header -- and 1 the bottom.'),
            'gradientType' => $schema->string()->enum(['linear', 'radial'])->description('How a fill fades: "linear" (the default) across it at gradientAngle, or "radial" out from its middle to its edge -- a glowing node, a vignette.'),
            'gradientStops' => $schema->array()->items($schema->object([
                'at' => $schema->number()->description('Where the colour falls, 0 (start, or the middle of a radial fade) to 1 (end, or the edge).')->required(),
                'color' => $schema->string()->max(32)->description('Hex, e.g. "#6366f1", or "#6366f100" for see-through.')->required(),
            ]))->description('A fill fading through more colours than two, in place of fill and gradient: [{"at": 0, "color": "#4f46e5"}, {"at": 0.5, "color": "#06b6d4"}, {"at": 1, "color": "#22c55e"}].'),
            'strokeGradient' => $schema->string()->max(32)->description('A second colour its line fades to: across a shape or path at strokeAngle, or along a connector from its start to its end.'),
            'strokeStops' => $schema->array()->items($schema->object([
                'at' => $schema->number()->required(),
                'color' => $schema->string()->max(32)->required(),
            ]))->description('A line fading through more colours than two, as gradientStops -- an indigo, cyan, green link.'),
            'strokeAngle' => $schema->number()->description('Which way a line\'s fade runs across a shape or path, in degrees as CSS writes it: 90 (the default) left to right.'),
            'fillOpacity' => $schema->number()->description('How solid the inside is, 0 to 1, leaving the line as it is.'),
            'strokeOpacity' => $schema->number()->description('How solid the line is, 0 to 1, leaving the inside as it is.'),
            'shadowColor' => $schema->string()->max(32)->description('A shadow of its own, in this colour (with shadowBlur, shadowX, shadowY, shadowOpacity) in place of the soft one "shadow" puts under it.'),
            'shadowBlur' => $schema->number()->description('How soft that shadow is: 16 by default.'),
            'shadowX' => $schema->number()->description('How far right the shadow falls: 0 by default.'),
            'shadowY' => $schema->number()->description('How far down the shadow falls: 6 by default.'),
            'shadowOpacity' => $schema->number()->description('How dark the shadow is, 0 to 1: 0.2 by default.'),
            'glow' => $schema->string()->max(32)->description('A light round it in this colour -- a glowing node or line. Takes the place of a shadow.'),
            'glowSize' => $schema->number()->description('How far the glow spreads: 24 by default.'),
            'blur' => $schema->number()->description('Softens the whole item by so many units: a haze, a light behind something.'),
            'blend' => $schema->string()->enum(BoardItems::BLENDS)->description('How it mixes with what is under it: multiply darkens, screen lightens, overlay adds contrast. source-over (the default) simply covers.'),
            'letterSpacing' => $schema->number()->description('Room added after each letter of its words, in board units: 2 to 4 for a small "kicker" label above a title.'),
            'caps' => $schema->boolean()->description('true: its words are shown in capitals, whatever they were typed in.'),
            'colorTo' => $schema->string()->max(32)->description('A second colour its words fade to, left to right, from "color".'),
            'hug' => $schema->boolean()->description('true: a rect, sticky, pill or process is exactly as tall as its words and padding, growing and shrinking with them. Its height is then worked out, not given.'),
            'poster' => $schema->string()->max(200)->description('For kind "video": a picture shown in its place until it plays -- and in pictures and PDFs of the board. Left out, its first frame (or the one at posterAt).'),
            'posterAt' => $schema->number()->description('For kind "video": the moment, in seconds, shown while it isn\'t playing.'),
            'notes' => $schema->string()->max(10000)->description('For kind "frame": speaker notes, shown to the presenter alone in present mode.'),
            'section' => $schema->string()->max(120)->description('For kind "frame": the part of the deck this slide begins -- what a footer\'s {section} says on it and the slides after it.'),
            'bare' => $schema->boolean()->description('For kind "frame": true leaves the deck\'s header, footer and slide number off this slide -- a title slide. It is still counted.'),
            'build' => $schema->array()->items($schema->object([
                'item' => $schema->string()->max(64)->description('The id of an item on this slide.')->required(),
                'kind' => $schema->string()->enum(Animations::kinds())->description('in: it comes on (hidden until then); emphasis: it draws the eye and stays; out: it goes.')->required(),
                'effect' => $schema->string()->enum(Animations::allEffects())->description('Entrance: appear, fade, fly (from a side), zoom, wipe (from a side). Emphasis: pulse, spin, blink. Exit: fade, fly (to a side), zoom, disappear.'),
                'start' => $schema->string()->enum(Animations::starts())->description('click (the default): on the presenter\'s next click; with: as the step before it begins; after: once the step before it ends. Steps before the first click play as the slide comes up.'),
                'duration' => $schema->integer()->description('How long it plays, ms: 250 fast, 500 (the default) normal, 1000 slow.'),
                'delay' => $schema->integer()->description('How long it waits once its turn comes, ms.'),
                'from' => $schema->string()->enum(Animations::froms())->description('For fly and wipe: the side it comes from (or goes to). Default bottom.'),
            ]))->description('For kind "frame": the slide\'s animations, in the order they play while presenting -- PowerPoint\'s Animation Pane. Sent whole: it replaces the list; [] takes them all off. Pictures and PDFs show the slide as it ends.'),
            'transition' => $schema->string()->enum(Animations::transitions())->description('For kind "frame": how the show moves on to this slide -- glide (the default: the view moves across the board), fade (through black), or cut (at once).'),
            'hidden' => $schema->boolean()->description('Keep it off the board without deleting it.'),
            'locked' => $schema->boolean()->description('Stop it being picked up on the canvas.'),
            'pdfHidden' => $schema->boolean()->description('For kind "frame": keep it on the board but leave it out of the PDF export, which otherwise has a page for every frame.'),
            'link' => $this->actionArgument($schema)->description('In a prototype (its screens are frames): what tapping this item does in Play -- an action. null takes it off. Not for a frame or a connector.'),
            'when' => $schema->object(array_fill_keys(BoardActions::TRIGGERS, $schema->object()))->description('In a prototype: what this item does for triggers other than a tap, an action each -- long-press, hover, swipe-left, swipe-right, swipe-up, swipe-down. null takes them all off.'),
            'screen' => $schema->object([
                'after' => $schema->object([
                    'seconds' => $schema->number()->description('0.5 to 30.'),
                    'do' => $schema->object()->description('An action, as in "link".'),
                ]),
            ])->description('For a frame in a prototype: what the screen does by itself -- {"after": {"seconds": 2, "do": {"to": "Home"}}}, a splash screen moving on. null takes it off.'),
            'repeat' => $schema->object([
                'table' => $schema->string()->max(255)->description('A table of this project, by ref_id or title.'),
                'where' => $schema->array()->items($schema->object([
                    'column' => $schema->string()->description('A column, by its label.'),
                    'is' => $schema->string()->description('What it has to be: words, which may say {name}.'),
                ]))->description('Only the rows matching all of these.'),
                'sort' => $schema->object([
                    'column' => $schema->string(),
                    'desc' => $schema->boolean(),
                ])->description('Which column the rows are in the order of.'),
                'limit' => $schema->integer()->description('At most so many rows, 1 to 200; left out, 50.'),
                'gap' => $schema->number()->description('Room between the rows, 0 to 400.'),
                'across' => $schema->boolean()->description('true: the rows side by side; left out, one under another.'),
            ])->description('In a prototype: this card (a rect or a ui_card) with everything inside its box is one row, shown in Play once for each row of a project table -- its words\' {Column} (a column\'s label) are that row\'s. Tapping one makes it the table\'s current row. null takes it off.'),
            'fixed' => $schema->string()->enum(BoardItems::FIXED)->description('In a prototype: kept at the top or the bottom while its screen (a frame taller than the device) scrolls -- a header, a tab bar. null to scroll with the rest.'),
            'startsHidden' => $schema->boolean()->description('In a prototype: not shown in Play until an action shows or flips it.'),
            'field' => $schema->string()->max(40)->description('In a prototype: a text_field or search_field typed into in Play, what is typed kept by this name. Any item\'s words may say {name} to show it.'),
            'choice' => $schema->string()->max(40)->description('In a prototype: radios or tabs on a screen with the same choice are one choice -- one of them on. Their "on" says which starts on.'),
            'from' => $schema->object([
                'item' => $schema->string()->max(64)->description('The id of the item this end is pinned to; it follows that shape around.'),
                'pin' => $schema->string()->max(120)->description('When the item is a symbol: which of its pins the wire is on, e.g. "1", "2", "a", "k", "b", "+". Left out, the pin nearest the other end is taken. When it is a table or a class: which of its rows the line holds on to, by the row\'s name or id ("customer_id") -- left out, the line is to the table as a whole.'),
                'along' => $schema->number()->description('When the item is a lifeline: how far down it this end is, 0 (its top) to 1 (its foot). A message between two lifelines has the same "along" at both ends, and messages lower down come later.'),
                'side' => $schema->string()->enum(BoardItems::SIDES)->description('Keep this end on that edge, whatever moves. Left out, it follows the shapes and always leaves by the face pointing at the other end -- usually best: an elbow line finds its own way round the items between, and lines sharing a face spread along it and keep apart.'),
                'x' => $schema->number()->description('A loose point on the board, instead of an item.'),
                'y' => $schema->number(),
            ])->description('For kind "arrow": where the connector starts. Either {"item": "<id>"} or a loose {"x", "y"}.'),
            'to' => $schema->object([
                'item' => $schema->string()->max(64)->description('The id of the item this end is pinned to.'),
                'pin' => $schema->string()->max(120)->description('When the item is a symbol: which of its pins the wire is on. When it is a table or a class: which of its rows, by name or id.'),
                'along' => $schema->number()->description('When the item is a lifeline: how far down it this end is, 0 to 1.'),
                'side' => $schema->string()->enum(BoardItems::SIDES)->description('Keep this end on that edge, whatever moves. Left out, it follows the shapes.'),
                'x' => $schema->number()->description('A loose point on the board, instead of an item.'),
                'y' => $schema->number(),
            ])->description('For kind "arrow": where the connector ends.'),
            'routing' => $schema->string()->enum(BoardItems::ROUTINGS)->description('How a connector is drawn: elbow (the default), straight or curved.'),
            'lineStyle' => $schema->string()->enum(BoardItems::LINE_STYLES)->description('solid, dashed or dotted.'),
            'lineWidth' => $schema->number()->description('How heavy a line is, 2 by default: a connector, or a shape\'s outline -- 0 for no outline.'),
            'startHead' => $schema->string()->enum(BoardItems::HEADS)->description('What the start of a connector is capped with (none by default). For a class diagram: triangle (hollow: is a kind of), diamond_open (has), diamond (is made of). For a data model, how many are at this end: one_only, many, one_many, zero_one, zero_many.'),
            'endHead' => $schema->string()->enum(BoardItems::HEADS)->description('What the end of a connector is capped with (arrow by default; none on a wire between symbols).'),
            'headSize' => $schema->number()->description('How big those caps are drawn.'),
        ]);
    }
}
