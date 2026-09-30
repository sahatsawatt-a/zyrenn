<?php

namespace App\Mcp\Tools\Drive;

use App\Mcp\Tools\DriveTool;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the files and folders in the user\'s Drive, newest first. Optionally narrow to one folder, search file names, or keep only one kind (image, pdf, doc, audio, video, archive, other). Each image comes back with a "markdown" line ready to put in a note.')]
class ListDrive extends DriveTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only files whose name contains this (case-insensitive). Searches every folder.'),
            'folder' => $this->folderArgument($schema, 'Only files directly in this folder.'),
            'kind' => $schema->string()->enum(['image', 'pdf', 'doc', 'audio', 'video', 'archive', 'other'])->description('Only files of this kind.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25)->description('Maximum number of files to return.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'kind' => ['nullable', 'string', 'in:image,pdf,doc,audio,video,archive,other'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $inFolder = array_key_exists('folder', $validated);
        $folder = $inFolder ? $this->folderAt($owner, $validated['folder'] ?? '') : null;

        if ($folder === false) {
            return Response::error("There is no folder \"{$validated['folder']}\" in the Drive. Call list-drive without a folder to see what is there.");
        }

        $paths = DriveFolder::pathsById($owner->driveFolders()->get(['id', 'parent_id', 'name']), '/');

        $files = $owner->driveFiles()
            ->when($inFolder, fn ($query) => $query->where('folder_id', $folder?->id))
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->whereLike('name', "%{$search}%"))
            ->when($validated['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->with('project:id,ref_id')
            ->latest('id')
            ->limit($validated['limit'] ?? 25)
            ->get()
            ->map(fn (DriveFile $file) => $this->summary($file, $paths))
            ->all();

        $folders = collect($paths)
            ->values()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return Response::structured([
            'files' => $files,
            'folders' => $folders,
        ]);
    }
}
