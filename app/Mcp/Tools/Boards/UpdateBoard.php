<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Support\BoardItems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description(<<<'TEXT'
Update or move a board. Only the fields you pass change, so a title can be set without touching what
is drawn.

"items" is the whole board: read it with get-board first, change what you mean to change, and send
the list back. An item whose id is already on the board keeps every field you leave out -- its
colours, its ink, the picture it holds -- and an item missing from the list is removed from the board.
Connectors of kind "arrow" re-pin themselves to whatever their "from" and "to" name.
TEXT)]
class UpdateBoard extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'title' => $schema->string()->max(255)->description('New title.'),
            'items' => $this->itemsArgument($schema, 'The board\'s items, drawn back to front; replaces what is on the board. Pass [] to clear it.'),
            'folder' => $this->folderArgument($schema, 'Move the board to this folder; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'title' => ['sometimes', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ...$this->itemRules(sometimes: true),
        ]);

        $board = $this->find($user, $validated['ref_id']);

        if (! $board) {
            return $this->notFound($validated['ref_id']);
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
            $board->folder_id = $this->ensureFolderAt($user, $validated['folder'] ?? '')?->id;
        }

        $board->save();

        return Response::structured($this->full($board));
    }
}
