<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Controller;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DriveController extends Controller
{
    /**
     * How the file list can be sorted: key => [column, direction].
     */
    private const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'name' => ['name', 'asc'],
        'size' => ['size', 'desc'],
    ];

    private const KINDS = ['image', 'pdf', 'doc', 'audio', 'video', 'archive', 'other'];

    /**
     * Browse a folder of the user's Drive (the root when none is given), or
     * search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'folder' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'type' => ['nullable', Rule::in(self::KINDS)],
        ]);

        $folder = null;

        if (! empty($filters['folder'])) {
            $folder = $user->driveFolders()->where('ref_id', $filters['folder'])->firstOrFail();
            Gate::authorize('view', $folder);
        }

        $query = trim($filters['q'] ?? '');
        $searching = $query !== '';
        $sort = $filters['sort'] ?? 'newest';
        $type = $filters['type'] ?? null;
        [$column, $direction] = self::SORTS[$sort];

        $allFolders = $user->driveFolders()->get(['id', 'ref_id', 'parent_id', 'name']);
        $paths = DriveFolder::pathsById($allFolders);

        $files = $user->driveFiles()
            ->when(! $searching, fn ($files) => $files->where('folder_id', $folder?->id))
            ->when($searching, fn ($files) => $files->whereLike('name', "%{$query}%"))
            ->when($type, fn ($files) => $files->where('kind', $type))
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->get()
            ->map(fn (DriveFile $file) => [
                ...$file->card(),
                // Search results come from every folder, so say where each one lives
                ...($searching ? ['path' => $paths[$file->folder_id] ?? null] : []),
            ]);

        // Folders are always listed by name; a search matches them by name too
        $folders = $allFolders
            ->when(! $searching, fn ($all) => $all->where('parent_id', $folder?->id))
            ->when($searching, fn ($all) => $all->filter(fn (DriveFolder $item) => mb_stripos($item->name, $query) !== false))
            // A type filter is about files, so it hides folders
            ->when($type, fn ($all) => $all->take(0))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (DriveFolder $item) => [
                'ref_id' => $item->ref_id,
                'name' => $item->name,
                ...($searching ? ['path' => $paths[$item->id]] : []),
            ])
            ->values();

        return Inertia::render('drive/Index', [
            'folder' => $folder?->only(['ref_id', 'name']),
            'breadcrumbs' => array_map(
                fn (DriveFolder $crumb) => $crumb->only(['ref_id', 'name']),
                $folder?->ancestry() ?? [],
            ),
            'folders' => $folders,
            'files' => $files,
            'filters' => ['q' => $query, 'sort' => $sort, 'type' => $type],
            'allFolders' => fn () => DriveFolder::paths($allFolders),
        ]);
    }

    /**
     * The user's images, newest first, for the note editor's picker.
     */
    public function pick(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        $files = $request->user()->driveFiles()
            ->whereIn('mime', DriveFile::IMAGE_MIMES)
            ->when($request->filled('q'), fn ($query) => $query->whereLike('name', '%'.$request->string('q').'%'))
            ->latest()
            ->limit(120)
            ->get();

        return response()->json([
            'files' => $files->map(fn (DriveFile $file) => $file->card()),
        ]);
    }
}
