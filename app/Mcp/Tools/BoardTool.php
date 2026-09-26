<?php

namespace App\Mcp\Tools;

use App\Models\Board;
use App\Models\BoardFolder;
use App\Models\User;
use App\Support\BoardItems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;

/**
 * Base for the board tools shared by both MCP servers. The user scoping and
 * folder-path handling live in ScopedTool.
 *
 * @extends ScopedTool<BoardFolder>
 */
abstract class BoardTool extends ScopedTool
{
    /** No board needs more on it than this in one call. */
    protected const MAX_ITEMS = 300;

    /**
     * @return HasMany<BoardFolder, User>
     */
    protected function folders(User $user): HasMany
    {
        return $user->boardFolders();
    }

    protected function findBoard(User $user, string $refId): ?Board
    {
        return $user->boards()->where('ref_id', $refId)->first();
    }

    /**
     * Schema for the board reference argument shared by single-board tools.
     */
    protected function refIdArgument(JsonSchema $schema): Type
    {
        return $schema->string()->description('The board\'s ref_id (shown on the board page, e.g. "k3x9m2p7qa").')->required();
    }

    /**
     * Schema for the list of things to put on a board.
     */
    protected function itemsArgument(JsonSchema $schema, string $description): Type
    {
        return $schema->array()
            ->items($this->itemArgument($schema))
            ->max(self::MAX_ITEMS)
            ->description($description);
    }

    /**
     * Validation rules for that list, keyed for `items.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(bool $sometimes = false): array
    {
        $kinds = implode(',', BoardItems::KINDS);
        $sides = implode(',', BoardItems::SIDES);
        $heads = implode(',', BoardItems::HEADS);

        return [
            'items' => [$sometimes ? 'sometimes' : 'nullable', 'array', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'items.*.kind' => ['required', 'string', 'in:'.$kinds],
            'items.*.x' => ['nullable', 'numeric', 'between:-100000,100000'],
            'items.*.y' => ['nullable', 'numeric', 'between:-100000,100000'],
            'items.*.width' => ['nullable', 'numeric', 'between:1,20000'],
            'items.*.height' => ['nullable', 'numeric', 'between:1,20000'],
            'items.*.rotation' => ['nullable', 'numeric', 'between:-360,360'],
            'items.*.text' => ['nullable', 'string', 'max:5000'],
            'items.*.fill' => ['nullable', 'string', 'max:32'],
            'items.*.stroke' => ['nullable', 'string', 'max:32'],
            'items.*.fontSize' => ['nullable', 'numeric', 'between:6,400'],
            'items.*.hidden' => ['nullable', 'boolean'],
            'items.*.locked' => ['nullable', 'boolean'],
            'items.*.src' => ['nullable', 'string', 'max:200', 'regex:#^(https?://|/drive/files/)#'],
            'items.*.from' => ['nullable'],
            'items.*.to' => ['nullable'],
            'items.*.from.item' => ['nullable', 'string', 'max:64'],
            'items.*.from.side' => ['nullable', 'string', 'in:'.$sides],
            'items.*.from.x' => ['nullable', 'numeric', 'between:-100000,100000'],
            'items.*.from.y' => ['nullable', 'numeric', 'between:-100000,100000'],
            'items.*.to.item' => ['nullable', 'string', 'max:64'],
            'items.*.to.side' => ['nullable', 'string', 'in:'.$sides],
            'items.*.to.x' => ['nullable', 'numeric', 'between:-100000,100000'],
            'items.*.to.y' => ['nullable', 'numeric', 'between:-100000,100000'],
            'items.*.routing' => ['nullable', 'string', 'in:'.implode(',', BoardItems::ROUTINGS)],
            'items.*.lineStyle' => ['nullable', 'string', 'in:'.implode(',', BoardItems::LINE_STYLES)],
            'items.*.lineWidth' => ['nullable', 'numeric', 'between:1,40'],
            'items.*.startHead' => ['nullable', 'string', 'in:'.$heads],
            'items.*.endHead' => ['nullable', 'string', 'in:'.$heads],
            'items.*.headSize' => ['nullable', 'numeric', 'between:4,80'],
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
    protected function specs(array $validated): array
    {
        $items = is_array($validated['items'] ?? null) ? $validated['items'] : [];
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
     * The board's folder as a path, e.g. "Plans/Q3", or null at the top level.
     *
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many boards
     */
    protected function folderPath(Board $board, ?array $paths = null): ?string
    {
        if ($board->folder_id === null) {
            return null;
        }

        return $paths[$board->folder_id]
            ?? implode('/', array_map(fn (BoardFolder $folder) => $folder->name, $board->folder->ancestry()));
    }

    /**
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many boards
     * @return array<string, mixed>
     */
    protected function summary(Board $board, ?array $paths = null): array
    {
        return [
            'ref_id' => $board->ref_id,
            'user_id' => $board->user_id,
            'title' => $board->title,
            'folder' => $this->folderPath($board, $paths),
            'item_count' => $board->itemCount(),
            'updated_at' => $board->updated_at?->toIso8601String(),
            'url' => route('boards.show', $board),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function withItems(Board $board): array
    {
        return [
            ...$this->summary($board),
            'items' => BoardItems::toSpec($board->content),
        ];
    }

    /**
     * One item, as a client writes it. Everything but the kind is optional:
     * what is left out gets the same default the toolbar would have given it.
     */
    private function itemArgument(JsonSchema $schema): Type
    {
        return $schema->object([
            'id' => $schema->string()->max(64)->description('Your name for this item, so a connector can point at it (letters, digits, "-" and "_"). Made up for you if you leave it out.'),
            'kind' => $schema->string()->enum(BoardItems::KINDS)->description('What to draw. Shapes: rect, pill, ellipse, triangle, diamond, hexagon, star. Flowchart: cylinder (a database), parallelogram (data), document, process, cloud. Also sticky, text, frame (a 16:9 slide for present mode), image, arrow (a connector) and draw (freehand ink).')->required(),
            'x' => $schema->number()->description('Left edge on the board. Leave x and y out and items are laid out in rows for you.'),
            'y' => $schema->number()->description('Top edge on the board.'),
            'width' => $schema->number()->description('Width in board units; each kind has a sensible default.'),
            'height' => $schema->number()->description('Height in board units.'),
            'text' => $schema->string()->max(5000)->description('The label written on it. Connectors and ink take no label.'),
            'fill' => $schema->string()->max(32)->description('Fill colour as hex, e.g. "#fde68a", or "transparent".'),
            'stroke' => $schema->string()->max(32)->description('Outline (or, for a connector, line) colour as hex.'),
            'fontSize' => $schema->number()->description('Label size in board units (16 on shapes, 28 on text).'),
            'rotation' => $schema->number()->description('Degrees clockwise.'),
            'src' => $schema->string()->max(200)->description('For kind "image": the URL of the picture. Upload it with upload-file first and pass the "url" from the response.'),
            'hidden' => $schema->boolean()->description('Keep it off the board without deleting it.'),
            'locked' => $schema->boolean()->description('Stop it being picked up on the canvas.'),
            'from' => $schema->object([
                'item' => $schema->string()->max(64)->description('The id of the item this end is pinned to; it follows that shape around.'),
                'side' => $schema->string()->enum(BoardItems::SIDES)->description('Which edge to leave from. Worked out from where the shapes sit if you leave it out.'),
                'x' => $schema->number()->description('A loose point on the board, instead of an item.'),
                'y' => $schema->number(),
            ])->description('For kind "arrow": where the connector starts. Either {"item": "<id>"} or a loose {"x", "y"}.'),
            'to' => $schema->object([
                'item' => $schema->string()->max(64)->description('The id of the item this end is pinned to.'),
                'side' => $schema->string()->enum(BoardItems::SIDES)->description('Which edge to arrive at.'),
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
