<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Concerns\BrowsesFolders;
use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    use BrowsesFolders;

    /**
     * Browse a folder of the user's or the project's boards (the top level
     * when none is given), or search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        return $this->browse(
            $request,
            'boards/Index',
            'boards',
            $this->owner($request)->boards(),
            ['title', 'plain_text'],
            fn (Board $board, string $query) => [
                // How much is on it, so the list says more than a title
                'items' => $board->itemCount(),
                ...($query !== '' ? ['snippet' => $board->snippet($query)] : []),
            ],
        );
    }

    /**
     * Create a blank board, in a folder when one is given, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');

        $request->validate([
            'folder' => ['nullable', 'string', self::ownFolder($owner)],
        ]);

        $board = (new Board)->ownedBy($owner, $request->user());
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
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($board->owner())],
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

        $owner = $board->owner();
        $folder = $board->folder;
        $board->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Board deleted.')]);

        return redirect(self::ownerRoute($owner, 'boards.index', $folder ? ['folder' => $folder->ref_id] : []));
    }

    /**
     * The user's or the project's boards, for the picker in a note. Newest
     * first, by name.
     */
    public function pick(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        $boards = $this->owner($request)->boards()
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

    protected static function folderModel(): string
    {
        return BoardFolder::class;
    }
}
