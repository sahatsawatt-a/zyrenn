<?php

namespace App\Mcp\Tools\Places;

use App\Mcp\Tools\PlaceTool;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Support\OwnerUrl;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List saved places -- the pins kept on the map, each in a list like "Want to go" -- with where each is, its note, and the rating and hours Google gave when it was looked up. Also every list, with how many places are in it. Optionally narrow to one list, or search names, addresses and notes.')]
class ListPlaces extends PlaceTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->max(80)->description('Only places in this list, by name or ref_id.'),
            'search' => $schema->string()->max(255)->description('Only places whose name, address or note contains this (case-insensitive).'),
            'limit' => $schema->integer()->min(1)->max(200)->default(50)->description('Maximum number of places to return.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'list' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $list = null;

        if (filled($validated['list'] ?? null)) {
            $list = $this->listNamed($owner, $validated['list']);

            if (! $list) {
                return Response::error("There is no list \"{$validated['list']}\". Leave \"list\" out to see every list.");
            }
        }

        $places = $owner->places()->getQuery()
            ->when($list, fn ($query) => $query->where('list_id', $list?->id))
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where(fn ($match) => $match
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('address', "%{$search}%")
                ->orWhereLike('note', "%{$search}%")))
            ->with('list:id,name')
            ->latest('updated_at')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return Response::structured([
            'lists' => $owner->placeLists()->getQuery()->withCount('places')->orderBy('sort_order')->get()
                ->map(fn (PlaceList $each) => ['ref_id' => $each->ref_id, 'name' => $each->name, 'places' => $each->places_count])
                ->all(),
            'places' => $places->map(fn (Place $place) => $this->described($place))->all(),
            'url' => OwnerUrl::to($owner, 'maps.index'),
        ]);
    }
}
