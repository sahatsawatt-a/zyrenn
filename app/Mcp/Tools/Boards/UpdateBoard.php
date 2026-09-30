<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\BoardItemProblem;
use App\Support\BoardItems;
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
If someone has the board open, only those items change for them, live. If one change can't be made,
none are.

"items" instead replaces the whole board: every item, in the order drawn, and any missing is removed.
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
            ...$this->itemRules(sometimes: true),
            ...$this->itemRules(sometimes: true, key: 'add_items'),
            ...$this->itemRules(sometimes: true, key: 'update_items', kindRequired: false),
        ]);

        /** @var Board|null $board */
        $board = $this->find($owner, $validated['ref_id']);

        if (! $board) {
            return $this->notFound($validated['ref_id']);
        }

        $byItem = array_intersect_key($validated, array_flip(['add_items', 'update_items', 'delete_items'])) !== [];
        $also = [];

        if ($byItem && array_key_exists('items', $validated)) {
            return Response::error('Send the whole board as "items", or changes as add_items, update_items and delete_items -- not both.');
        }

        if ($byItem) {
            // Checked against the board as everyone has it now
            Collab::flush($board);
            $board->refresh();

            try {
                $done = BoardItems::change(
                    array_values(array_filter($board->content['items'] ?? [], 'is_array')),
                    $this->specs($validated, 'add_items'),
                    $this->specs($validated, 'update_items'),
                    array_values($validated['delete_items'] ?? []),
                );
            } catch (BoardItemProblem $problem) {
                return Response::error($problem->getMessage());
            }

            // Open somewhere: only these items change in the live copy, which the app is then handed
            $live = Collab::apply($board, [
                ['do' => 'set', 'items' => $done['set']],
                ['do' => 'delete', 'ids' => $done['deleted']],
            ], $user);

            if ($live === null) {
                $board->content = ['items' => $done['items']];
            } else {
                $board->refresh();
            }

            $also = array_filter(['added' => $done['added'], 'changed' => $done['changed'], 'deleted' => $done['deleted']]);
        }

        if (array_key_exists('items', $validated)) {
            $specs = $this->specs($validated);
            $problem = $this->itemProblem($specs);

            if ($problem !== null) {
                return Response::error($problem);
            }

            $current = $board->content['items'] ?? [];
            $board->content = ['items' => BoardItems::fromSpec($specs, is_array($current) ? array_values($current) : [])];
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
