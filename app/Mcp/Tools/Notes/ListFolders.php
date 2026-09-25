<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\NoteTool;
use App\Models\NoteFolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List every note folder as a path (e.g. "KT Plan/Lakeshore"), with how many notes sit directly in it. Pass a path as "folder" to list-notes, create-note or update-note.')]
class ListFolders extends NoteTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $user = $this->targetUser($request);

        $folders = $user->noteFolders()->withCount('notes')->get();
        $paths = NoteFolder::pathsById($folders, '/');

        $list = $folders
            ->map(fn (NoteFolder $folder) => [
                'path' => $paths[$folder->id],
                'notes_count' => $folder->notes_count,
            ])
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return Response::structured([
            'folders' => $list,
            'top_level_notes_count' => $user->notes()->whereNull('folder_id')->count(),
        ]);
    }
}
