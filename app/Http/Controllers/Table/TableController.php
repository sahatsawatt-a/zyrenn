<?php

namespace App\Http\Controllers\Table;

use App\Events\TableChanged;
use App\Http\Controllers\Concerns\BrowsesFolders;
use App\Http\Controllers\Controller;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\Table\TableFolder;
use App\Support\Live\Live;
use App\Support\Table\TableStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TableController extends Controller
{
    use BrowsesFolders;

    /**
     * Browse a folder of the user's or the project's tables (the top level
     * when none is given), or search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        return $this->browse(
            $request,
            'tables/Index',
            'tables',
            $this->owner($request)->tables()->withCount('columns'),
            ['title'],
            fn (Table $table) => [
                // The id column is always there; the ones the user added are what count
                'columns' => max(0, $table->columns_count - 1),
                'rows' => TableStorage::count($table),
            ],
        );
    }

    /**
     * Create a blank table, in a folder when one is given, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');

        $request->validate([
            'folder' => ['nullable', 'string', self::ownFolder($owner)],
        ]);

        $table = DB::transaction(function () use ($owner, $request) {
            $table = (new Table)->ownedBy($owner, $request->user());
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
            // Who a "user" column can name: the project's members, or on one's own table, oneself
            'people' => $table->project_id !== null
                ? $table->project->members()->orderBy('name')->pluck('name')
                : [$table->user->name],
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
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($table->owner())],
        ]);

        if (array_key_exists('title', $validated)) {
            $validated['title'] = (string) $validated['title'];
        }

        if (array_key_exists('folder', $validated)) {
            $table->folder_id = self::folderId($validated['folder']);
            unset($validated['folder']);
        }

        $table->fill($validated);
        $looksDifferent = $table->isDirty(['title', 'density']);
        $table->save();

        if ($looksDifferent) {
            Live::tell(new TableChanged($table, 'table', $table->only(['title', 'density'])));
        }

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

        $owner = $table->owner();
        $folder = $table->folder;
        $table->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Table deleted.')]);

        return redirect(self::ownerRoute($owner, 'tables.index', $folder ? ['folder' => $folder->ref_id] : []));
    }

    protected static function folderModel(): string
    {
        return TableFolder::class;
    }
}
