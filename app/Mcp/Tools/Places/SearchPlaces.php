<?php

namespace App\Mcp\Tools\Places;

use App\Mcp\Tools\UserTool;
use App\Support\Maps\Photon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[Description(<<<'TEXT'
Find places on OpenStreetMap by name or address, for their "lat" and "lng" -- to save one with
save-place, or put it on a trip. Give "lat" and "lng" of somewhere nearby (a trip's hotel, the city)
and the nearest matches come first: a name alone can find a namesake on another continent, so check
the address of what comes back. Add the city to the query when in doubt, e.g. "Yu Garden Shanghai".
TEXT)]
class SearchPlaces extends UserTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(200)->description('A name or address.')->required(),
            'lat' => $schema->number()->min(-90)->max(90)->description('Latitude of somewhere near, to prefer what is close to it.'),
            'lng' => $schema->number()->min(-180)->max(180)->description('Longitude of the same.'),
            'limit' => $schema->integer()->min(1)->max(10)->default(5),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->targetUser($request);

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $hits = Photon::search(
                $validated['query'],
                isset($validated['lat']) ? (float) $validated['lat'] : null,
                isset($validated['lng']) ? (float) $validated['lng'] : null,
                $validated['limit'] ?? 5,
            );
        } catch (ConnectionException|RuntimeException $problem) {
            return Response::error($problem->getMessage().' Try again in a moment.');
        }

        return Response::structured([
            'places' => array_map(fn (array $hit) => [
                'name' => $hit['name'],
                'address' => $hit['address'],
                'kind' => $hit['kind'],
                'lat' => $hit['lat'],
                'lng' => $hit['lng'],
            ], $hits),
        ]);
    }
}
