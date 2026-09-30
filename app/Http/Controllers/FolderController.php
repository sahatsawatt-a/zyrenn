<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Models\Folder;
use App\Models\Owner;
use App\Support\Folders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Making, renaming, moving and deleting folders -- the same for every kind of
 * folder. A feature's folder controller only says which kind it is.
 *
 * The folder comes in as its ref_id and is looked up here, since route model
 * binding needs the concrete class in the signature.
 */
abstract class FolderController extends Controller
{
    use ActsForOwner;

    /**
     * The kind of folder this controller looks after.
     *
     * @return class-string<Folder>
     */
    abstract protected function folderModel(): string;

    /**
     * Folder names can't hold "/": MCP tools address folders by path, e.g. "KT Plan/Lakeshore".
     *
     * @return list<string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:/\//'];
    }

    /**
     * Create a folder, for the user or the project in the URL.
     */
    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'name' => $this->nameRules(),
            'parent' => ['nullable', 'string', $this->ownFolder($owner)],
        ], self::messages());

        $this->folderModel()::make([
            'name' => $validated['name'],
            'parent_id' => $this->folderId($validated['parent'] ?? null),
        ])->ownedBy($owner, $request->user())->save();

        return back();
    }

    /**
     * Rename a folder or move it under another one.
     */
    public function update(Request $request, string $folder): RedirectResponse
    {
        $folder = $this->find($folder);
        Gate::authorize('update', $folder);

        $validated = $request->validate([
            'name' => ['sometimes', ...$this->nameRules()],
            // Only under another folder of the same owner
            'parent' => ['sometimes', 'nullable', 'string', $this->ownFolder($folder->owner())],
        ], self::messages());

        if (array_key_exists('name', $validated)) {
            $folder->name = $validated['name'];
        }

        if (array_key_exists('parent', $validated)) {
            $parentId = $this->folderId($validated['parent']);

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
     * Delete a folder with everything in it.
     */
    public function destroy(string $folder): RedirectResponse
    {
        $folder = $this->find($folder);
        Gate::authorize('delete', $folder);

        $folder->deleteTree();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder deleted.')]);

        return back();
    }

    private function find(string $refId): Folder
    {
        return $this->folderModel()::query()->where('ref_id', $refId)->firstOrFail();
    }

    private function ownFolder(Owner $owner): Exists
    {
        return Folders::rule($this->folderModel(), $owner);
    }

    private function folderId(?string $refId): ?int
    {
        return Folders::idOf($this->folderModel(), $refId);
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return ['name.not_regex' => __('Folder names can’t contain “/”.')];
    }
}
