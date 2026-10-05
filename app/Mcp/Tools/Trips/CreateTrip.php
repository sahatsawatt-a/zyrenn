<?php

namespace App\Mcp\Tools\Trips;

use App\Mcp\Tools\TripTool;
use App\Models\Map\Trip;
use App\Support\Maps\TripDocument;
use App\Support\Maps\TripParts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Create a trip: days of places to see, in the order they are visited, with the hotels booked and the
flights in and out. Every place needs a "lat" and "lng" -- search-places finds them. The trip page
works out the routes and the time of each stop when it is opened.

A two-day trip, for example:
{"title": "Shanghai", "start_date": "2026-11-03", "currency": "¥",
 "stays": [{"name": "Hotel Indigo", "lat": 31.2347, "lng": 121.4972,
            "check_in": "2026-11-03T15:00", "check_out": "2026-11-05T11:00", "cost": 1800}],
 "days": [{"stops": [{"name": "The Bund", "lat": 31.2397, "lng": 121.4906, "minutes": 90}]},
          {"mode": "auto", "start": "10:00", "stops": [{"name": "Yu Garden", "lat": 31.2272,
           "lng": 121.4921}, {"rest": "hotel"}]}]}

Each day begins at the hotel it woke in and ends at the one it sleeps in, when a stay covers that
night. A hotel's "cost" is for the whole stay. The answer has the id of every day and stop made, for update-trip.
TEXT)]
class CreateTrip extends TripTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->description('The trip title.')->required(),
            'start_date' => $schema->string()->description('The first day\'s date, YYYY-MM-DD. Today unless said.'),
            'currency' => $schema->string()->max(8)->description('What costs are in, as a symbol or code, e.g. "¥" or "EUR".'),
            'from_hotel' => $schema->boolean()->description('Begin and end each day at the hotel booked for it. True unless said.'),
            'days' => $schema->array()->max(TripDocument::MAX_DAYS)->items($schema->object([
                'mode' => $schema->string()->enum(TripDocument::TRAVEL_MODES)->description('How the day gets around: pedestrian (walking and transit, the default), auto or bicycle.'),
                'start' => $schema->string()->description('When the day starts, HH:MM. 09:00 unless said.'),
                'stops' => $schema->array()->max(TripDocument::MAX_STOPS)->items($this->placeArgument($schema))->description('The day\'s places, in the order visited.'),
            ]))->description('The days, in order: the first is on start_date. One empty day unless given.'),
            'stays' => $schema->array()->max(TripDocument::MAX_STAYS)->items($this->placeArgument($schema, [
                'cost' => $this->stayCostArgument($schema),
                'check_in' => $schema->string()->description('Check-in, local time: YYYY-MM-DDTHH:MM.'),
                'check_out' => $schema->string()->description('Check-out, local time: YYYY-MM-DDTHH:MM.'),
            ]))->description('Hotels booked, each with where it is and its check-in and check-out.'),
            ...$this->flightArguments($schema),
            'folder' => $this->folderArgument($schema, 'Folder to create the trip in; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'max:8'],
            'from_hotel' => ['nullable', 'boolean'],
            'days' => ['nullable', 'array', 'max:'.TripDocument::MAX_DAYS],
            'days.*' => ['array'],
            'days.*.mode' => ['nullable', 'in:'.implode(',', TripDocument::TRAVEL_MODES)],
            'days.*.start' => ['nullable', 'date_format:H:i'],
            ...$this->placeRules('days.*.stops', TripDocument::MAX_STOPS),
            ...$this->placeRules('stays', TripDocument::MAX_STAYS),
            'stays.*.check_in' => ['nullable', 'string', self::MOMENT],
            'stays.*.check_out' => ['nullable', 'string', self::MOMENT],
            ...$this->flightRules(),
        ]);

        // Hotels and flights first: a rest at the hotel needs the hotel
        $doc = TripDocument::normalize($this->withFlights([
            'startDate' => $validated['start_date'] ?? null,
            'currency' => $validated['currency'] ?? '',
            'fromHotel' => $validated['from_hotel'] ?? true,
            'stays' => array_map(fn (array $stay) => [
                'place' => [...$stay, 'kind' => $stay['kind'] ?? 'tourism/hotel', 'minutes' => 0, 'cost' => 0],
                'checkIn' => $stay['check_in'] ?? '',
                'checkOut' => $stay['check_out'] ?? '',
                'cost' => $stay['cost'] ?? 0,
            ], $this->listed($validated, 'stays')),
        ], $validated));

        $days = $this->listed($validated, 'days') ?: [[]];
        $doc['days'] = array_map(fn () => ['id' => TripDocument::newId(), 'stops' => [], 'legs' => []], $days);

        try {
            foreach ($days as $index => $day) {
                $doc['days'][$index] += ['mode' => $day['mode'] ?? null, 'start' => $day['start'] ?? null];

                foreach ($this->listed($day, 'stops') as $stop) {
                    $doc = TripParts::insertStop($doc, $index, TripParts::place($doc, $index, $stop));
                }
            }
        } catch (InvalidArgumentException $problem) {
            return Response::error($problem->getMessage());
        }

        $trip = (new Trip(['title' => $validated['title'], 'content' => $doc]))->ownedBy($owner, $user);
        $trip->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        $trip->save();

        return Response::structured($this->answer($trip->refresh(), [
            'days' => TripParts::outline($trip->content)['days'],
        ]));
    }
}
