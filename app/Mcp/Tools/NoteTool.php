<?php

namespace App\Mcp\Tools;

use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\User;
use App\Support\TiptapMarkdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Base for the note tools shared by both MCP servers. Finding, listing and
 * folders are FiledTool's; notes add their Markdown.
 *
 * @extends FiledTool<NoteFolder, Note>
 */
abstract class NoteTool extends FiledTool
{
    protected function noun(): string
    {
        return 'note';
    }

    /**
     * Note folders came first, so theirs is the plain name.
     */
    protected function folderTool(): string
    {
        return 'list-folders';
    }

    /**
     * @return HasMany<NoteFolder, User>
     */
    protected function folders(User $user): HasMany
    {
        return $user->noteFolders();
    }

    /**
     * @return HasMany<Note, User>
     */
    protected function things(User $user): HasMany
    {
        return $user->notes();
    }

    protected function searchIn(): array
    {
        return ['title' => 'title', 'plain_text' => 'text'];
    }

    /**
     * @param  Note  $thing
     */
    protected function details(Model $thing): array
    {
        return ['is_wide' => $thing->is_wide];
    }

    /**
     * @param  Note  $thing
     */
    protected function full(Model $thing): array
    {
        return [
            ...$this->summary($thing),
            'markdown' => TiptapMarkdown::toMarkdown($thing->content),
        ];
    }
}
