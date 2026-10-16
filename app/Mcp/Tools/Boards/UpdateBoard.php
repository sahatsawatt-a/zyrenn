<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\Board\BoardItemProblem;
use App\Support\Board\BoardItems;
use App\Support\Board\BoardParts;
use App\Support\Board\CircuitLayout;
use App\Support\Board\Deck;
use App\Support\Live\Collab;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Update or move a board. Only what you pass changes, so a title can be set without touching what is
drawn.

To change what is on it, send only what changes, by item id (from get-board):
- "add_items": new items, drawn on top. Leave "id" out and one is made up; leave x and y out and they
  are laid out for you. A connector can pin to any item on the board, old or new.
- "add_circuit": a circuit written as text ("R1: 10k", "V1 -- R1 -- D1 -- V1"; see create-board), laid
  out for you -- parts on the grid, wires clear of one another -- and added under what is there.
- "update_items": each with its "id" and just the fields to change; the rest keep what they have.
- "delete_items": the ids to take off.
- "delete_frames": frames (by id or title) to take off with everything on them.
- "frame_order": every frame's id, in the order they should be presented.
- "add_slides": slides laid out for you after the last frame, each from a layout (a title, two
  columns, a big number...) in the deck's look.
An id can't be both deleted and added in one call. Whatever sits on a frame is kept drawn above it.
If someone has the board open, only those items change for them, live. If one change can't be made,
none are.

"items" instead replaces the whole board: every item, in the order drawn, and any missing is removed.
An item keeps what it had only when you send its own "id" and kind; one without an id is new.
Connectors of kind "arrow" re-pin themselves to whatever their "from" and "to" name.

"deck" makes the frames a deck: a theme, and a header, footer and slide number written on every
slide. Set it before "add_slides", in the same call, and they are laid out in its look.
TEXT)]
class UpdateBoard extends BoardTool
{
    use MakesDecks;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'title' => $schema->string()->max(255)->description('New title.'),
            'add_items' => $this->itemsArgument($schema, 'Items to add, on top.'),
            'add_circuit' => $this->circuitArgument($schema, 'A circuit as text, laid out for you and added under what is on the board -- or at "circuit_at".'),
            'circuit_at' => $schema->object(['x' => $schema->number(), 'y' => $schema->number()])->description('Where the top left corner of "add_circuit" goes.'),
            'update_items' => $this->itemsLike($schema, 'Items to change: each with its "id" and just the fields to change, named as in add_items.', withId: true),
            'delete_items' => $schema->array()->max(self::MAX_ITEMS)->items($schema->string()->max(64))->description('Ids of items to take off.'),
            'delete_frames' => $schema->array()->max(self::MAX_ITEMS)->items($schema->string()->max(255))->description('Frames, by id or title, to take off along with everything on them and any connector joined to it.'),
            'frame_order' => $schema->array()->max(self::MAX_ITEMS)->items($schema->string()->max(64))->description('Every frame\'s id, once each, in the order they are presented. Whatever is on a frame stays drawn above it.'),
            'items' => $this->itemsLike($schema, 'The whole board, drawn back to front, each item as in add_items; replaces what is on it. Pass [] to clear it.'),
            'deck' => $this->deckArgument($schema),
            'add_slides' => $this->slidesArgument($schema, 'Slides to add after the last frame, in order, each laid out from a layout in the deck\'s look.'),
            'folder' => $this->folderArgument($schema, 'Move the board to this folder; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'title' => ['sometimes', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'delete_items' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'delete_items.*' => ['string', 'max:64'],
            'delete_frames' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'delete_frames.*' => ['string', 'max:255'],
            'frame_order' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'frame_order.*' => ['string', 'max:64'],
            'add_circuit' => ['sometimes', 'string', 'max:'.self::MAX_CIRCUIT],
            'circuit_at' => ['sometimes', 'array'],
            'circuit_at.x' => ['required_with:circuit_at', 'numeric', 'between:-100000,100000'],
            'circuit_at.y' => ['required_with:circuit_at', 'numeric', 'between:-100000,100000'],
            ...$this->itemRules(sometimes: true),
            ...$this->itemRules(sometimes: true, key: 'add_items'),
            ...$this->itemRules(sometimes: true, key: 'update_items', kindRequired: false),
            ...$this->deckRules('add_slides'),
        ]);

        /** @var Board|null $board */
        $board = $this->find($owner, $validated['ref_id']);

        if (! $board) {
            return $this->notFound($validated['ref_id']);
        }

        $byItem = array_intersect_key($validated, array_flip(['add_items', 'add_circuit', 'update_items', 'delete_items', 'delete_frames', 'frame_order', 'add_slides'])) !== [];
        $also = [];

        // The deck as it will be: what was sent said over what it was
        $decked = array_key_exists('deck', $validated);

        if ($decked) {
            Collab::flush($board);
            $board->refresh();
        }

        $deck = ! $decked ? Deck::of($board->content)
            : ($validated['deck'] === null ? null : Deck::merged(Deck::of($board->content), $validated['deck']));

        if ($byItem && array_key_exists('items', $validated)) {
            return Response::error('Send the whole board as "items", or changes as add_items, update_items, delete_items, delete_frames and frame_order -- not both.');
        }

        if ($byItem) {
            // Checked against the board as everyone has it now
            Collab::flush($board);
            $board->refresh();

            $current = array_values(array_filter($board->content['items'] ?? [], 'is_array'));
            $delete = array_values($validated['delete_items'] ?? []);

            // A frame goes with everything on it
            foreach ($validated['delete_frames'] ?? [] as $named) {
                $frame = BoardParts::frame($current, $named);

                if (! $frame) {
                    return Response::error("There is no frame \"{$named}\" on this board. get-board lists its frames.");
                }

                array_push($delete, ...array_column(BoardParts::inFrame($current, (string) $frame['id']), 'id'));
            }

            // ...and so does a connector joined to anything taken off
            if (isset($validated['delete_frames'])) {
                $delete = BoardItems::withConnectors($current, array_values(array_unique($delete)));
            }

            try {
                $add = $this->specs($validated, 'add_items');

                // Slides laid out after the last frame, in the deck's look
                if (isset($validated['add_slides'])) {
                    $add = [...$add, ...Deck::slideSpecs($this->slides($validated, 'add_slides'), [...$current, ...$add], $deck)];
                }

                // A circuit written as text is laid out, and added with the rest
                if (filled($validated['add_circuit'] ?? null)) {
                    $add = [...$add, ...CircuitLayout::specs(
                        $validated['add_circuit'],
                        [...array_column($current, 'id'), ...array_column($add, 'id')],
                        $validated['circuit_at'] ?? CircuitLayout::under($current),
                    )];
                }

                $done = BoardItems::change(
                    $current,
                    $add,
                    $this->specs($validated, 'update_items'),
                    $delete,
                );

                if (isset($validated['frame_order'])) {
                    $done['items'] = BoardItems::orderFrames($done['items'], array_values($validated['frame_order']));
                }
            } catch (BoardItemProblem $problem) {
                return Response::error($problem->getMessage());
            }

            // The order the live copy comes to by itself: new items on top. Frames
            // reordered, or anything brought up off the frame it was under, it is told
            $order = array_column($done['items'], 'id');
            $stacked = [...array_values(array_diff(array_column($current, 'id'), $done['deleted'])), ...$done['added']];

            // Open somewhere: only these items change in the live copy, which the app is then handed
            $live = Collab::apply($board, [
                ['do' => 'set', 'items' => $done['set']],
                ['do' => 'delete', 'ids' => $done['deleted']],
                ...($order !== $stacked ? [['do' => 'order', 'ids' => $order]] : []),
                ...($decked ? [['do' => 'deck', 'deck' => $deck]] : []),
            ], $user);

            if ($live === null) {
                $board->content = Deck::content($done['items'], $deck);
            } else {
                $board->refresh();
            }

            $touched = array_flip([...$done['added'], ...$done['changed']]);
            $also = array_filter([
                'added' => $done['added'],
                'changed' => $done['changed'],
                'deleted' => $done['deleted'],
                ...$this->overflow(array_values(array_filter($done['items'], fn (array $item) => isset($touched[$item['id']])))),
            ]);
        }

        if (array_key_exists('items', $validated)) {
            // Compared with the board as everyone has it now
            Collab::flush($board);
            $board->refresh();

            $current = array_values(array_filter($board->content['items'] ?? [], 'is_array'));
            $specs = BoardItems::withIds($this->specs($validated), array_column($current, 'id'));
            $problem = $this->itemProblem($specs, $current);

            if ($problem !== null) {
                return Response::error($problem);
            }

            $board->content = Deck::content(BoardItems::fromSpec($specs, $current), $deck);
            $also = $this->overflow($board->content['items']);
        }

        // Only the deck changed: in the live copy when it is open, as above
        if ($decked && ! $byItem && ! array_key_exists('items', $validated)) {
            if (Collab::apply($board, [['do' => 'deck', 'deck' => $deck]], $user) === null) {
                $board->content = Deck::content(array_values(array_filter($board->content['items'] ?? [], 'is_array')), $deck);
            } else {
                $board->refresh();
            }
        }

        if ($decked) {
            $also['deck'] = $deck;
        }

        if (array_key_exists('title', $validated)) {
            $board->title = $validated['title'];
        }

        if (array_key_exists('folder', $validated)) {
            $board->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        }

        $board->save();

        return Response::structured($this->answer($board, $also));
    }
}
