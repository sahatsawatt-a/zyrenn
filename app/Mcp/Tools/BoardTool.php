<?php

namespace App\Mcp\Tools;

use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use App\Models\Owner;
use App\Support\BoardItems;
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
        $kinds = implode(',', BoardItems::KINDS);
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
            "{$key}.*.lineWidth" => ['nullable', 'numeric', 'between:1,40'],
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
     * The trouble with a client's list that the field rules cannot see: two
     * items sharing an id, or a connector pinned to something that isn't there.
     *
     * @param  list<array<string, mixed>>  $specs
     */
    protected function itemProblem(array $specs): ?string
    {
        $duplicates = BoardItems::duplicateIds($specs);

        if ($duplicates !== []) {
            return 'Every item needs its own id. Used twice: "'.implode('", "', $duplicates).'".';
        }

        $references = BoardItems::badReferences($specs);

        if ($references !== []) {
            return 'A connector points at something it cannot pin to. '.implode(' ', $references);
        }

        return null;
    }

    /**
     * One item, as a client writes it. Everything but the kind is optional:
     * what is left out gets the same default the toolbar would have given it.
     */
    private function itemArgument(JsonSchema $schema, bool $kindRequired = true): Type
    {
        $kind = $schema->string()->enum(BoardItems::KINDS)->description('What to draw. Shapes: rect, pill, ellipse, triangle, diamond, hexagon, star. Flowchart: cylinder (a database), parallelogram (data), document, process, cloud. Also sticky, text, frame (a 16:9 slide for present mode), image, video (plays on the board), math (a formula, with LaTeX in "text"), arrow (a connector) and draw (freehand ink).');

        return $schema->object([
            'id' => $schema->string()->max(64)->description('Your name for this item, so a connector can point at it (letters, digits, "-" and "_"). Made up for you if you leave it out.'),
            'kind' => $kindRequired ? $kind->required() : $kind,
            'x' => $schema->number()->description('Left edge on the board. Leave x and y out and items are laid out in rows for you.'),
            'y' => $schema->number()->description('Top edge on the board.'),
            'width' => $schema->number()->description('Width in board units; each kind has a sensible default.'),
            'height' => $schema->number()->description('Height in board units.'),
            'text' => $schema->string()->max(5000)->description('The label written on it. On a "math" item this is LaTeX, set with KaTeX, e.g. "e^{i\\pi} + 1 = 0". Ink takes no label.'),
            'fill' => $schema->string()->max(32)->description('Fill colour as hex, e.g. "#fde68a", or "transparent".'),
            'stroke' => $schema->string()->max(32)->description('Outline (or, for a connector, line) colour as hex.'),
            'fontSize' => $schema->number()->description('Label size in board units (16 on shapes, 28 on text).'),
            'align' => $schema->string()->enum(BoardItems::ALIGNS)->description('Where the label sits across the item: left, center or right. Shapes centre it, a text item starts at the left.'),
            'verticalAlign' => $schema->string()->enum(BoardItems::VERTICAL_ALIGNS)->description('Where the label sits down the item: top, middle or bottom.'),
            'rotation' => $schema->number()->description('Degrees clockwise.'),
            'src' => $schema->string()->max(200)->description('For kind "image": the URL of the picture; for kind "video", of the video (MP4 or WebM). Upload it with upload-file first and pass the "url" from the response.'),
            'hidden' => $schema->boolean()->description('Keep it off the board without deleting it.'),
            'locked' => $schema->boolean()->description('Stop it being picked up on the canvas.'),
            'from' => $schema->object([
                'item' => $schema->string()->max(64)->description('The id of the item this end is pinned to; it follows that shape around.'),
                'side' => $schema->string()->enum(BoardItems::SIDES)->description('Keep this end on that edge, whatever moves. Left out, it follows the shapes and always leaves by the face pointing at the other end.'),
                'x' => $schema->number()->description('A loose point on the board, instead of an item.'),
                'y' => $schema->number(),
            ])->description('For kind "arrow": where the connector starts. Either {"item": "<id>"} or a loose {"x", "y"}.'),
            'to' => $schema->object([
                'item' => $schema->string()->max(64)->description('The id of the item this end is pinned to.'),
                'side' => $schema->string()->enum(BoardItems::SIDES)->description('Keep this end on that edge, whatever moves. Left out, it follows the shapes.'),
                'x' => $schema->number()->description('A loose point on the board, instead of an item.'),
                'y' => $schema->number(),
            ])->description('For kind "arrow": where the connector ends.'),
            'routing' => $schema->string()->enum(BoardItems::ROUTINGS)->description('How a connector is drawn: elbow (the default), straight or curved.'),
            'lineStyle' => $schema->string()->enum(BoardItems::LINE_STYLES)->description('solid, dashed or dotted.'),
            'lineWidth' => $schema->number()->description('Connector thickness (2 by default).'),
            'startHead' => $schema->string()->enum(BoardItems::HEADS)->description('What the start of a connector is capped with (none by default).'),
            'endHead' => $schema->string()->enum(BoardItems::HEADS)->description('What the end of a connector is capped with (arrow by default).'),
            'headSize' => $schema->number()->description('How big those caps are drawn.'),
        ]);
    }
}
