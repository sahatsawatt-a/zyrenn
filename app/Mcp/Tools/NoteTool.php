<?php

namespace App\Mcp\Tools;

use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\User;
use App\Support\TiptapMarkdown;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Base for note tools shared by both MCP servers.
 *
 * - User mode: the target is always the authenticated token's owner.
 * - Global mode: the caller picks the target with a required `user_id` argument.
 *
 * Notes are always looked up through the target user's `notes()` relation,
 * so a tool can never reach another user's note.
 */
abstract class NoteTool extends Tool
{
    public function __construct(protected bool $global = false) {}

    /**
     * Arguments specific to this tool.
     *
     * @return array<string, Type>
     */
    abstract protected function arguments(JsonSchema $schema): array;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $user = $this->global
            ? ['user_id' => $schema->integer()->description('ID of the user whose notes to act on (see list-users).')->required()]
            : [];

        return [...$user, ...$this->arguments($schema)];
    }

    /**
     * Resolves the user whose notes this call acts on.
     */
    protected function targetUser(Request $request): User
    {
        if (! $this->global) {
            $user = $request->user();

            // e.g. the token behind a running stdio session was revoked
            if (! $user instanceof User) {
                throw new AuthenticationException('This MCP token is no longer valid.');
            }

            return $user;
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.exists' => 'No user exists with that user_id.',
        ]);

        return User::query()->whereKey($validated['user_id'])->firstOrFail();
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
     * Schema for a folder given by path.
     */
    protected function folderArgument(JsonSchema $schema, string $description): Type
    {
        return $schema->string()->max(1000)->description($description.' A path of folder names separated by "/", e.g. "KT Plan/Lakeshore"; "" is the top level.');
    }

    /**
     * Find the user's folder at a path like "KT Plan/Lakeshore" ("" is the
     * top level, returned as null). With $create, missing folders along the
     * way are made, like `mkdir -p`; without it, a missing folder is `false`.
     */
    protected function folderAt(User $user, string $path, bool $create = false): NoteFolder|false|null
    {
        $folder = null;

        foreach (array_filter(array_map('trim', explode('/', $path)), 'strlen') as $name) {
            $next = $user->noteFolders()->where('parent_id', $folder?->id)->where('name', $name)->first()
                // A wrong capital shouldn't create a near-duplicate folder
                ?? $user->noteFolders()->where('parent_id', $folder?->id)->whereLike('name', $name)->first();

            if (! $next && ! $create) {
                return false;
            }

            if (! $next) {
                $next = new NoteFolder(['name' => mb_substr($name, 0, 255), 'parent_id' => $folder?->id]);
                $next->user()->associate($user)->save();
            }

            $folder = $next;
        }

        return $folder;
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
