<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Controller;
use App\Models\Note\Note;
use App\Models\Note\NoteVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteVersionController extends Controller
{
    /**
     * List the note's snapshots, newest first.
     */
    public function index(Note $note): JsonResponse
    {
        Gate::authorize('view', $note);

        return response()->json([
            'versions' => $note->versions->map(fn (NoteVersion $version) => $this->present($version))->values(),
        ]);
    }

    /**
     * Pin the note as it is now, as a version that is kept.
     */
    public function store(Request $request, Note $note): JsonResponse
    {
        Gate::authorize('update', $note);

        $validated = $request->validate(['label' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->present($note->snapshot(true, $validated['label'] ?? null)), 201);
    }

    /**
     * Pin or unpin a version, or rename its label.
     */
    public function update(Request $request, Note $note, NoteVersion $version): JsonResponse
    {
        Gate::authorize('update', $note);

        $validated = $request->validate([
            'pinned' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if (array_key_exists('pinned', $validated)) {
            $version->pinned_at = $validated['pinned'] ? ($version->pinned_at ?? now()) : null;
        }

        if (array_key_exists('label', $validated)) {
            $version->label = $validated['label'];
        }

        // A label belongs to a pin
        if (! $version->pinned_at) {
            $version->label = null;
        }

        $version->save();

        return response()->json($this->present($version));
    }

    /**
     * Delete a version.
     */
    public function destroy(Note $note, NoteVersion $version): JsonResponse
    {
        Gate::authorize('update', $note);

        $version->delete();

        return response()->json(null, 204);
    }

    /**
     * Put the note back as it was in a version. The state being replaced is
     * snapshotted first, so a restore can itself be undone.
     */
    public function restore(Note $note, NoteVersion $version): JsonResponse
    {
        Gate::authorize('update', $note);

        $note->snapshot();
        $note->fill(['title' => $version->title, 'content' => $version->content])->save();

        return response()->json(['updated_at' => $note->updated_at]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NoteVersion $version): array
    {
        return [
            'ref_id' => $version->ref_id,
            'title' => $version->title,
            'pinned' => $version->pinned_at !== null,
            'label' => $version->label,
            'created_at' => $version->created_at,
        ];
    }
}
