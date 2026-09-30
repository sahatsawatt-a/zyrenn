<?php

namespace App\Mcp\Tools\Drive;

use App\Mcp\Tools\DriveTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Rename a Drive file or move it to another folder. Only the fields you pass change. The file keeps its ref_id and URL, so notes that already show it are unaffected.')]
class UpdateFile extends DriveTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'name' => $schema->string()->max(255)->description('New file name. Keep the extension: it decides how the file is served back.'),
            'folder' => $this->folderArgument($schema, 'Move the file to this folder; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'name' => ['sometimes', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $file = $this->findFile($owner, $validated['ref_id']);

        if (! $file) {
            return Response::error("File {$validated['ref_id']} was not found.");
        }

        if (array_key_exists('name', $validated)) {
            $file->name = basename(str_replace('\\', '/', trim($validated['name'])));
        }

        if (array_key_exists('folder', $validated)) {
            $file->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        }

        $file->save();

        return Response::structured($this->summary($file));
    }
}
