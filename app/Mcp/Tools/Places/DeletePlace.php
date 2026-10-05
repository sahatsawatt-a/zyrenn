<?php

namespace App\Mcp\Tools\Places;

use App\Mcp\Tools\PlaceTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Take a saved place off the map. A trip that stops there keeps its own copy of the stop.')]
class DeletePlace extends PlaceTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $schema->string()->max(16)->description('The saved place\'s ref_id, from list-places.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $owner = $this->targetOwner($request, changes: true);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        $place = $owner->places()->getQuery()->where('ref_id', $validated['ref_id'])->first();

        if (! $place) {
            return Response::error("Saved place {$validated['ref_id']} was not found.");
        }

        $place->delete();

        return Response::text("Deleted saved place {$validated['ref_id']} (\"{$place->name}\").");
    }
}
