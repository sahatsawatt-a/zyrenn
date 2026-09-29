<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Controller;
use App\Models\Drive\DriveFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DriveFolderController extends Controller
{
    /**
     * Create a folder.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent' => ['nullable', 'string', DriveFileController::ownFolder($user)],
        ]);

        $folder = new DriveFolder([
            'name' => $validated['name'],
            'parent_id' => isset($validated['parent'])
                ? DriveFolder::query()->where('ref_id', $validated['parent'])->value('id')
                : null,
        ]);
        $folder->user()->associate($user)->save();

        return back();
    }

    /**
     * Rename a folder or move it under another one.
     */
    public function update(Request $request, DriveFolder $folder): RedirectResponse
    {
        Gate::authorize('update', $folder);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent' => ['sometimes', 'nullable', 'string', DriveFileController::ownFolder($request->user())],
        ]);

        if (array_key_exists('name', $validated)) {
            $folder->name = $validated['name'];
        }

        if (array_key_exists('parent', $validated)) {
            $parentId = $validated['parent'] === null
                ? null
                : DriveFolder::query()->where('ref_id', $validated['parent'])->value('id');

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
    public function destroy(DriveFolder $folder): RedirectResponse
    {
        Gate::authorize('delete', $folder);

        $folder->deleteTree();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder deleted.')]);

        return back();
    }
}
