<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\NoteTool;
use App\Support\TiptapMarkdown;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a note. Content is Markdown: headings, lists, "- [ ]" to-dos, > quotes, ``` code (```mermaid for diagrams), GFM tables, ":::callout 💡 … :::" callouts, $…$ inline math and $$ … $$ math blocks (LaTeX).')]
class CreateNote extends NoteTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->description('The note title.')->required(),
            'markdown' => $schema->string()->description('The note body as Markdown.'),
            'is_wide' => $schema->boolean()->description('Use the full-width page layout.'),
            'folder' => $this->folderArgument($schema, 'Folder to create the note in; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'markdown' => ['nullable', 'string', 'max:500000'],
            'is_wide' => ['nullable', 'boolean'],
            'folder' => ['nullable', 'string', 'max:1000'],
        ]);

        $note = $user->notes()->make([
            'title' => $validated['title'],
            'content' => isset($validated['markdown']) ? TiptapMarkdown::toDoc($validated['markdown']) : null,
            'is_wide' => $validated['is_wide'] ?? false,
        ]);
        $note->folder_id = $this->folderAt($user, $validated['folder'] ?? '', create: true)?->id;
        $note->save();

        return Response::structured($this->withContent($note->refresh()));
    }
}
