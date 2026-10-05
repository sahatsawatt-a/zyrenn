<?php

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Map\PlaceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The lists saved places are kept in, changed from the map page with fetch.
 */
class PlaceListController extends Controller
{
    use ActsForOwner;

    private const COLOR = ['string', 'regex:/^#[0-9a-fA-F]{6}$/'];

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', ...self::COLOR],
        ]);

        $count = $owner->placeLists()->count();
        $list = (new PlaceList)->ownedBy($owner, $request->user());
        $list->fill([
            'name' => $validated['name'],
            // Each new list the next colour round
            'color' => $validated['color'] ?? PlaceList::COLORS[$count % count(PlaceList::COLORS)],
            'sort_order' => $count,
        ])->save();

        return response()->json(['list' => $list->toMap()], 201);
    }

    public function update(Request $request, PlaceList $placeList): JsonResponse
    {
        Gate::authorize('update', $placeList);

        $placeList->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'color' => ['sometimes', ...self::COLOR],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]))->save();

        return response()->json(['list' => $placeList->toMap()]);
    }

    /**
     * A list goes; what was in it moves to another of the owner's lists
     * rather than vanishing -- unless it was the last list.
     */
    public function destroy(PlaceList $placeList): JsonResponse
    {
        Gate::authorize('delete', $placeList);

        $other = PlaceList::query()
            ->where($placeList->user_id !== null ? 'user_id' : 'project_id', $placeList->user_id ?? $placeList->project_id)
            ->whereKeyNot($placeList->getKey())
            ->orderBy('sort_order')
            ->first();

        DB::transaction(function () use ($placeList, $other) {
            if ($other) {
                $placeList->places()->update(['list_id' => $other->id]);
            }

            $placeList->delete();
        });

        return response()->json(['moved_to' => $other?->ref_id]);
    }
}
