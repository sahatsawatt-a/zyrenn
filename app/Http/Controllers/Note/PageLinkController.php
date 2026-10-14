<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Controller;
use App\Models\Note\Note;
use App\Support\Note\PageLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A note's links to other pages, as the page showing it asks about them:
 * which pages there are to link to, what each link points at now, and which
 * notes link here.
 */
class PageLinkController extends Controller
{
    /**
     * The pages a few typed letters could mean: the note's owner's notes,
     * boards, tables and trips.
     */
    public function pick(Request $request, Note $note): JsonResponse
    {
        Gate::authorize('view', $note);

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return response()->json([
            'pages' => PageLinks::search($note->owner(), (string) ($validated['q'] ?? ''), $note),
        ]);
    }

    /**
     * What each link in the note points at now: the page's title, and its
     * address when the reader may open it.
     */
    public function show(Request $request, Note $note): JsonResponse
    {
        Gate::authorize('view', $note);

        $validated = $request->validate([
            'links' => ['present', 'array', 'max:500'],
            'links.*.kind' => ['nullable', 'string', 'max:16'],
            'links.*.ref' => ['nullable', 'string', 'max:32'],
            'links.*.title' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'links' => PageLinks::describe(array_values($validated['links']), $note, $request->user()),
        ]);
    }

    /**
     * The notes that link to this one.
     */
    public function linkedFrom(Request $request, Note $note): JsonResponse
    {
        Gate::authorize('view', $note);

        return response()->json([
            'notes' => PageLinks::linkedFrom($note, $request->user()),
        ]);
    }

    /**
     * The notes that link to a page of any kind: a board, a table, a trip.
     */
    public function linkedFromPage(Request $request, string $kind, string $ref): JsonResponse
    {
        abort_unless(isset(PageLinks::KINDS[$kind]), 404);

        $page = PageLinks::KINDS[$kind][0]::query()->where('ref_id', $ref)->firstOrFail();

        Gate::authorize('view', $page);

        return response()->json([
            'notes' => PageLinks::linkedFrom($page, $request->user()),
        ]);
    }
}
