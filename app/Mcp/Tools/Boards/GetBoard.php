<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\Board\BoardItems;
use App\Support\Board\BoardParts;
use App\Support\Board\BoardRender;
use App\Support\Live\Collab;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[Description(<<<'TEXT'
Get a board: its frames (the slides it is divided into, with how many things sit on each), and its
items -- each with its kind, box, label and colours, and each connector with the items its ends are
pinned to. A picture is reported as "has_picture" rather than its bytes.

A big board comes in outline: each item's id, kind, label, box and frame, without colours, styles or
the points of ink -- and a big board with frames comes as just its frames, each with how many things
are on it, to be read a frame at a time. "frame" (an id or title) reads just that frame and what is
on it; "frame": "board" reads what sits on no frame. "outline" true or false asks for one form or
the other; "items": false leaves the items out, for when only the frames, the check or the picture
is wanted.

"image" true also sends a picture of it, drawn as the app draws it -- of the frame asked for with
"frame", which comes out as the slide would show it, or of the whole board -- so you can see how it
looks without opening it in a browser.

"check" true adds what to look at before showing it: "overflowing", items whose label has more
words than room (the canvas draws it running past the item's edge), with the height that would hold
it; and "overlapping", items in one frame lying over each other, with how much of the smaller is
covered -- some of those are meant. A box laid round a picture as its border shows here too:
give the picture a "stroke" and "lineWidth" of its own instead.

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
            'check' => $schema->boolean()->description('true: also list labels that run out of their box, and items overlapping in a frame.'),
            'image' => $schema->boolean()->description('true: also a PNG of the frame asked for, or of the whole board, as the app draws it.'),
            'items' => $schema->boolean()->description('false: leave the items out -- just the frames, and the check or picture if asked for. Left out, a big board with frames comes without them.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'frame' => ['nullable', 'string', 'max:255'],
            'outline' => ['nullable', 'boolean'],
            'check' => ['nullable', 'boolean'],
            'image' => ['nullable', 'boolean'],
            'items' => ['nullable', 'boolean'],
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
        $looseCount = count(BoardParts::loose($items));
        $frame = null;
        $named = $validated['frame'] ?? null;

        // What sits on no frame, unless a frame is called that
        if ($named === BoardParts::LOOSE && ! BoardParts::frame($items, $named)) {
            $items = BoardParts::loose($items);
        } elseif (filled($named)) {
            $frame = BoardParts::frame($items, $validated['frame']);

            if (! $frame) {
                return Response::error("There is no frame \"{$validated['frame']}\" on this board. Its frames are: "
                    .(collect($frames)->map(fn (array $one) => "\"{$one['title']}\" ({$one['id']})")->join(', ') ?: 'none').'.');
            }

            $items = BoardParts::inFrame($items, $frame['id']);
        }

        $full = BoardItems::toSpec(['items' => $items]);
        $big = mb_strlen((string) json_encode($full)) > self::WHOLE_UP_TO;
        $outline = $validated['outline'] ?? $big;

        // Big, and in frames: read a frame at a time rather than all of it at once
        $whole = $named === null && ! isset($validated['outline']);
        $listed = $validated['items'] ?? ! ($big && $whole && $frames !== []);

        $answer = [
            ...$this->summary($board),
            'frames' => $frames,
            ...($frames !== [] ? ['loose_items' => $looseCount] : []),
            ...($frame ? ['frame' => $frame['id']] : []),
            ...($listed ? ['items' => $outline ? BoardParts::outline($items) : $full] : []),
            ...(($validated['check'] ?? false)
                ? ['overflowing' => BoardItems::overflowing($items), 'overlapping' => BoardParts::overlapping($items)]
                : []),
            ...(! $listed && ! isset($validated['items'])
                ? ['more' => 'The board is big, so it comes as its frames. Read one with "frame" (its id or title), what is on no frame with "frame": "board", or everything in outline with "outline": true.']
                : []),
            ...($listed && $outline && ! isset($validated['outline'])
                ? ['more' => 'The board is big, so its items are in outline. Read one frame in full with "frame", or everything with "outline": false.']
                : []),
        ];

        if (! ($validated['image'] ?? false)) {
            return Response::structured($answer);
        }

        // The picture is a help, not the answer: if it can't be drawn, say so and answer anyway
        try {
            $picture = Response::image(BoardRender::png($board, $frame), 'image/png');
        } catch (ConnectionException|RuntimeException $e) {
            report($e);
            $picture = Response::text('The picture of the board could not be drawn just now; its items are below.');
        }

        return Response::make([
            Response::text(($frame ? "Frame \"{$frame['text']}\" of " : '')."\"{$board->title}\", as the app draws it."),
            $picture,
        ])->withStructuredContent($answer);
    }
}
