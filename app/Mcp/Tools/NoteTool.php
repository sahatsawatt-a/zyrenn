<?php

namespace App\Mcp\Tools;

use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\User;
use App\Support\TiptapMarkdown;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;

/**
 * Base for the note tools shared by both MCP servers. The user scoping and
 * folder-path handling live in ScopedTool.
 *
 * @extends ScopedTool<NoteFolder>
 */
abstract class NoteTool extends ScopedTool
{
    /**
     * @return HasMany<NoteFolder, User>
     */
    protected function folders(User $user): HasMany
    {
        return $user->noteFolders();
    }

    protected function findNote(User $user, string $refId): ?Note
    {
        return $user->notes()->where('ref_id', $refId)->first();
    }

    /**
     * Schema for the note reference argument shared by single-note tools.
     */
    protected function refIdArgument(JsonSchema $schema): Type
    {
        return $schema->string()->description('The note\'s ref_id (shown on the note page, e.g. "k3x9m2p7qa").')->required();
    }

    /**
     * The note's folder as a path, e.g. "KT Plan/Lakeshore", or null at the top level.
     *
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many notes
     */
    protected function folderPath(Note $note, ?array $paths = null): ?string
    {
        if ($note->folder_id === null) {
            return null;
        }

        return $paths[$note->folder_id]
            ?? implode('/', array_map(fn (NoteFolder $folder) => $folder->name, $note->folder->ancestry()));
    }

    /**
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many notes
     * @return array<string, mixed>
     */
    protected function summary(Note $note, ?array $paths = null): array
    {
        return [
            'ref_id' => $note->ref_id,
            'user_id' => $note->user_id,
            'title' => $note->title,
            'folder' => $this->folderPath($note, $paths),
            'is_wide' => $note->is_wide,
            'updated_at' => $note->updated_at?->toIso8601String(),
            'url' => route('notes.show', $note),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function withContent(Note $note): array
    {
        return [
            ...$this->summary($note),
            'markdown' => TiptapMarkdown::toMarkdown($note->content),
        ];
    }
}
