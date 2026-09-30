<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * list-folders, list-board-folders, list-table-folders: every folder of the
 * kind as a path, with how many sit directly in it.
 */
trait ListsFolders
{
    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $owner = $this->targetOwner($request);
        $count = "{$this->plural()}_count";

        $folders = $this->folders($owner)->withCount($this->plural())->get();
        $paths = $this->folders($owner)->getRelated()::pathsById($folders, '/');

        $list = $folders
            ->map(fn ($folder) => [
                'path' => $paths[$folder->id],
                $count => $folder->getAttribute($count),
            ])
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return Response::structured([
            'folders' => $list,
            "top_level_{$count}" => $this->things($owner)->whereNull('folder_id')->count(),
        ]);
    }
}
