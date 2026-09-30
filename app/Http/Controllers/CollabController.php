<?php

namespace App\Http\Controllers;

use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Support\Live\Collab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * What the collaboration server asks of the app: whether someone may open a
 * shared note or board (with their own session), and -- with the shared
 * secret -- what one holds, and keeping what it has become.
 */
class CollabController extends Controller
{
    /**
     * May the signed-in user open this document, and change it? Asked with the
     * cookie their browser sent the collaboration server.
     */
    public function auth(Request $request): JsonResponse
    {
        $validated = $request->validate(['document' => ['required', 'string', 'max:64']]);
        $thing = Collab::find($validated['document']);

        abort_if($thing === null, 404);
        Gate::authorize('view', $thing);

        return response()->json([
            'user' => $request->user()->only(['id', 'name']),
            'read_only' => $request->user()->cannot('update', $thing),
        ]);
    }

    /**
     * What a document is opened from: its shared state, or when it has none,
     * what the app keeps of it.
     */
    public function show(string $document): JsonResponse
    {
        $thing = Collab::find($document);
        abort_if($thing === null, 404);

        return response()->json(['state' => $thing->ydoc, ...Collab::values($thing)]);
    }

    /**
     * Keeps what a document has become. Search, the lists, Markdown and MCP
     * read the content; the shared state reopens it with everyone's edits.
     */
    public function update(Request $request, string $document): Response
    {
        $thing = Collab::find($document);
        abort_if($thing === null, 404);

        $validated = $request->validate([
            'state' => ['required', 'string'],
            'title' => ['present', 'nullable', 'string', 'max:255'],
            'updated_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            ...($thing instanceof Note ? [
                'content' => ['present', 'nullable', 'array'],
                'is_wide' => ['required', 'boolean'],
            ] : [
                'items' => ['present', 'array'],
            ]),
        ]);

        $thing->ydoc = $validated['state'];
        $thing->title = (string) $validated['title'];

        if ($thing instanceof Note) {
            $thing->content = $validated['content'];
            $thing->is_wide = $validated['is_wide'];
        }

        if ($thing instanceof Board) {
            $thing->content = ['items' => $validated['items']];
        }

        if ($thing->isDirty(['title', 'content', 'is_wide']) && $validated['updated_by'] !== null) {
            $thing->updated_by = $validated['updated_by'];
        }

        $thing->save();

        return response()->noContent();
    }
}
