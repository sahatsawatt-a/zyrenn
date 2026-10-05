<?php

namespace App\Mcp\Tools;

use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Base for the saved-place tools shared by both MCP servers. A place is kept
 * in one of its owner's lists -- "Want to go", "Work" -- which stand where
 * other kinds have folders, though they never nest.
 *
 * @extends ScopedTool<PlaceList>
 */
abstract class PlaceTool extends ScopedTool
{
    /**
     * @return HasMany<PlaceList, covariant Model&Owner>
     */
    protected function folders(Owner $owner): HasMany
    {
        return $owner->placeLists();
    }

    /**
     * One of the owner's lists, by ref_id or name (any case).
     */
    protected function listNamed(Owner $owner, string $named): ?PlaceList
    {
        return $owner->placeLists()->getQuery()->where('ref_id', $named)->first()
            ?? $owner->placeLists()->getQuery()->where(DB::raw('lower(name)'), mb_strtolower($named))->orderBy('sort_order')->first();
    }

    /**
     * The list a place goes in: the one named, made if there is none by that
     * name; or with none named, the first -- "Saved", if there are none yet.
     */
    protected function listFor(Owner $owner, ?string $named, User $by): PlaceList
    {
        $named = trim((string) $named);
        $found = $named === ''
            ? $owner->placeLists()->getQuery()->orderBy('sort_order')->orderBy('id')->first()
            : $this->listNamed($owner, $named);

        if ($found) {
            return $found;
        }

        $count = $owner->placeLists()->count();
        $list = (new PlaceList)->ownedBy($owner, $by);
        $list->fill([
            'name' => $named === '' ? 'Saved' : mb_substr($named, 0, 80),
            'color' => PlaceList::COLORS[$count % count(PlaceList::COLORS)],
            'sort_order' => $count,
        ])->save();

        return $list;
    }

    /**
     * A place as a tool answers with it: where it is, its note, and the
     * rating and hours Google gave when it was looked up on the map.
     *
     * @return array<string, mixed>
     */
    protected function described(Place $place): array
    {
        $details = $place->details ?? [];

        return array_filter([
            'ref_id' => $place->ref_id,
            'list' => $place->list?->name,
            'name' => $place->name,
            'address' => $place->address,
            'kind' => $place->kind,
            'note' => $place->note,
            'lat' => $place->lat,
            'lng' => $place->lng,
            'rating' => $details['rating'] ?? null,
            'hours' => $details['hours'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
