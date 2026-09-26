<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\BoardFolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List boards, most recently edited first. Optionally narrow to one folder, or search titles and the labels written on the boards.')]
class ListBoards extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only boards whose title or labels contain this (case-insensitive).'),
            'folder' => $this->folderArgument($schema, 'Only boards directly in this folder.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25)->description('Maximum number of boards to return.'),
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
            return Response::error("There is no board folder \"{$validated['folder']}\". See list-board-folders.");
        }

        $paths = BoardFolder::pathsById($user->boardFolders()->get(['id', 'parent_id', 'name']), '/');

        $boards = $user->boards()
            ->when($inFolder, fn ($query) => $query->where('folder_id', $folder?->id))
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where(fn ($match) => $match
                ->whereLike('title', "%{$search}%")
                ->orWhereLike('plain_text', "%{$search}%")))
            ->latest('updated_at')
            ->limit($validated['limit'] ?? 25)
            ->get()
            ->map(fn ($board) => $this->summary($board, $paths))
            ->all();

        return Response::structured(['boards' => $boards]);
    }
}
