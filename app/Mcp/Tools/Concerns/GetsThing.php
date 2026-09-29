<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * get-note, get-board, get-table: one of them, with everything in it.
 */
trait GetsThing
{
    protected function arguments(JsonSchema $schema): array
    {
        return ['ref_id' => $this->refIdArgument($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        $thing = $this->find($user, $validated['ref_id']);

        return $thing ? Response::structured($this->full($thing)) : $this->notFound($validated['ref_id']);
    }
}
