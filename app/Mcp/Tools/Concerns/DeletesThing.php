<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * delete-note, delete-board, delete-table: gone for good.
 */
trait DeletesThing
{
    protected function arguments(JsonSchema $schema): array
    {
        return ['ref_id' => $this->refIdArgument($schema)];
    }

    public function handle(Request $request): Response
    {
        $user = $this->targetUser($request);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        $thing = $this->find($user, $validated['ref_id']);

        if (! $thing) {
            return $this->notFound($validated['ref_id']);
        }

        $title = $thing->getAttribute('title') ?: 'Untitled';
        $thing->delete();

        return Response::text("Deleted {$this->noun()} {$validated['ref_id']} (\"{$title}\").");
    }
}
