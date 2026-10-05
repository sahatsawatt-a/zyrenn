<?php

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    use ActsForOwner;

    /**
     * The map, with the user's or the project's saved places on it, by list.
     */
    public function index(Request $request): Response
    {
        $owner = $this->owner($request);

        return Inertia::render('maps/Index', [
            'lists' => $owner->placeLists()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (PlaceList $list) => $list->toMap()),
            'places' => $owner->places()->with('list:id,ref_id')->orderBy('id')->get()
                ->map(fn (Place $place) => $place->toMap()),
        ]);
    }
}
