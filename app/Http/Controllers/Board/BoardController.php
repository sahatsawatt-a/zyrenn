<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Concerns\BrowsesFolders;
use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Live\Collab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    use BrowsesFolders;

    /** How long a board's page waits for the live copy before opening from what was kept. */
    private const FLUSH_SECONDS = 2;

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
    public function show(Board $board): Response|RedirectResponse
    {
        Gate::authorize('view', $board);

        // A prototype is drawn on here too, but lives at its own address
        if ($board->isPrototype()) {
            return to_route('prototypes.show', $board);
        }

        return self::page($board);
    }

    /**
     * The page a board -- or a prototype -- is drawn on.
     */
    public static function page(Board $board): Response
    {
        // Open and being drawn on: shown as everyone has it now, not as it was
        // last kept -- so a page loaded again doesn't show, for a moment,
        // what was just taken away. Unanswered, it opens from what was kept.
        Collab::flush($board, timeout: self::FLUSH_SECONDS);
        $board->refresh();

        return Inertia::render('boards/Show', [
            'board' => $board->only(['ref_id', 'title', 'content', 'updated_at']),
            'breadcrumbs' => self::crumbs($board->folder),
            // A prototype's own settings, for its screens bar; none on a board
            'prototype' => $board->isPrototype() ? ($board->prototype ?? ['device' => 'phone', 'start' => null]) : null,
            // Its project's tables, for its screens to list, open and save to
            'tables' => $board->isPrototype() ? self::tablesFor($board) : [],
        ]);
    }

    /**
     * A prototype's project's tables, each with its columns as the grid shows
     * them -- in order, hidden ones left out.
     *
     * @return array<int, array{ref_id: string, title: string, columns: array<int, array{label: string, type: string}>}>
     */
    private static function tablesFor(Board $board): array
    {
        if ($board->project === null) {
            return [];
        }

        return $board->project->tables()
            ->with('columns')
            ->orderBy('title')
            ->get()
            ->map(fn (Table $table) => [
                'ref_id' => $table->ref_id,
                'title' => $table->title,
                'columns' => $table->columns
                    ->reject(fn (TableColumn $column) => (bool) $column->getAttribute('hidden'))
                    ->map(fn (TableColumn $column) => ['label' => $column->label, 'type' => $column->type])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The presenter's window for a board being shown as slides. What it shows
     * comes from the show itself, in the same browser (usePresenterLink).
     */
    public function presenter(Board $board): Response
    {
        Gate::authorize('view', $board);

        return Inertia::render('boards/Presenter', [
            'board' => $board->only(['ref_id', 'title']),
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

        // A prototype goes back to its project's prototypes
        if ($board->isPrototype() && $board->project) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Prototype deleted.')]);

            return to_route('projects.prototypes.index', $board->project);
        }

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
            'deck' => $board->content['deck'] ?? null,
        ]);
    }

    protected static function folderModel(): string
    {
        return BoardFolder::class;
    }
}
