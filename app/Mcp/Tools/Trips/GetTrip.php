<?php

namespace App\Mcp\Tools\Trips;

use App\Mcp\Tools\TripTool;
use App\Models\Map\Trip;
use App\Support\Maps\PlaceRefs;
use App\Support\Maps\TripDocument;
use App\Support\Maps\TripParts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
Get a trip. Without "day": its outline -- when it starts, the flight in and the flight home, the
hotels booked with their check-in, check-out and cost, what it all comes to ("totals": stops, rides
typed in by hand, hotels, nights), and every day with what it costs, its date, how it gets around
("pedestrian", "auto" or "bicycle"), when it starts, and its stops by id and name.

With "day" (its number, 1 for the first, or its id): that day in full -- every stop with all its
fields; its "route", the places it goes between in order (the hotel woken in, the airport landed at,
the stops, the night's hotel or the flight home), each by id; and its "rides", one between each two
places on the route one after the other, with what was typed in by hand for it ("by_hand": mode,
minutes, cost, note), or null where the map's router times it.

Times of day aren't here: the trip page works them out from the routes as it is opened. To change a
trip, use update-trip with the ids read here.
TEXT)]
class GetTrip extends TripTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'day' => $schema->string()->max(32)->description('A day\'s number (1 is the first) or id: that day in full.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'day' => ['nullable', 'max:32'],
        ]);

        /** @var Trip|null $trip */
        $trip = $this->find($owner, $validated['ref_id']);

        if (! $trip) {
            return $this->notFound($validated['ref_id']);
        }

        $doc = PlaceRefs::trip($owner, TripDocument::normalize($trip->content));
        $totals = TripDocument::totals($doc);

        if (! isset($validated['day'])) {
            $outline = TripParts::outline($doc);

            foreach ($outline['days'] as $at => $day) {
                $outline['days'][$at]['cost'] = $totals['days'][$at]['cost'];
            }

            return Response::structured([
                ...$this->summary($trip),
                ...$outline,
                'totals' => array_diff_key($totals, ['days' => true, 'stays' => true]),
            ]);
        }

        try {
            $index = TripParts::dayIndex($doc, (string) $validated['day']);
        } catch (InvalidArgumentException $problem) {
            return Response::error($problem->getMessage());
        }

        return Response::structured([
            ...$this->summary($trip),
            'currency' => $doc['currency'],
            ...TripParts::day($doc, $index),
            'cost' => $totals['days'][$index]['cost'],
        ]);
    }
}
