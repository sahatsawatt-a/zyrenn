<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Controller;
use App\Models\Note\Note;
use App\Support\Formula\NoteValues;
use App\Support\Formula\Parser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A note's live values, asked for by the page showing it: the formulas it
 * shows, and what each comes to now among the note's owner's things.
 */
class NoteValueController extends Controller
{
    public function __invoke(Request $request, Note $note): JsonResponse
    {
        Gate::authorize('view', $note);

        $validated = $request->validate([
            'expressions' => ['present', 'array', 'max:'.NoteValues::MAX],
            'expressions.*' => ['string', 'max:'.Parser::MAX_LENGTH],
        ]);

        return response()->json([
            'values' => NoteValues::of($note, array_values(array_unique($validated['expressions']))),
        ]);
    }
}
