<?php

namespace App\Http\Controllers\Table;

use App\Http\Controllers\Controller;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\Table\TableFolder;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

class TableController extends Controller
{
    /**
     * How the tables list can be sorted: key => [column, direction].
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
     * Browse a folder of the user's tables (the top level when none is given),
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
            $folder = $user->tableFolders()->where('ref_id', $filters['folder'])->firstOrFail();
            Gate::authorize('view', $folder);
        }

        $query = trim($filters['q'] ?? '');
        $searching = $query !== '';
        $sort = $filters['sort'] ?? 'edited';
        $edited = $filters['edited'] ?? null;
        [$column, $direction] = self::SORTS[$sort];

        $allFolders = $user->tableFolders()->get(['id', 'ref_id', 'parent_id', 'name']);
        $paths = TableFolder::pathsById($allFolders);

        $tables = $user->tables()
            ->withCount('columns')
            ->when(! $searching, fn ($tables) => $tables->where('folder_id', $folder?->id))
            ->when($searching, fn ($tables) => $tables->whereLike('title', "%{$query}%"))
            ->when($edited, fn ($tables) => $tables->where(
                'updated_at',
                '>=',
                self::EDITED[$edited] === 0 ? now()->startOfDay() : now()->subDays(self::EDITED[$edited]),
            ))
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Table $table) => [
                'ref_id' => $table->ref_id,
                'title' => $table->title,
                // The id column is always there; the ones the user added are what count
                'columns' => max(0, $table->columns_count - 1),
                'rows' => TableStorage::count($table),
                'updated_at' => $table->updated_at,
                'created_at' => $table->created_at,
                // Search results come from every folder, so say where each one lives
                ...($searching ? ['path' => $paths[$table->folder_id] ?? null] : []),
            ]);

        // Folders are always listed by name; a search matches them by name too
        $folders = $allFolders
            ->when(! $searching, fn ($all) => $all->where('parent_id', $folder?->id))
            ->when($searching, fn ($all) => $all->filter(fn (TableFolder $item) => mb_stripos($item->name, $query) !== false))
            // An edited filter is about tables, so it hides folders rather than guessing at them
            ->when($edited, fn ($all) => $all->take(0))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (TableFolder $item) => [
                'ref_id' => $item->ref_id,
                'name' => $item->name,
                ...($searching ? ['path' => $paths[$item->id]] : []),
            ])
            ->values();

        return Inertia::render('tables/Index', [
            'folder' => $folder?->only(['ref_id', 'name']),
            'breadcrumbs' => self::crumbs($folder),
            'folders' => $folders,
            'tables' => $tables,
            'filters' => ['q' => $query, 'sort' => $sort, 'edited' => $edited],
            'allFolders' => fn () => TableFolder::paths($allFolders),
        ]);
    }

    /**
     * Create a blank table, in a folder when one is given, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'folder' => ['nullable', 'string', self::ownFolder($user)],
        ]);

        $table = DB::transaction(function () use ($user, $request) {
            $table = $user->tables()->make();
            $table->folder_id = self::folderId($request->input('folder'));
            $table->save();

            TableStorage::create($table);

            return $table;
        });

        return to_route('tables.show', $table);
    }

    /**
     * Show the table: its columns, and every row.
     */
    public function show(Table $table): Response
    {
        Gate::authorize('view', $table);

        return Inertia::render('tables/Show', [
            'table' => $table->only(['ref_id', 'title', 'density', 'updated_at']),
            'columns' => $table->columns->map(fn (TableColumn $column) => $column->toGrid())->values(),
            'rows' => TableStorage::rows($table),
            'breadcrumbs' => self::crumbs($table->folder),
        ]);
    }

    /**
     * Rename the table, change how it is drawn, or move it to another folder.
     */
    public function update(Request $request, Table $table): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $table);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'density' => ['sometimes', Rule::in(Table::DENSITIES)],
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($request->user())],
        ]);

        if (array_key_exists('title', $validated)) {
            $validated['title'] = (string) $validated['title'];
        }

        if (array_key_exists('folder', $validated)) {
            $table->folder_id = self::folderId($validated['folder']);
            unset($validated['folder']);
        }

        $table->fill($validated)->save();

        // The grid saves with fetch; the tables list moves tables through Inertia
        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json(['updated_at' => $table->updated_at]);
    }

    /**
     * Delete the table, and every row in it.
     */
    public function destroy(Table $table): RedirectResponse
    {
        Gate::authorize('delete', $table);

        $folder = $table->folder;
        $table->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Table deleted.')]);

        return to_route('tables.index', $folder ? ['folder' => $folder->ref_id] : []);
    }

    /**
     * A table folder ref_id that belongs to the user.
     */
    public static function ownFolder(User $user): Exists
    {
        return Rule::exists('table_folders', 'ref_id')->where('user_id', $user->id);
    }

    /**
     * The id of a folder given by ref_id, already validated as the user's own.
     */
    public static function folderId(?string $refId): ?int
    {
        return $refId === null ? null : TableFolder::query()->where('ref_id', $refId)->value('id');
    }

    /**
     * The path from the top level down to the folder, for breadcrumbs.
     *
     * @return list<array{ref_id: string, name: string}>
     */
    private static function crumbs(?TableFolder $folder): array
    {
        return array_map(
            fn (TableFolder $crumb) => $crumb->only(['ref_id', 'name']),
            $folder?->ancestry() ?? [],
        );
    }
}
