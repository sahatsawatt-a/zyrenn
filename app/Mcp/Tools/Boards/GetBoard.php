<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\BoardItems;
use App\Support\BoardParts;
use App\Support\Live\Collab;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
Get a board: its frames (the slides it is divided into, with how many things sit on each), and its
items -- each with its kind, box, label and colours, and each connector with the items its ends are
pinned to. A picture is reported as "has_picture" rather than its bytes.

A big board comes in outline: each item's id, kind, label, box and frame, without colours, styles or
the points of ink. "frame" (an id or title) reads just that frame and what is on it; "outline"
true or false asks for one form or the other.

To change it, use update-board with add_items, update_items and delete_items: they send only what
changes. Fields left out of an item keep the value they have now.
TEXT)]
class GetBoard extends BoardTool
{
    /** Items this small, as JSON, come whole unless the outline is asked for. */
    private const WHOLE_UP_TO = 8000;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'frame' => $schema->string()->max(255)->description('A frame\'s id or title: that frame and what is on it.'),
            'outline' => $schema->boolean()->description('true: items in outline; false: every item in full. Left out, a big board comes in outline.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'frame' => ['nullable', 'string', 'max:255'],
            'outline' => ['nullable', 'boolean'],
        ]);

        /** @var Board|null $board */
        $board = $this->find($owner, $validated['ref_id']);

        if (! $board) {
            return $this->notFound($validated['ref_id']);
        }

        // Open and being drawn on: read what everyone has drawn
        Collab::flush($board);
        $board->refresh();

        $items = array_values(array_filter($board->content['items'] ?? [], 'is_array'));
        $frames = BoardParts::frames($items);
        $frame = null;

        if (filled($validated['frame'] ?? null)) {
            $frame = BoardParts::frame($items, $validated['frame']);

            if (! $frame) {
                return Response::error("There is no frame \"{$validated['frame']}\" on this board. Its frames are: "
                    .(collect($frames)->map(fn (array $one) => "\"{$one['title']}\" ({$one['id']})")->join(', ') ?: 'none').'.');
            }

            $items = BoardParts::inFrame($items, $frame['id']);
        }

        $full = BoardItems::toSpec(['items' => $items]);
        $outline = $validated['outline'] ?? mb_strlen((string) json_encode($full)) > self::WHOLE_UP_TO;

        return Response::structured([
            ...$this->summary($board),
            'frames' => $frames,
            ...($frame ? ['frame' => $frame['id']] : []),
            'items' => $outline ? BoardParts::outline($items) : $full,
            ...($outline && ! isset($validated['outline'])
                ? ['more' => 'The board is big, so its items are in outline. Read one frame in full with "frame", or everything with "outline": false.']
                : []),
        ]);
    }
}
