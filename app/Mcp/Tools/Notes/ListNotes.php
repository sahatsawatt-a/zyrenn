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
#[Description('List notes, most recently edited first. Optionally narrow to one folder, or search titles and note text.')]
class ListNotes extends NoteTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only notes whose title or text contains this (case-insensitive).'),
            'folder' => $this->folderArgument($schema, 'Only notes directly in this folder.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25)->description('Maximum number of notes to return.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $inFolder = array_key_exists('folder', $validated);
        $folder = $inFolder ? $this->folderAt($user, $validated['folder'] ?? '') : null;

        if ($folder === false) {
            return Response::error("There is no folder \"{$validated['folder']}\". See list-folders.");
        }

        $paths = NoteFolder::pathsById($user->noteFolders()->get(['id', 'parent_id', 'name']), '/');

        $notes = $user->notes()
            ->when($inFolder, fn ($query) => $query->where('folder_id', $folder?->id))
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where(fn ($match) => $match
                ->whereLike('title', "%{$search}%")
                ->orWhereLike('plain_text', "%{$search}%")))
            ->latest('updated_at')
            ->limit($validated['limit'] ?? 25)
            ->get()
            ->map(fn ($note) => $this->summary($note, $paths))
            ->all();

        return Response::structured(['notes' => $notes]);
    }
}
