<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Concerns\BrowsesFolders;
use App\Http\Controllers\Controller;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NoteController extends Controller
{
    use BrowsesFolders;

    /**
     * Browse a folder of the user's notes (the top level when none is given),
     * or search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        return $this->browse(
            $request,
            'notes/Index',
            'notes',
            $request->user()->notes()->select(['id', 'ref_id', 'folder_id', 'title', 'plain_text', 'updated_at', 'created_at']),
            ['title', 'plain_text'],
            fn (Note $note, string $query) => $query !== ''
                ? ['snippet' => $note->snippet($query)]
                : [],
        );
    }

    /**
     * Create a blank note, in a folder when one is given, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'folder' => ['nullable', 'string', self::ownFolder($user)],
        ]);

        $note = $user->notes()->make();
        $note->folder_id = self::folderId($request->input('folder'));
        $note->save();

        return to_route('notes.show', $note);
    }

    /**
     * Show the note editor.
     */
    public function show(Note $note): Response
    {
        Gate::authorize('view', $note);

        return Inertia::render('notes/Show', [
            'note' => $note->only(['ref_id', 'title', 'content', 'is_wide', 'updated_at']),
            'breadcrumbs' => self::crumbs($note->folder),
        ]);
    }

    /**
     * Autosave the note's title, content and page width, or move it to
     * another folder.
     */
    public function update(Request $request, Note $note): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $note);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'array'],
            'is_wide' => ['sometimes', 'boolean'],
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($request->user())],
        ]);

        if (array_key_exists('title', $validated)) {
            $validated['title'] = (string) $validated['title'];
        }

        if (array_key_exists('folder', $validated)) {
            $note->folder_id = self::folderId($validated['folder']);
            unset($validated['folder']);
        }

        $note->fill($validated)->save();

        if ($note->wasChanged('content')) {
            $note->snapshotIfDue();
        }

        // The editor autosaves with fetch; the notes list moves notes through Inertia
        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json([
            'updated_at' => $note->updated_at,
        ]);
    }

    /**
     * Delete the note.
     */
    public function destroy(Note $note): RedirectResponse
    {
        Gate::authorize('delete', $note);

        $folder = $note->folder;
        $note->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note deleted.')]);

        return to_route('notes.index', $folder ? ['folder' => $folder->ref_id] : []);
    }

    protected static function folderModel(): string
    {
        return NoteFolder::class;
    }
}
