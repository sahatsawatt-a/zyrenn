<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * list-notes, list-boards, list-tables: most recently edited first, narrowed
 * to one folder or a search when asked.
 */
trait ListsThings
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description("Only {$this->plural()} whose ".implode(' or ', $this->searchIn()).' contains this (case-insensitive).'),
            'folder' => $this->folderArgument($schema, "Only {$this->plural()} directly in this folder."),
            'limit' => $schema->integer()->min(1)->max(100)->default(25)->description("Maximum number of {$this->plural()} to return."),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $inFolder = array_key_exists('folder', $validated);
        $folder = $inFolder ? $this->folderAt($owner, $validated['folder'] ?? '') : null;

        if ($folder === false) {
            return Response::error("There is no {$this->noun()} folder \"{$validated['folder']}\". See {$this->folderTool()}.");
        }

        $paths = $this->folderPaths($owner);

        $found = $this->things($owner)
            ->when($inFolder, fn ($query) => $query->where('folder_id', $folder?->getKey()))
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where(function ($match) use ($search) {
                foreach (array_keys($this->searchIn()) as $column) {
                    $match->orWhereLike($column, "%{$search}%");
                }
            }))
            ->with('project:id,ref_id')
            ->latest('updated_at')
            ->limit($validated['limit'] ?? 25)
            ->get()
            ->map(fn ($thing) => $this->summary($thing, $paths))
            ->all();

        return Response::structured([$this->plural() => $found]);
    }
}
