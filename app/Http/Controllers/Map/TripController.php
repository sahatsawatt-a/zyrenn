<?php

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Concerns\BrowsesFolders;
use App\Http\Controllers\Controller;
use App\Models\Map\Trip;
use App\Models\Map\TripFolder;
use App\Support\Maps\TripDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TripController extends Controller
{
    use BrowsesFolders;

    /**
     * Browse a folder of the user's or the project's trips (the top level when
     * none is given), or search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        return $this->browse(
            $request,
            'trips/Index',
            'trips',
            $this->owner($request)->trips(),
            ['title', 'plain_text'],
            fn (Trip $trip, string $query) => [
                'days' => $trip->dayCount(),
                'start_date' => $trip->startDate(),
                ...($query !== '' ? ['snippet' => $trip->snippet($query)] : []),
            ],
        );
    }

    /**
     * Start a trip -- blank, or from a plan brought along (one made in the
     * demo, say) -- in a folder when one is given, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'folder' => ['nullable', 'string', self::ownFolder($owner)],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'array'],
        ]);

        $trip = (new Trip)->ownedBy($owner, $request->user());
        $trip->folder_id = self::folderId($validated['folder'] ?? null);
        $trip->title = trim((string) ($validated['title'] ?? '')) ?: __('New trip');
        $trip->content = $validated['content'] ?? null;
        $trip->save();

        return to_route('trips.show', $trip);
    }

    /**
     * Show the trip.
     */
    public function show(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        return Inertia::render('trips/Show', [
            'trip' => $trip->only(['ref_id', 'title', 'content', 'revision', 'updated_at']),
            'breadcrumbs' => self::crumbs($trip->folder),
        ]);
    }

    /**
     * The user's or the project's trips, newest first, for choosing one to
     * show somewhere else -- in a note.
     */
    public function pick(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        $trips = $this->owner($request)->trips()
            ->when($request->filled('q'), fn ($query) => $query
                ->whereLike('title', '%'.$request->string('q').'%'))
            ->latest('updated_at')
            ->limit(100)
            ->get(['ref_id', 'title', 'updated_at']);

        return response()->json([
            'trips' => $trips->map(fn (Trip $trip) => [
                'ref_id' => $trip->ref_id,
                'title' => $trip->title,
                'updated_at' => $trip->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * A trip and what it comes to, for showing it somewhere other than its
     * own page.
     */
    public function content(Trip $trip): JsonResponse
    {
        Gate::authorize('view', $trip);

        $doc = TripDocument::normalize($trip->content);

        return response()->json([
            'ref_id' => $trip->ref_id,
            'title' => $trip->title,
            'content' => $doc,
            'revision' => $trip->revision,
            'totals' => TripDocument::totals($doc),
        ]);
    }

    /**
     * Autosave the trip's title and plan, or move it to another folder.
     *
     * The page says which revision it last saw. When someone has saved since,
     * this save was made from an older copy: it is refused (409) with what is
     * there now, rather than quietly undoing their change.
     */
    public function update(Request $request, Trip $trip): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $trip);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'array'],
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($trip->owner())],
            'revision' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('revision', $validated) && $validated['revision'] !== $trip->revision) {
            $trip->loadMissing('editor:id,name');

            return response()->json([
                'message' => __('Someone else changed this trip since you opened it.'),
                'trip' => [
                    ...$trip->only(['title', 'content', 'revision', 'updated_at']),
                    'edited_by' => $trip->editor?->name,
                ],
            ], 409);
        }

        if (array_key_exists('title', $validated)) {
            $trip->title = (string) $validated['title'];
        }

        if (array_key_exists('content', $validated)) {
            $trip->content = $validated['content'];
        }

        if (array_key_exists('folder', $validated)) {
            $trip->folder_id = self::folderId($validated['folder']);
        }

        $trip->save();

        // The trip page autosaves with fetch; the trips list moves trips through Inertia
        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json($trip->only(['revision', 'updated_at']));
    }

    /**
     * Delete the trip.
     */
    public function destroy(Trip $trip): RedirectResponse
    {
        Gate::authorize('delete', $trip);

        $owner = $trip->owner();
        $folder = $trip->folder;
        $trip->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trip deleted.')]);

        return redirect(self::ownerRoute($owner, 'trips.index', $folder ? ['folder' => $folder->ref_id] : []));
    }

    protected static function folderModel(): string
    {
        return TripFolder::class;
    }
}
