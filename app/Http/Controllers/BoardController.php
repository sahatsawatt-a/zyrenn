<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardFolder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    /**
     * How the boards list can be sorted: key => [column, direction].
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
     * Browse a folder of the user's boards (the top level when none is given),
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
            $folder = $user->boardFolders()->where('ref_id', $filters['folder'])->firstOrFail();
            Gate::authorize('view', $folder);
        }

        $query = trim($filters['q'] ?? '');
        $searching = $query !== '';
        $sort = $filters['sort'] ?? 'edited';
        $edited = $filters['edited'] ?? null;
        [$column, $direction] = self::SORTS[$sort];

        $allFolders = $user->boardFolders()->get(['id', 'ref_id', 'parent_id', 'name']);
        $paths = BoardFolder::pathsById($allFolders);

        $boards = $user->boards()
            ->when(! $searching, fn ($boards) => $boards->where('folder_id', $folder?->id))
            ->when($searching, fn ($boards) => $boards->where(fn ($match) => $match
                ->whereLike('title', "%{$query}%")
                ->orWhereLike('plain_text', "%{$query}%")))
            ->when($edited, fn ($boards) => $boards->where(
                'updated_at',
                '>=',
                self::EDITED[$edited] === 0 ? now()->startOfDay() : now()->subDays(self::EDITED[$edited]),
            ))
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->get(['id', 'ref_id', 'folder_id', 'title', 'content', 'plain_text', 'updated_at', 'created_at'])
            ->map(fn (Board $board) => [
                'ref_id' => $board->ref_id,
                'title' => $board->title,
                // How much is on it, so the list says more than a title
                'items' => $board->itemCount(),
                'updated_at' => $board->updated_at,
                'created_at' => $board->created_at,
                // Search results come from every folder, so say where each one lives
                ...($searching ? [
                    'path' => $paths[$board->folder_id] ?? null,
                    'snippet' => $board->snippet($query),
                ] : []),
            ]);

        // Folders are always listed by name; a search matches them by name too
        $folders = $allFolders
            ->when(! $searching, fn ($all) => $all->where('parent_id', $folder?->id))
            ->when($searching, fn ($all) => $all->filter(fn (BoardFolder $item) => mb_stripos($item->name, $query) !== false))
            // An edited filter is about boards, so it hides folders rather than guessing at them
            ->when($edited, fn ($all) => $all->take(0))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (BoardFolder $item) => [
                'ref_id' => $item->ref_id,
                'name' => $item->name,
                ...($searching ? ['path' => $paths[$item->id]] : []),
            ])
            ->values();

        return Inertia::render('boards/Index', [
            'folder' => $folder?->only(['ref_id', 'name']),
            'breadcrumbs' => self::crumbs($folder),
            'folders' => $folders,
            'boards' => $boards,
            'filters' => ['q' => $query, 'sort' => $sort, 'edited' => $edited],
            'allFolders' => fn () => BoardFolder::paths($allFolders),
        ]);
    }

    /**
     * Create a blank board, in a folder when one is given, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'folder' => ['nullable', 'string', self::ownFolder($user)],
        ]);

        $board = $user->boards()->make();
        $board->folder_id = self::folderId($request->input('folder'));
        $board->save();

        return to_route('boards.show', $board);
    }

    /**
     * Show the board.
     */
    public function show(Board $board): Response
    {
        Gate::authorize('view', $board);

        return Inertia::render('boards/Show', [
            'board' => $board->only(['ref_id', 'title', 'content', 'updated_at']),
            'breadcrumbs' => self::crumbs($board->folder),
        ]);
    }

    /**
     * Autosave the board's title and contents, or move it to another folder.
     */
    public function update(Request $request, Board $board): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $board);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'array'],
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($request->user())],
        ]);

        if (array_key_exists('title', $validated)) {
            $validated['title'] = (string) $validated['title'];
        }

        if (array_key_exists('folder', $validated)) {
            $board->folder_id = self::folderId($validated['folder']);
            unset($validated['folder']);
        }

        $board->fill($validated)->save();

        // The editor autosaves with fetch; the boards list moves boards through Inertia
        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json([
            'updated_at' => $board->updated_at,
        ]);
    }

    /**
     * Delete the board.
     */
    public function destroy(Board $board): RedirectResponse
    {
        Gate::authorize('delete', $board);

        $folder = $board->folder;
        $board->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Board deleted.')]);

        return to_route('boards.index', $folder ? ['folder' => $folder->ref_id] : []);
    }

    /**
     * The user's boards, for the picker in a note. Newest first, by name.
     */
    public function pick(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        $boards = $request->user()->boards()
            ->when($request->filled('q'), fn ($query) => $query
                ->whereLike('title', '%'.$request->string('q').'%'))
            ->latest('updated_at')
            ->limit(100)
            ->get(['ref_id', 'title', 'updated_at']);

        return response()->json([
            'boards' => $boards->map(fn (Board $board) => [
                'ref_id' => $board->ref_id,
                'title' => $board->title,
                'updated_at' => $board->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * What is on a board, for showing it somewhere other than its own page.
     */
    public function content(Board $board): JsonResponse
    {
        Gate::authorize('view', $board);

        return response()->json([
            'ref_id' => $board->ref_id,
            'title' => $board->title,
            'items' => $board->content['items'] ?? [],
        ]);
    }

    /**
     * A board folder ref_id that belongs to the user.
     */
    public static function ownFolder(User $user): Exists
    {
        return Rule::exists('board_folders', 'ref_id')->where('user_id', $user->id);
    }

    /**
     * The id of a folder given by ref_id, already validated as the user's own.
     */
    public static function folderId(?string $refId): ?int
    {
        return $refId === null ? null : BoardFolder::query()->where('ref_id', $refId)->value('id');
    }

    /**
     * The path from the top level down to the folder, for breadcrumbs.
     *
     * @return list<array{ref_id: string, name: string}>
     */
    private static function crumbs(?BoardFolder $folder): array
    {
        return array_map(
            fn (BoardFolder $crumb) => $crumb->only(['ref_id', 'name']),
            $folder?->ancestry() ?? [],
        );
    }
}
