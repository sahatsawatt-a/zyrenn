<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\NoteTool;
use App\Support\TiptapMarkdown;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Update or move a note. Only the fields you pass change. "markdown" replaces the whole body (read it with get-note first to edit part of it).')]
class UpdateNote extends NoteTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'title' => $schema->string()->max(255)->description('New title.'),
            'markdown' => $schema->string()->description('New body as Markdown; replaces the existing content.'),
            'is_wide' => $schema->boolean()->description('Use the full-width page layout.'),
            'folder' => $this->folderArgument($schema, 'Move the note to this folder; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'title' => ['sometimes', 'string', 'max:255'],
            'markdown' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'is_wide' => ['sometimes', 'boolean'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $note = $this->findNote($user, $validated['ref_id']);

        if (! $note) {
            return Response::error("Note {$validated['ref_id']} was not found.");
        }

        if (array_key_exists('title', $validated)) {
            $note->title = $validated['title'];
        }

        if (array_key_exists('markdown', $validated)) {
            $note->content = TiptapMarkdown::toDoc($validated['markdown'] ?? '');
        }

        if (array_key_exists('is_wide', $validated)) {
            $note->is_wide = $validated['is_wide'];
        }

        if (array_key_exists('folder', $validated)) {
            $note->folder_id = $this->folderAt($user, $validated['folder'] ?? '', create: true)?->id;
        }

        $note->save();

        return Response::structured($this->withContent($note));
    }
}
