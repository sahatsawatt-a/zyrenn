<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\BoardItemProblem;
use App\Support\BoardItems;
use App\Support\BoardParts;
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
- "update_items": each with its "id" and just the fields to change; the rest keep what they have.
- "delete_items": the ids to take off.
- "delete_frames": frames (by id or title) to take off with everything on them.
- "frame_order": every frame's id, in the order they should be presented.
If someone has the board open, only those items change for them, live. If one change can't be made,
none are.

"items" instead replaces the whole board: every item, in the order drawn, and any missing is removed.
An item keeps what it had only when you send its own "id" and kind; one without an id is new.
Connectors of kind "arrow" re-pin themselves to whatever their "from" and "to" name.
TEXT)]
class UpdateBoard extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'title' => $schema->string()->max(255)->description('New title.'),
            'add_items' => $this->itemsArgument($schema, 'Items to add, on top.'),
            'update_items' => $this->itemsArgument($schema, 'Items to change: each with its "id" and the fields to change.', kindRequired: false),
            'delete_items' => $schema->array()->max(self::MAX_ITEMS)->items($schema->string()->max(64))->description('Ids of items to take off.'),
            'delete_frames' => $schema->array()->max(self::MAX_ITEMS)->items($schema->string()->max(255))->description('Frames, by id or title, to take off along with everything on them and any connector joined to it.'),
            'frame_order' => $schema->array()->max(self::MAX_ITEMS)->items($schema->string()->max(64))->description('Every frame\'s id, once each, in the order they are presented. Nothing else changes place.'),
            'items' => $this->itemsArgument($schema, 'The whole board, drawn back to front; replaces what is on it. Pass [] to clear it.'),
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
            ...$this->itemRules(sometimes: true),
            ...$this->itemRules(sometimes: true, key: 'add_items'),
            ...$this->itemRules(sometimes: true, key: 'update_items', kindRequired: false),
        ]);

        /** @var Board|null $board */
        $board = $this->find($owner, $validated['ref_id']);

        if (! $board) {
            return $this->notFound($validated['ref_id']);
        }

        $byItem = array_intersect_key($validated, array_flip(['add_items', 'update_items', 'delete_items', 'delete_frames', 'frame_order'])) !== [];
        $also = [];

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
                $done = BoardItems::change(
                    $current,
                    $this->specs($validated, 'add_items'),
                    $this->specs($validated, 'update_items'),
                    $delete,
                );

                if (isset($validated['frame_order'])) {
                    $done['items'] = BoardItems::orderFrames($done['items'], array_values($validated['frame_order']));
                }
            } catch (BoardItemProblem $problem) {
                return Response::error($problem->getMessage());
            }

            // Open somewhere: only these items change in the live copy, which the app is then handed
            $live = Collab::apply($board, [
                ['do' => 'set', 'items' => $done['set']],
                ['do' => 'delete', 'ids' => $done['deleted']],
                ...(isset($validated['frame_order']) ? [['do' => 'order', 'ids' => array_column($done['items'], 'id')]] : []),
            ], $user);

            if ($live === null) {
                $board->content = ['items' => $done['items']];
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
            $problem = $this->itemProblem($specs);

            if ($problem !== null) {
                return Response::error($problem);
            }

            $board->content = ['items' => BoardItems::fromSpec($specs, $current)];
            $also = $this->overflow($board->content['items']);
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
