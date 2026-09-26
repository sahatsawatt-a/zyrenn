<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a board and everything on it.')]
class DeleteBoard extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->targetUser($request);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        $board = $this->findBoard($user, $validated['ref_id']);

        if (! $board) {
            return Response::error("Board {$validated['ref_id']} was not found.");
        }

        $board->delete();
        $title = $board->title !== '' ? $board->title : 'Untitled';

        return Response::text("Deleted board {$validated['ref_id']} (\"{$title}\").");
    }
}
