<?php

namespace App\Mcp\Tools;

use App\Models\Map\Trip;
use App\Models\Map\TripFolder;
use App\Models\Owner;
use App\Support\Maps\PlaceRefs;
use App\Support\Maps\TripDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;

/**
 * Base for the trip tools shared by both MCP servers. Finding, listing and
 * folders are FiledTool's; trips add the places a trip is made of, which a
 * client writes the same way wherever they go -- a stop, a hotel, an airport.
 *
 * @extends FiledTool<TripFolder, Trip>
 */
abstract class TripTool extends FiledTool
{
    /** A local time, as the trip page writes one. */
    protected const MOMENT = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';

    protected function noun(): string
    {
        return 'trip';
    }

    /**
     * @return HasMany<TripFolder, covariant Model&Owner>
     */
    protected function folders(Owner $owner): HasMany
    {
        return $owner->tripFolders();
    }

    /**
     * @return HasMany<Trip, covariant Model&Owner>
     */
    protected function things(Owner $owner): HasMany
    {
        return $owner->trips();
    }

    protected function searchIn(): array
    {
        return ['title' => 'title', 'plain_text' => 'places'];
    }

    /**
     * @param  Trip  $thing
     */
    protected function details(Model $thing): array
    {
        return ['start_date' => $thing->startDate(), 'days' => $thing->dayCount()];
    }

    /**
     * The fields of one place, as a client writes it, with any more the
     * caller's list needs (a day to go in, a hotel's dates).
     *
     * @param  array<string, Type>  $also
     */
    protected function placeArgument(JsonSchema $schema, array $also = []): Type
    {
        return $schema->object([
            'place' => $schema->string()->max(16)->description('One of the saved places (list-places), by ref_id: its name, address and where it is come from it, and keep up with it when it is renamed or moved on the map. Other fields given here still count.'),
            'name' => $schema->string()->max(200)->description('What it is called.'),
            'lat' => $schema->number()->min(-90)->max(90)->description('Latitude. search-places finds where a place is.'),
            'lng' => $schema->number()->min(-180)->max(180)->description('Longitude.'),
            'address' => $schema->string()->max(300),
            'kind' => $schema->string()->max(120)->description('What sort of place, e.g. "museum".'),
            'minutes' => $schema->integer()->min(0)->max(4320)->description('How long is spent there. A new stop is 60 unless said; a rest 30 where you are, 90 at the hotel.'),
            'cost' => $schema->number()->min(0)->description('What it costs, in the trip\'s currency.'),
            'note' => $schema->string()->max(2000),
            'rest' => $schema->string()->enum(['here', 'hotel'])->description('Make it a rest instead of a place to see: "here" is a break wherever you are and needs no lat/lng; "hotel" goes back to the night\'s hotel to rest.'),
            'hours' => $schema->object()->description('Opening hours by weekday, as Google words them, e.g. {"monday": "9 AM–5 PM", "tuesday": "Closed"}.'),
            ...$also,
        ]);
    }

    /**
     * A place given as one of the owner's saved places ("place": its ref_id)
     * as the trip keeps it: that place's name, address and whereabouts, under
     * whatever else was given, and linked to it (see PlaceRefs).
     *
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when it is no saved place of theirs
     */
    protected function fromSaved(Owner $owner, array $given): array
    {
        $ref = $given['place'] ?? null;
        unset($given['place']);

        if (! is_string($ref)) {
            return $given;
        }

        $saved = PlaceRefs::find($owner, [$ref])[$ref]
            ?? throw new InvalidArgumentException("There is no saved place \"{$ref}\". list-places shows them.");

        return [
            ...$saved->only(['name', 'address', 'kind', 'lat', 'lng']),
            ...array_filter($given, fn ($value) => $value !== null),
            'placeRef' => $ref,
        ];
    }

    /**
     * A hotel as the trip keeps it: no time spent at it as a stop, its cost on
     * the stay rather than the place, and a hotel unless it says what else.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    protected function hotel(array $place): array
    {
        return [...$place, 'minutes' => 0, 'cost' => 0, 'kind' => ($place['kind'] ?? '') ?: 'tourism/hotel'];
    }

    /**
     * A hotel's cost: for the whole stay, not a night.
     */
    protected function stayCostArgument(JsonSchema $schema): Type
    {
        return $schema->number()->min(0)->description('What the whole stay costs, in the trip\'s currency.');
    }

    /**
     * Validation rules for a list of those under $key.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function placeRules(string $key, int $max): array
    {
        return [
            $key => ['sometimes', 'array', "max:{$max}"],
            "{$key}.*" => ['array'],
            "{$key}.*.place" => ['nullable', 'string', 'max:16'],
            "{$key}.*.name" => ['nullable', 'string', 'max:200'],
            "{$key}.*.lat" => ['nullable', 'numeric', 'between:-90,90'],
            "{$key}.*.lng" => ['nullable', 'numeric', 'between:-180,180'],
            "{$key}.*.address" => ['nullable', 'string', 'max:300'],
            "{$key}.*.kind" => ['nullable', 'string', 'max:120'],
            "{$key}.*.minutes" => ['nullable', 'integer', 'between:0,4320'],
            "{$key}.*.cost" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.note" => ['nullable', 'string', 'max:2000'],
            "{$key}.*.rest" => ['nullable', 'in:here,hotel'],
            "{$key}.*.hours" => ['nullable', 'array'],
        ];
    }

    /**
     * Validation rules for one airport, under $key.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function airportRules(string $key): array
    {
        return [
            $key => ['sometimes', 'nullable', 'array'],
            "{$key}.name" => ['required_with:'.$key, 'string', 'max:200'],
            "{$key}.lat" => ['required_with:'.$key, 'numeric', 'between:-90,90'],
            "{$key}.lng" => ['required_with:'.$key, 'numeric', 'between:-180,180'],
            "{$key}.address" => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * The flights in and out, as a client writes them.
     *
     * @return array<string, Type>
     */
    protected function flightArguments(JsonSchema $schema): array
    {
        $airport = fn (string $which) => $schema->object([
            'name' => $schema->string()->max(200)->required(),
            'lat' => $schema->number()->min(-90)->max(90)->required(),
            'lng' => $schema->number()->min(-180)->max(180)->required(),
            'address' => $schema->string()->max(300),
        ])->description("The airport {$which}; null takes it off.");

        return [
            'arrival' => $schema->object([
                'airport' => $airport('landed at'),
                'at' => $schema->string()->description('When the flight lands, local time: YYYY-MM-DDTHH:MM.'),
                'clear_minutes' => $schema->integer()->min(0)->max(4320)->description('From landing to leaving the airport: immigration, bags. 60 unless said.'),
                'rest_minutes' => $schema->integer()->min(0)->max(4320)->description('A rest at the hotel after landing, before the day goes on.'),
            ])->description('The flight in. Only the fields given change.'),
            'departure' => $schema->object([
                'airport' => $airport('flown home from'),
                'at' => $schema->string()->description('When the flight leaves, local time: YYYY-MM-DDTHH:MM.'),
                'early_minutes' => $schema->integer()->min(0)->max(4320)->description('How long before the flight to be at the airport. 180 unless said.'),
            ])->description('The flight home. Only the fields given change.'),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function flightRules(): array
    {
        return [
            'arrival' => ['sometimes', 'array'],
            ...$this->airportRules('arrival.airport'),
            'arrival.at' => ['sometimes', 'nullable', 'string', self::MOMENT],
            'arrival.clear_minutes' => ['sometimes', 'integer', 'between:0,4320'],
            'arrival.rest_minutes' => ['sometimes', 'integer', 'between:0,4320'],
            'departure' => ['sometimes', 'array'],
            ...$this->airportRules('departure.airport'),
            'departure.at' => ['sometimes', 'nullable', 'string', self::MOMENT],
            'departure.early_minutes' => ['sometimes', 'integer', 'between:0,4320'],
        ];
    }

    /**
     * The flights a client gave, laid over the document's.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function withFlights(array $doc, array $validated): array
    {
        $names = [
            'arrival' => ['at' => 'at', 'clear_minutes' => 'clearMinutes', 'rest_minutes' => 'restMinutes'],
            'departure' => ['at' => 'at', 'early_minutes' => 'earlyMinutes'],
        ];

        foreach ($names as $which => $fields) {
            $given = $validated[$which] ?? null;

            if (! is_array($given)) {
                continue;
            }

            if (array_key_exists('airport', $given)) {
                $doc[$which]['airport'] = $given['airport'] === null ? null : [
                    'id' => $doc[$which]['airport']['id'] ?? TripDocument::newId(),
                    'kind' => 'aeroway/aerodrome',
                    'minutes' => 0,
                    ...$given['airport'],
                ];
            }

            foreach ($fields as $field => $named) {
                if (array_key_exists($field, $given)) {
                    $doc[$which][$named] = $given[$field] ?? '';
                }
            }
        }

        return $doc;
    }

    /**
     * A list the client sent, back in the order sent. Validated data is
     * rebuilt rule by rule and can come out shuffled; its keys still say the
     * order, and a day's stops are in the order they are visited.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    protected function listed(array $validated, string $key): array
    {
        $items = is_array($validated[$key] ?? null) ? $validated[$key] : [];
        ksort($items);

        /** @var list<array<string, mixed>> */
        return array_values($items);
    }
}
