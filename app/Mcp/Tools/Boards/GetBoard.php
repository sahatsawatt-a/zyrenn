<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get everything on a board: each item with its kind, box, label and colours, and each connector with the items its ends are pinned to. Pass the list back to update-board to change it -- fields left out keep the value they have now, and a picture is reported as "has_picture" rather than its bytes.')]
class GetBoard extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        $board = $this->findBoard($user, $validated['ref_id']);

        if (! $board) {
            return Response::error("Board {$validated['ref_id']} was not found.");
        }

        return Response::structured($this->withItems($board));
    }
}
