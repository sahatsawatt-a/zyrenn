<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

class NoteController extends Controller
{
    /**
     * How the notes list can be sorted: key => [column, direction].
     */
    private const SORTS = [
        'edited' => ['updated_at', 'desc'],
        'created' => ['created_at', 'desc'],
        'title' => ['title', 'asc'],
    ];

    /**
     * How far back the "edited" filter reaches, in days (0 = since midnight).
     */
    private const EDITED = ['today' => 0, 'week' => 7, 'month' => 30];

    /**
     * Browse a folder of the user's notes (the top level when none is given),
     * or search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'folder' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'edited' => ['nullable', Rule::in(array_keys(self::EDITED))],
        ]);

        $folder = null;

        if (! empty($filters['folder'])) {
            $folder = $user->noteFolders()->where('ref_id', $filters['folder'])->firstOrFail();
            Gate::authorize('view', $folder);
        }

        $query = trim($filters['q'] ?? '');
        $searching = $query !== '';
        $sort = $filters['sort'] ?? 'edited';
        $edited = $filters['edited'] ?? null;
        [$column, $direction] = self::SORTS[$sort];

        $allFolders = $user->noteFolders()->get(['id', 'ref_id', 'parent_id', 'name']);
        $paths = NoteFolder::pathsById($allFolders);

        $notes = $user->notes()
            ->when(! $searching, fn ($notes) => $notes->where('folder_id', $folder?->id))
            ->when($searching, fn ($notes) => $notes->where(fn ($match) => $match
                ->whereLike('title', "%{$query}%")
                ->orWhereLike('plain_text', "%{$query}%")))
            ->when($edited, fn ($notes) => $notes->where(
                'updated_at',
                '>=',
                self::EDITED[$edited] === 0 ? now()->startOfDay() : now()->subDays(self::EDITED[$edited]),
            ))
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->get(['id', 'ref_id', 'folder_id', 'title', 'plain_text', 'updated_at', 'created_at'])
            ->map(fn (Note $note) => [
                'ref_id' => $note->ref_id,
                'title' => $note->title,
                'updated_at' => $note->updated_at,
                'created_at' => $note->created_at,
                // Search results come from every folder, so say where each one lives
                ...($searching ? [
                    'path' => $paths[$note->folder_id] ?? null,
                    'snippet' => $note->snippet($query),
                ] : []),
            ]);

        // Folders are always listed by name; a search matches them by name too
        $folders = $allFolders
            ->when(! $searching, fn ($all) => $all->where('parent_id', $folder?->id))
            ->when($searching, fn ($all) => $all->filter(fn (NoteFolder $item) => mb_stripos($item->name, $query) !== false))
            // An edited filter is about notes, so it hides folders rather than guessing at them
            ->when($edited, fn ($all) => $all->take(0))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (NoteFolder $item) => [
                'ref_id' => $item->ref_id,
                'name' => $item->name,
                ...($searching ? ['path' => $paths[$item->id]] : []),
            ])
            ->values();

        return Inertia::render('notes/Index', [
            'folder' => $folder?->only(['ref_id', 'name']),
            'breadcrumbs' => self::crumbs($folder),
            'folders' => $folders,
            'notes' => $notes,
            'filters' => ['q' => $query, 'sort' => $sort, 'edited' => $edited],
            'allFolders' => fn () => NoteFolder::paths($allFolders),
        ]);
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

    /**
     * A note folder ref_id that belongs to the user.
     */
    public static function ownFolder(User $user): Exists
    {
        return Rule::exists('note_folders', 'ref_id')->where('user_id', $user->id);
    }

    /**
     * The id of a folder given by ref_id, already validated as the user's own.
     */
    public static function folderId(?string $refId): ?int
    {
        return $refId === null ? null : NoteFolder::query()->where('ref_id', $refId)->value('id');
    }

    /**
     * The path from the top level down to the folder, for breadcrumbs.
     *
     * @return list<array{ref_id: string, name: string}>
     */
    private static function crumbs(?NoteFolder $folder): array
    {
        return array_map(
            fn (NoteFolder $crumb) => $crumb->only(['ref_id', 'name']),
            $folder?->ancestry() ?? [],
        );
    }
}
