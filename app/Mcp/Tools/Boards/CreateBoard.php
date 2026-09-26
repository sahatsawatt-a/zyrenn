<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Support\BoardItems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Create a board: an endless canvas of shapes, sticky notes, connectors, pictures and 16:9 frames to
present. "items" is what goes on it, in the order they are drawn, back to front.

Only "kind" is needed for each item. Leave "x" and "y" out and items are laid out in rows for you.
A connector is an item of kind "arrow" whose "from" and "to" name other items by id; it stays pinned
to those shapes' edges however they are moved or resized later.

A flowchart, for example:
[{"id":"a","kind":"process","text":"Order placed"},
 {"id":"b","kind":"diamond","text":"In stock?"},
 {"id":"c","kind":"cylinder","text":"Warehouse"},
 {"kind":"arrow","from":{"item":"a"},"to":{"item":"b"}},
 {"kind":"arrow","from":{"item":"b"},"to":{"item":"c"},"text":"yes"}]
TEXT)]
class CreateBoard extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->description('The board title.')->required(),
            'items' => $this->itemsArgument($schema, 'What to put on the board, drawn back to front. An empty board is fine too.'),
            'folder' => $this->folderArgument($schema, 'Folder to create the board in; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            ...$this->itemRules(),
        ]);

        /** @var list<array<string, mixed>> $specs */
        $specs = array_values($validated['items'] ?? []);
        $problem = $this->itemProblem($specs);

        if ($problem !== null) {
            return Response::error($problem);
        }

        $board = $user->boards()->make([
            'title' => $validated['title'],
            'content' => $specs === [] ? null : ['items' => BoardItems::fromSpec($specs)],
        ]);
        $board->folder_id = $this->ensureFolderAt($user, $validated['folder'] ?? '')?->id;
        $board->save();

        return Response::structured($this->withItems($board->refresh()));
    }
}
