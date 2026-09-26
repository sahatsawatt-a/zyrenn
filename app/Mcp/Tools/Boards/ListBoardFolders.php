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
#[Description('List every board folder as a path (e.g. "Plans/Q3"), with how many boards sit directly in it. Board folders are their own tree, separate from note and Drive folders. Pass a path as "folder" to list-boards, create-board or update-board.')]
class ListBoardFolders extends BoardTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $user = $this->targetUser($request);

        $folders = $user->boardFolders()->withCount('boards')->get();
        $paths = BoardFolder::pathsById($folders, '/');

        $list = $folders
            ->map(fn (BoardFolder $folder) => [
                'path' => $paths[$folder->id],
                'boards_count' => $folder->boards_count,
            ])
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return Response::structured([
            'folders' => $list,
            'top_level_boards_count' => $user->boards()->whereNull('folder_id')->count(),
        ]);
    }
}
