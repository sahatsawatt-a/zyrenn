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
 * The lists saved places are kept in, read and changed from a page with fetch.
 */
class PlaceListController extends Controller
{
    use ActsForOwner;

    private const COLOR = ['string', 'regex:/^#[0-9a-fA-F]{6}$/'];

    /** An icon by its name in the app's icon set, which the page draws. */
    private const ICON = ['string', 'max:40', 'regex:/^[a-z0-9-]+$/'];

    /**
     * The lists, in their order, for choosing one to save a place in from
     * somewhere other than the map page -- a place shared in a chat.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'lists' => $this->owner($request)->placeLists()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (PlaceList $list) => $list->toMap()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', ...self::COLOR],
            'icon' => ['nullable', ...self::ICON],
        ]);

        $count = $owner->placeLists()->count();
        $list = (new PlaceList)->ownedBy($owner, $request->user());
        $list->fill([
            'name' => $validated['name'],
            // Each new list the next colour round
            'color' => $validated['color'] ?? PlaceList::COLORS[$count % count(PlaceList::COLORS)],
            'icon' => $validated['icon'] ?? 'bookmark',
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
            'icon' => ['sometimes', ...self::ICON],
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
