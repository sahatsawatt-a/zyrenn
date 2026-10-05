<?php

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Models\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Saved places, changed from the map page with fetch. A place is always in
 * one of its owner's lists, and belongs to whoever the list belongs to.
 */
class PlaceController extends Controller
{
    use ActsForOwner;

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'list' => ['required', 'string', self::ownList($owner)],
            ...self::fields(required: true),
        ]);

        $list = PlaceList::query()->where('ref_id', $validated['list'])->firstOrFail();
        $place = (new Place)->ownedBy($owner, $request->user());
        $place->list_id = $list->id;
        $place->fill(self::values($validated))->save();

        return response()->json(['place' => $place->load('list:id,ref_id')->toMap()], 201);
    }

    public function update(Request $request, Place $place): JsonResponse
    {
        Gate::authorize('update', $place);

        $validated = $request->validate([
            'list' => ['sometimes', 'string', self::ownList($place->owner())],
            ...self::fields(required: false),
        ]);

        if (isset($validated['list'])) {
            $place->list_id = PlaceList::query()->where('ref_id', $validated['list'])->value('id');
        }

        $place->fill(self::values($validated))->save();

        return response()->json(['place' => $place->load('list:id,ref_id')->toMap()]);
    }

    public function destroy(Place $place): JsonResponse
    {
        Gate::authorize('delete', $place);

        $place->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function fields(bool $required): array
    {
        $must = $required ? 'required' : 'sometimes';

        return [
            'name' => [$must, 'string', 'max:200'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lat' => [$must, 'numeric', 'between:-90,90'],
            'lng' => [$must, 'numeric', 'between:-180,180'],
            'details' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * What may be written to a place: blank text stays blank, not null.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function values(array $validated): array
    {
        $values = array_intersect_key($validated, array_flip(['name', 'address', 'kind', 'note', 'lat', 'lng', 'details']));

        foreach (['address', 'kind'] as $text) {
            if (array_key_exists($text, $values)) {
                $values[$text] = (string) $values[$text];
            }
        }

        return $values;
    }

    /**
     * A list ref_id that belongs to the owner.
     */
    private static function ownList(Owner $owner): Exists
    {
        return Rule::exists('place_lists', 'ref_id')->where($owner->ownerColumn(), $owner->getKey());
    }
}
