<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\NoteTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a note.')]
class DeleteNote extends NoteTool
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

        $note = $this->findNote($user, $validated['ref_id']);

        if (! $note) {
            return Response::error("Note {$validated['ref_id']} was not found.");
        }

        $note->delete();

        return Response::text("Deleted note {$validated['ref_id']} (\"{$note->title}\").");
    }
}
