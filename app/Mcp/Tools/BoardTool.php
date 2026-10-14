<?php

namespace App\Mcp\Tools;

use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use App\Models\Owner;
use App\Support\Board\BoardItems;
use App\Support\Board\BoardRows;
use App\Support\Board\Shapes;
use App\Support\Board\Symbols;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        return $owner->boards();
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
        return ['item_count' => $thing->itemCount()];
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
            "{$key}.*.variant" => ['nullable', 'string', 'in:top,left'],
            "{$key}.*.rows" => ['nullable', 'array', 'max:'.BoardRows::MAX],
            "{$key}.*.rows.*" => ['array'],
            "{$key}.*.rows.*.id" => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            "{$key}.*.rows.*.name" => ['required', 'string', 'max:120'],
            "{$key}.*.rows.*.type" => ['nullable', 'string', 'max:80'],
            "{$key}.*.rows.*.key" => ['nullable', 'string', 'in:'.implode(',', BoardRows::KEYS)],
            "{$key}.*.rows.*.visibility" => ['nullable', 'string', 'in:'.implode(',', BoardRows::VISIBILITIES)],
            "{$key}.*.rows.*.nullable" => ['nullable', 'boolean'],
            "{$key}.*.rows.*.method" => ['nullable', 'boolean'],
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

        /** @var list<array<string, mixed>> */
        return array_values($items);
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

        $kind = $schema->string()->enum(BoardItems::kinds())->description('What to draw. Shapes: rect, pill, ellipse, triangle, diamond, hexagon, star. Flowchart: cylinder (a database), parallelogram (data), document, process (a predefined process: a box with a rail down each side -- an ordinary step is a rect), cloud. More, from the shape library -- '.$library.'. A table (of a schema) and a class hold "rows" under their name in "text"; a lane is a titled column or swimlane to put steps in; a figure such as actor, user or server is named under it; a lifeline is a participant in a sequence, with messages drawn between two of them at the same "along". Also sticky, text (grows to fit its words unless given a height), frame (a 16:9 slide for present mode), image, video (plays on the board), math (a formula, with LaTeX in "text"), symbol (a part of a circuit: say which in "symbol"), arrow (a connector, or between symbols\' pins a wire) and draw (freehand ink).');

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
            'fontFamily' => $schema->string()->enum(BoardItems::FONTS)->description('The label\'s typeface: "sans" (Arial, the default), "serif" (Times New Roman) or "mono" (Courier New).'),
            'padding' => $schema->number()->description('Room between the item\'s edge and its label, in board units: 12 on shapes, 0 on text.'),
            'rich' => $schema->boolean()->description('true: the label is light Markdown, a line at a time -- "# " / "## " / "### " headings, "- " bullets, "1. " numbered items, **bold** and *italic*, and a line of "---" for a rule across the item -- so one card can hold a heading and its points. A text item\'s words are then regular weight, not bold.'),
            'align' => $schema->string()->enum(BoardItems::ALIGNS)->description('Where the label sits across the item: left, center or right. Shapes centre it, a text item starts at the left.'),
            'verticalAlign' => $schema->string()->enum(BoardItems::VERTICAL_ALIGNS)->description('Where the label sits down the item: top, middle or bottom.'),
            'rotation' => $schema->number()->description('Degrees clockwise.'),
            'src' => $schema->string()->max(200)->description('For kind "image": the URL of the picture; for kind "video", of the video (MP4 or WebM). Upload it first -- request-upload for a file on your disk, or upload-file -- and pass the "url" from the response.'),
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
            'variant' => $schema->string()->enum(['top', 'left'])->description('For kind "lane": where its title runs -- "top" for a column, "left" for a swimlane lying on its side.'),
            'hidden' => $schema->boolean()->description('Keep it off the board without deleting it.'),
            'locked' => $schema->boolean()->description('Stop it being picked up on the canvas.'),
            'pdfHidden' => $schema->boolean()->description('For kind "frame": keep it on the board but leave it out of the PDF export, which otherwise has a page for every frame.'),
            'from' => $schema->object([
                'item' => $schema->string()->max(64)->description('The id of the item this end is pinned to; it follows that shape around.'),
                'pin' => $schema->string()->max(120)->description('When the item is a symbol: which of its pins the wire is on, e.g. "1", "2", "a", "k", "b", "+". Left out, the pin nearest the other end is taken. When it is a table or a class: which of its rows the line holds on to, by the row\'s name or id ("customer_id") -- left out, the line is to the table as a whole.'),
                'along' => $schema->number()->description('When the item is a lifeline: how far down it this end is, 0 (its top) to 1 (its foot). A message between two lifelines has the same "along" at both ends, and messages lower down come later.'),
                'side' => $schema->string()->enum(BoardItems::SIDES)->description('Keep this end on that edge, whatever moves. Left out, it follows the shapes and always leaves by the face pointing at the other end.'),
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
