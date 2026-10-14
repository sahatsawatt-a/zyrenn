<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\Board\BoardItems;
use App\Support\Board\Templates;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
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

A circuit is items of kind "symbol", each a part with pins, wired by arrows that name the pins they
join. Parts keep to a 20-unit grid so the wires run straight:
[{"id":"v1","kind":"symbol","symbol":"battery","text":"BT1\n9 V","x":0,"y":40},
 {"id":"r1","kind":"symbol","symbol":"resistor","text":"R1\n330 Ω","x":80,"y":0},
 {"id":"d1","kind":"symbol","symbol":"led","text":"D1","x":260,"y":40,"rotation":90},
 {"kind":"arrow","from":{"item":"v1","pin":"+"},"to":{"item":"r1","pin":"1"}},
 {"kind":"arrow","from":{"item":"r1","pin":"2"},"to":{"item":"d1","pin":"a"}},
 {"kind":"arrow","from":{"item":"d1","pin":"k"},"to":{"item":"v1","pin":"-"}}]

To start from a ready-made diagram instead of drawing one, name it in "template": the answer lists
its items, each under an id to change with update-board.
TEXT)]
class CreateBoard extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->description('The board title.')->required(),
            'items' => $this->itemsArgument($schema, 'What to put on the board, drawn back to front. An empty board is fine too.'),
            'template' => $schema->string()->enum(Templates::ids())->description('A diagram to start the board from, with sample words to change: '.Templates::described().'. Sent with "items", those are drawn over it.'),
            'folder' => $this->folderArgument($schema, 'Folder to create the board in; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'template' => ['nullable', 'string', Rule::in(Templates::ids())],
            ...$this->itemRules(),
        ]);

        // A template is the first thing drawn; what else was sent goes over it
        $specs = [...Templates::specs($validated['template'] ?? ''), ...$this->specs($validated)];
        $problem = $this->itemProblem($specs);

        if ($problem !== null) {
            return Response::error($problem);
        }

        $board = (new Board([
            'title' => $validated['title'],
            'content' => $specs === [] ? null : ['items' => BoardItems::fromSpec($specs)],
        ]))->ownedBy($owner, $user);
        $board->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        $board->save();

        return Response::structured($this->answer($board->refresh(), $this->overflow($board->content['items'] ?? [])));
    }
}
