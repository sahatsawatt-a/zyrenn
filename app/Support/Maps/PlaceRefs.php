<?php

namespace App\Support\Maps;

use App\Models\Map\Place;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;

/**
 * A trip's stop, hotel or airport, or a table's location cell, can be one of
 * its owner's saved places -- by the place's ref_id ("placeRef" on a trip's
 * place, "place" in a cell). It also keeps a copy of where and what the place
 * is, and that copy is what it shows when the saved place is gone.
 *
 * Read through here, a reference shows the saved place as it is now: renamed
 * or moved on the map, it is renamed or moved in every trip and table that
 * names it. The copy catches up the next time the trip or row is saved.
 *
 * Only the owner's own saved places count -- a trip of a project's names the
 * project's places, never someone's private ones.
 */
class PlaceRefs
{
    /** What a reference takes from the saved place. */
    private const KEPT = ['name', 'address', 'kind', 'lat', 'lng'];

    /**
     * A trip's document with each of its places that is a saved place brought
     * up to date.
     *
     * @param  array<string, mixed>  $doc  as TripDocument::normalize leaves it
     * @return array<string, mixed>
     */
    public static function trip(Owner $owner, array $doc): array
    {
        $refs = [];
        $walk = function (callable $each) use (&$doc): void {
            foreach (['arrival', 'departure'] as $flight) {
                if (isset($doc[$flight]['airport'])) {
                    $each($doc[$flight]['airport']);
                }
            }

            foreach ($doc['stays'] as &$stay) {
                $each($stay['place']);
            }
            unset($stay);

            foreach ($doc['days'] as &$day) {
                foreach ($day['stops'] as &$stop) {
                    $each($stop);
                }
                unset($stop);
            }
            unset($day);
        };

        $walk(function (array $place) use (&$refs) {
            if (isset($place['placeRef'])) {
                $refs[] = $place['placeRef'];
            }
        });

        $saved = self::find($owner, $refs);

        if ($saved === []) {
            return $doc;
        }

        $walk(function (array &$place) use ($saved) {
            $found = $saved[$place['placeRef'] ?? ''] ?? null;

            // A rest at the hotel keeps its own name, "Rest at ...": only where it is follows
            if ($found) {
                $fields = ($place['rest'] ?? null) === 'hotel' ? ['lat', 'lng'] : self::KEPT;
                $place = [...$place, ...array_intersect_key($found->only(self::KEPT), array_flip($fields))];
            }
        });

        return $doc;
    }

    /**
     * A table's rows with each location cell that is a saved place brought up
     * to date.
     *
     * @param  list<array<string, mixed>>  $rows  as the grid reads them
     * @return list<array<string, mixed>>
     */
    public static function rows(Table $table, array $rows): array
    {
        $columns = $table->columns->where('type', 'location')->pluck('name')->all();

        if ($columns === [] || $rows === []) {
            return $rows;
        }

        $refs = [];

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (is_string($row[$column]['place'] ?? null)) {
                    $refs[] = $row[$column]['place'];
                }
            }
        }

        $saved = self::find($table->owner(), $refs);

        if ($saved === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($columns as $column) {
                $found = $saved[$row[$column]['place'] ?? ''] ?? null;

                if ($found) {
                    $row[$column] = [...$row[$column], ...self::location($found)];
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * A location written as just a saved place -- {"place": "k3x9m2p7qa"} --
     * filled in with where and what that place is, so the cell keeps a copy.
     * One that isn't the table owner's saved place is left as it was given.
     */
    public static function filledIn(TableColumn $column, mixed $value): mixed
    {
        $ref = is_array($value) ? ($value['place'] ?? null) : null;

        if (! is_string($ref) || isset($value['lat'], $value['lng'])) {
            return $value;
        }

        $table = $column->parentTable;
        $found = $table ? (self::find($table->owner(), [$ref])[$ref] ?? null) : null;

        return $found ? self::location($found) : $value;
    }

    /**
     * A saved place as a location cell holds it.
     *
     * @return array{lat: float, lng: float, label: string, place: string}
     */
    public static function location(Place $place): array
    {
        return [
            'lat' => $place->lat,
            'lng' => $place->lng,
            'label' => $place->address !== '' ? "{$place->name}, {$place->address}" : $place->name,
            'place' => $place->ref_id,
        ];
    }

    /**
     * The owner's saved places among these ref_ids, by ref_id.
     *
     * @param  list<string>  $refs
     * @return array<string, Place>
     */
    public static function find(Owner $owner, array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        return $owner->places()->getQuery()
            ->whereIn('ref_id', array_values(array_unique($refs)))
            ->get(['id', 'ref_id', ...self::KEPT])
            ->keyBy('ref_id')
            ->all();
    }
}
