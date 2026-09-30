<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Support\Folders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DriveController extends Controller
{
    use ActsForOwner;

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
     * Browse a folder of the user's or the project's Drive (the root when none
     * is given), or search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        $owner = $this->owner($request);

        $filters = $request->validate([
            'folder' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'type' => ['nullable', Rule::in(self::KINDS)],
        ]);

        $folder = Folders::open(DriveFolder::class, $owner, $filters['folder'] ?? null);
        $query = trim($filters['q'] ?? '');
        $searching = $query !== '';
        $sort = $filters['sort'] ?? 'newest';
        $type = $filters['type'] ?? null;
        [$column, $direction] = self::SORTS[$sort];

        $allFolders = Folders::all(DriveFolder::class, $owner);
        $paths = DriveFolder::pathsById($allFolders);

        $files = $owner->driveFiles()
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

        return Inertia::render('drive/Index', [
            'folder' => $folder?->only(['ref_id', 'name']),
            'breadcrumbs' => Folders::crumbs($folder),
            // A type filter is about files, so it hides folders
            'folders' => Folders::listed($allFolders, $folder, $query, $paths, hide: $type !== null),
            'files' => $files,
            'filters' => ['q' => $query, 'sort' => $sort, 'type' => $type],
            'allFolders' => fn () => DriveFolder::paths($allFolders),
        ]);
    }

    /**
     * The user's or the project's images, newest first, for the note editor's picker.
     */
    public function pick(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        $files = $this->owner($request)->driveFiles()
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
