<?php

namespace App\Http\Controllers\Table;

use App\Http\Controllers\Controller;
use App\Models\Table\TableFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TableFolderController extends Controller
{
    private const NAME_MESSAGES = ['name.not_regex' => 'Folder names can’t contain “/”.'];

    /**
     * Create a folder.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => self::nameRules(),
            'parent' => ['nullable', 'string', TableController::ownFolder($user)],
        ], self::NAME_MESSAGES);

        $folder = new TableFolder([
            'name' => $validated['name'],
            'parent_id' => TableController::folderId($validated['parent'] ?? null),
        ]);
        $folder->user()->associate($user)->save();

        return back();
    }

    /**
     * Rename a folder or move it under another one.
     */
    public function update(Request $request, TableFolder $folder): RedirectResponse
    {
        Gate::authorize('update', $folder);

        $validated = $request->validate([
            'name' => ['sometimes', ...self::nameRules()],
            'parent' => ['sometimes', 'nullable', 'string', TableController::ownFolder($request->user())],
        ], self::NAME_MESSAGES);

        if (array_key_exists('name', $validated)) {
            $folder->name = $validated['name'];
        }

        if (array_key_exists('parent', $validated)) {
            $parentId = TableController::folderId($validated['parent']);

            if ($parentId !== null && in_array($parentId, $folder->subtreeIds(), true)) {
                throw ValidationException::withMessages([
                    'parent' => __('A folder can’t be moved into itself.'),
                ]);
            }

            $folder->parent_id = $parentId;
        }

        $folder->save();

        return back();
    }

    /**
     * Folder names can't hold "/": MCP tools address folders by path, e.g. "KT Plan/Lakeshore".
     *
     * @return list<string>
     */
    public static function nameRules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:/\//'];
    }

    /**
     * Delete a folder with every table and folder in it.
     */
    public function destroy(TableFolder $folder): RedirectResponse
    {
        Gate::authorize('delete', $folder);

        $folder->deleteTree();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder deleted.')]);

        return back();
    }
}
