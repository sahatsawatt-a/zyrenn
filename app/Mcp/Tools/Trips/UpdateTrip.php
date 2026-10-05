<?php

namespace App\Mcp\Tools\Trips;

use App\Mcp\Tools\TripTool;
use App\Models\Map\Trip;
use App\Models\Owner;
use App\Support\Maps\PlaceRefs;
use App\Support\Maps\TripDocument;
use App\Support\Maps\TripParts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Change a trip a part at a time, by the ids get-trip shows. Only what you pass changes.

- "title", "start_date", "currency", "from_hotel", "folder"; "arrival" and "departure" change just
  the fields given ("airport": null takes the flight's airport off).
- Days -- named by number (1 is the first, counted as the trip was before this call) or id:
  "add_days" ({position, mode, start, stops}; at the end unless "position" says, 1 for first),
  "update_days" ({day, mode, start}), "delete_days".
- Stops: "add_stops" (a place with the "day" it goes in, and a "position" in it -- at the end unless
  said), "update_stops" ({id, and just the fields to change}; a "day" and/or "position" moves it),
  "delete_stops" (ids).
- Hotels: "add_stays", "update_stays" ({id, ...}), "delete_stays". A hotel's "cost" is the whole stay.
- Rides between two places one after the other on a day's route (get-trip with "day" lists them):
  "set_rides" ({from, to, mode, minutes, cost, note}) types one in by hand -- a metro, a taxi -- in
  place of the map's own routing; "clear_rides" ({from, to}) hands it back. "day" is needed only
  when the two are both hotels or airports, which more than one day can go between.

Every place needs a "lat" and "lng" (search-places finds them), but a rest: {"rest": "here"} is a
break where you are, {"rest": "hotel"} goes back to the day's hotel. If one change can't be made,
none are. Someone with the trip open is asked, when they next change it, whether to keep theirs or
take this.
TEXT)]
class UpdateTrip extends TripTool
{
    protected function arguments(JsonSchema $schema): array
    {
        // Each use its own: required() marks the type it is called on
        $day = fn () => $schema->string()->max(32)->description('A day, by number (1 is the first) or id.');
        $position = fn () => $schema->integer()->min(1)->description('Where in the day: 1 is first. At the end unless said.');
        $id = fn () => $schema->string()->max(32)->required();
        $ride = fn (bool $set) => $schema->object([
            'from' => $schema->string()->max(32)->required(),
            'to' => $schema->string()->max(32)->required(),
            'day' => $day(),
            ...($set ? [
                'mode' => $schema->string()->enum(TripDocument::LEG_MODES)->required(),
                'minutes' => $schema->integer()->min(0)->max(4320)->required(),
                'cost' => $schema->number()->min(0),
                'note' => $schema->string()->max(200)->description('e.g. "Line 2, exit 7".'),
            ] : []),
        ]);
        $stay = fn () => [
            'cost' => $this->stayCostArgument($schema),
            'check_in' => $schema->string()->description('Check-in, local time: YYYY-MM-DDTHH:MM.'),
            'check_out' => $schema->string()->description('Check-out, local time: YYYY-MM-DDTHH:MM.'),
        ];

        return [
            'ref_id' => $this->refIdArgument($schema),
            'title' => $schema->string()->max(255)->description('New title.'),
            'start_date' => $schema->string()->description('The first day\'s date, YYYY-MM-DD; every day moves with it.'),
            'currency' => $schema->string()->max(8),
            'from_hotel' => $schema->boolean()->description('Begin and end each day at the hotel booked for it.'),
            ...$this->flightArguments($schema),
            'add_days' => $schema->array()->max(TripDocument::MAX_DAYS)->items($schema->object([
                'position' => $schema->integer()->min(1)->description('Where it goes: 1 is first. At the end unless said.'),
                'mode' => $schema->string()->enum(TripDocument::TRAVEL_MODES),
                'start' => $schema->string()->description('HH:MM.'),
                'stops' => $schema->array()->max(TripDocument::MAX_STOPS)->items($this->placeArgument($schema)),
            ])),
            'update_days' => $schema->array()->max(TripDocument::MAX_DAYS)->items($schema->object([
                'day' => $day()->required(),
                'mode' => $schema->string()->enum(TripDocument::TRAVEL_MODES),
                'start' => $schema->string()->description('HH:MM.'),
            ])),
            'delete_days' => $schema->array()->max(TripDocument::MAX_DAYS)->items($day())->description('Days to take off, with their stops.'),
            'add_stops' => $schema->array()->max(TripDocument::MAX_STOPS)->items($this->placeArgument($schema, ['day' => $day()->required(), 'position' => $position()])),
            'update_stops' => $schema->array()->max(TripDocument::MAX_STOPS)->items($this->placeArgument($schema, ['id' => $id(), 'day' => $day(), 'position' => $position()])),
            'delete_stops' => $schema->array()->max(TripDocument::MAX_STOPS)->items($schema->string()->max(32)),
            'add_stays' => $schema->array()->max(TripDocument::MAX_STAYS)->items($this->placeArgument($schema, $stay())),
            'update_stays' => $schema->array()->max(TripDocument::MAX_STAYS)->items($this->placeArgument($schema, ['id' => $id(), ...$stay()])),
            'delete_stays' => $schema->array()->max(TripDocument::MAX_STAYS)->items($schema->string()->max(32)),
            'set_rides' => $schema->array()->max(TripDocument::MAX_LEGS)->items($ride(true)),
            'clear_rides' => $schema->array()->max(TripDocument::MAX_LEGS)->items($ride(false)),
            'folder' => $this->folderArgument($schema, 'Move the trip to this folder; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $day = ['nullable', 'max:32'];
        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'title' => ['sometimes', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:8'],
            'from_hotel' => ['sometimes', 'boolean'],
            ...$this->flightRules(),
            'add_days' => ['sometimes', 'array', 'max:'.TripDocument::MAX_DAYS],
            'add_days.*' => ['array'],
            'add_days.*.position' => ['nullable', 'integer', 'min:1'],
            'add_days.*.mode' => ['nullable', 'in:'.implode(',', TripDocument::TRAVEL_MODES)],
            'add_days.*.start' => ['nullable', 'date_format:H:i'],
            ...$this->placeRules('add_days.*.stops', TripDocument::MAX_STOPS),
            'update_days' => ['sometimes', 'array', 'max:'.TripDocument::MAX_DAYS],
            'update_days.*.day' => ['required', 'max:32'],
            'update_days.*.mode' => ['nullable', 'in:'.implode(',', TripDocument::TRAVEL_MODES)],
            'update_days.*.start' => ['nullable', 'date_format:H:i'],
            'delete_days' => ['sometimes', 'array', 'max:'.TripDocument::MAX_DAYS],
            'delete_days.*' => ['required', 'max:32'],
            ...$this->placeRules('add_stops', TripDocument::MAX_STOPS),
            'add_stops.*.day' => ['required', 'max:32'],
            'add_stops.*.position' => ['nullable', 'integer', 'min:1'],
            ...$this->placeRules('update_stops', TripDocument::MAX_STOPS),
            'update_stops.*.id' => ['required', 'string', 'max:32'],
            'update_stops.*.day' => $day,
            'update_stops.*.position' => ['nullable', 'integer', 'min:1'],
            'delete_stops' => ['sometimes', 'array', 'max:'.TripDocument::MAX_STOPS],
            'delete_stops.*' => ['string', 'max:32'],
            ...$this->placeRules('add_stays', TripDocument::MAX_STAYS),
            'add_stays.*.check_in' => ['nullable', 'string', self::MOMENT],
            'add_stays.*.check_out' => ['nullable', 'string', self::MOMENT],
            ...$this->placeRules('update_stays', TripDocument::MAX_STAYS),
            'update_stays.*.id' => ['required', 'string', 'max:32'],
            'update_stays.*.check_in' => ['nullable', 'string', self::MOMENT],
            'update_stays.*.check_out' => ['nullable', 'string', self::MOMENT],
            'delete_stays' => ['sometimes', 'array', 'max:'.TripDocument::MAX_STAYS],
            'delete_stays.*' => ['string', 'max:32'],
            'set_rides' => ['sometimes', 'array', 'max:'.TripDocument::MAX_LEGS],
            'set_rides.*.from' => ['required', 'string', 'max:32'],
            'set_rides.*.to' => ['required', 'string', 'max:32'],
            'set_rides.*.day' => $day,
            'set_rides.*.mode' => ['required', 'in:'.implode(',', TripDocument::LEG_MODES)],
            'set_rides.*.minutes' => ['required', 'integer', 'between:0,4320'],
            'set_rides.*.cost' => ['nullable', 'numeric', 'min:0'],
            'set_rides.*.note' => ['nullable', 'string', 'max:200'],
            'clear_rides' => ['sometimes', 'array', 'max:'.TripDocument::MAX_LEGS],
            'clear_rides.*.from' => ['required', 'string', 'max:32'],
            'clear_rides.*.to' => ['required', 'string', 'max:32'],
            'clear_rides.*.day' => $day,
        ]);

        /** @var Trip|null $trip */
        $trip = $this->find($owner, $validated['ref_id']);

        if (! $trip) {
            return $this->notFound($validated['ref_id']);
        }

        try {
            [$doc, $made] = $this->changed(PlaceRefs::trip($owner, TripDocument::normalize($trip->content)), $validated, $owner);
        } catch (InvalidArgumentException $problem) {
            return Response::error($problem->getMessage().' Nothing was changed.');
        }

        $trip->fill(['content' => $doc, ...array_intersect_key($validated, ['title' => true])]);

        if (array_key_exists('folder', $validated)) {
            $trip->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        }

        $trip->save();

        return Response::structured($this->answer($trip->refresh(), array_filter(['made' => array_filter($made)])));
    }

    /**
     * The trip with every change made, and the ids of what was added. Hotels
     * go first, as a rest at the hotel needs one; days are named as they were
     * before this call, so a deletion doesn't renumber the rest of it.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $validated
     * @return array{array<string, mixed>, array<string, list<string>>}
     */
    private function changed(array $doc, array $validated, Owner $owner): array
    {
        $made = ['days' => [], 'stops' => [], 'stays' => []];
        $was = $doc;
        // A day as the client named it, to the id it keeps through the changes
        $dayId = fn (mixed $day) => $was['days'][TripParts::dayIndex($was, (string) $day)]['id'];
        // Where that day is now -- by reference, as the days move under it
        $index = function (string $id) use (&$doc): int {
            return TripParts::dayIndex($doc, $id);
        };

        foreach (['start_date' => 'startDate', 'currency' => 'currency', 'from_hotel' => 'fromHotel'] as $field => $named) {
            if (array_key_exists($field, $validated)) {
                $doc[$named] = $validated[$field] ?? '';
            }
        }

        $doc = $this->withFlights($doc, $validated);

        // ---- hotels ----

        foreach ($validated['delete_stays'] ?? [] as $id) {
            array_splice($doc['stays'], TripParts::stayAt($doc, $id), 1);
        }

        foreach ($this->listed($validated, 'update_stays') as $given) {
            $at = TripParts::stayAt($doc, $given['id']);
            $stay = &$doc['stays'][$at];
            $given = $this->fromSaved($owner, $given);
            $stay['place'] = [...$stay['place'], ...array_intersect_key($given, array_flip(array_diff(TripParts::PLACE_FIELDS, ['cost'])))];
            $stay['cost'] = $given['cost'] ?? $stay['cost'] ?? 0;
            $stay['checkIn'] = $given['check_in'] ?? $stay['checkIn'];
            $stay['checkOut'] = $given['check_out'] ?? $stay['checkOut'];
            unset($stay);
        }

        foreach ($this->listed($validated, 'add_stays') as $given) {
            $place = TripParts::place($doc, 0, [...$this->hotel($this->fromSaved($owner, $given)), 'rest' => null]);
            $doc['stays'][] = [
                'id' => $made['stays'][] = TripDocument::newId(),
                'place' => $place,
                'checkIn' => $given['check_in'] ?? '',
                'checkOut' => $given['check_out'] ?? '',
                'cost' => $given['cost'] ?? 0,
            ];
        }

        // ---- days ----

        foreach ($this->listed($validated, 'update_days') as $given) {
            $at = $index($dayId($given['day']));
            $doc['days'][$at] = [...$doc['days'][$at], ...array_intersect_key($given, ['mode' => true, 'start' => true])];
        }

        $gone = array_map($dayId, $validated['delete_days'] ?? []);

        // Stops are named by id, which outlives a renumbering: resolve their days first
        $addTo = array_map(fn (array $given) => $dayId($given['day']), $this->listed($validated, 'add_stops'));
        $moveTo = array_map(fn (array $given) => isset($given['day']) ? $dayId($given['day']) : null, $this->listed($validated, 'update_stops'));

        $doc['days'] = array_values(array_filter($doc['days'], fn (array $day) => ! in_array($day['id'], $gone, true)));

        if ($doc['days'] === [] && empty($validated['add_days'])) {
            throw new InvalidArgumentException('A trip keeps at least one day.');
        }

        // ---- stops ----

        $doc = TripParts::deleteStops($doc, $validated['delete_stops'] ?? []);

        foreach ($this->listed($validated, 'update_stops') as $n => $given) {
            [$from, $at] = TripParts::stopAt($doc, $given['id']);
            $stop = TripParts::place($doc, $from, $this->fromSaved($owner, $given), $doc['days'][$from]['stops'][$at]);

            if ($moveTo[$n] === null && ! isset($given['position'])) {
                $doc['days'][$from]['stops'][$at] = $stop;

                continue;
            }

            $doc = TripParts::deleteStops($doc, [$given['id']]);
            $to = $moveTo[$n] === null ? $from : $index($moveTo[$n]);
            $doc = TripParts::insertStop($doc, $to, $stop, $given['position'] ?? null);
        }

        foreach ($this->listed($validated, 'add_days') as $given) {
            $new = ['id' => $made['days'][] = TripDocument::newId(), 'mode' => $given['mode'] ?? 'pedestrian', 'start' => $given['start'] ?? '09:00', 'stops' => [], 'legs' => []];
            $at = isset($given['position']) ? max(0, min((int) $given['position'] - 1, count($doc['days']))) : count($doc['days']);
            array_splice($doc['days'], $at, 0, [$new]);

            foreach ($this->listed($given, 'stops') as $stop) {
                $place = TripParts::place($doc, $at, $this->fromSaved($owner, $stop));
                $made['stops'][] = $place['id'];
                $doc = TripParts::insertStop($doc, $at, $place);
            }
        }

        foreach ($this->listed($validated, 'add_stops') as $n => $given) {
            $at = $index($addTo[$n]);
            $place = TripParts::place($doc, $at, $this->fromSaved($owner, array_diff_key($given, ['day' => true, 'position' => true])));
            $made['stops'][] = $place['id'];
            $doc = TripParts::insertStop($doc, $at, $place, $given['position'] ?? null);
        }

        // ---- rides ----

        foreach ($this->listed($validated, 'set_rides') as $given) {
            $leg = array_intersect_key($given, ['mode' => true, 'minutes' => true, 'cost' => true, 'note' => true]);
            $doc = TripParts::setLeg($doc, $given['from'], $given['to'], $leg, isset($given['day']) ? $dayId($given['day']) : null);
        }

        foreach ($this->listed($validated, 'clear_rides') as $given) {
            $doc = TripParts::setLeg($doc, $given['from'], $given['to'], null, isset($given['day']) ? $dayId($given['day']) : null);
        }

        return [$doc, $made];
    }
}
