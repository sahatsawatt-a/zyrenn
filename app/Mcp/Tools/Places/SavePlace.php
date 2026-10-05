<?php

namespace App\Mcp\Tools\Places;

use App\Mcp\Tools\PlaceTool;
use App\Models\Map\Place;
use App\Support\OwnerUrl;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Save a place on the map, in a list -- or, given a saved place's "ref_id" (from list-places), change it:
only the fields passed change, and a "list" moves it there. A new place needs a "name", "lat" and
"lng"; search-places finds where something is. A list named that doesn't exist yet is made; with no
list named, a new place goes in the first list.
TEXT)]
class SavePlace extends PlaceTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $schema->string()->max(16)->description('A saved place to change, instead of saving a new one.'),
            'name' => $schema->string()->max(200),
            'lat' => $schema->number()->min(-90)->max(90),
            'lng' => $schema->number()->min(-180)->max(180),
            'address' => $schema->string()->max(300),
            'kind' => $schema->string()->max(120)->description('What sort of place, e.g. "restaurant".'),
            'note' => $schema->string()->max(5000),
            'list' => $schema->string()->max(80)->description('The list to keep it in, by name or ref_id.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);
        $creating = ! $request->filled('ref_id');
        $must = $creating ? 'required' : 'sometimes';

        $validated = $request->validate([
            'ref_id' => ['nullable', 'string', 'max:16'],
            'name' => [$must, 'string', 'max:200'],
            'lat' => [$must, 'numeric', 'between:-90,90'],
            'lng' => [$must, 'numeric', 'between:-180,180'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'list' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        if ($creating) {
            $place = (new Place)->ownedBy($owner, $user);
        } else {
            $place = $owner->places()->getQuery()->where('ref_id', $validated['ref_id'])->first();

            if (! $place) {
                return Response::error("Saved place {$validated['ref_id']} was not found.");
            }
        }

        if ($creating || array_key_exists('list', $validated)) {
            $place->list_id = $this->listFor($owner, $validated['list'] ?? null, $user)->id;
        }

        $values = array_intersect_key($validated, array_flip(['name', 'lat', 'lng', 'address', 'kind', 'note']));

        foreach (['address', 'kind'] as $text) {
            if (array_key_exists($text, $values)) {
                $values[$text] = (string) $values[$text];
            }
        }

        $place->fill($values)->save();

        return Response::structured([...$this->described($place->load('list:id,name')), 'url' => OwnerUrl::to($owner, 'maps.index')]);
    }
}
