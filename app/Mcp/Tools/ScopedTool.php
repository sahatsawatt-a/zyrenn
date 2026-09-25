<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every tool that acts on one user's content.
 *
 * - User mode: the target is always the authenticated token's owner.
 * - Global mode: the caller picks the target with a required `user_id` argument.
 *
 * Rows are always reached through the target user's own relations, so a tool
 * can never touch another user's note or file.
 *
 * @template TFolder of Model  the folder model this tool's content lives in
 */
abstract class ScopedTool extends Tool
{
    public function __construct(protected bool $global = false) {}

    /**
     * Arguments specific to this tool.
     *
     * @return array<string, Type>
     */
    abstract protected function arguments(JsonSchema $schema): array;

    /**
     * The user's folders of the kind this tool works with.
     *
     * @return HasMany<TFolder, User>
     */
    abstract protected function folders(User $user): HasMany;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $user = $this->global
            ? ['user_id' => $schema->integer()->description('ID of the user whose content to act on (see list-users).')->required()]
            : [];

        return [...$user, ...$this->arguments($schema)];
    }

    /**
     * Resolves the user this call acts on.
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

    /**
     * Schema for a folder given by path.
     */
    protected function folderArgument(JsonSchema $schema, string $description): Type
    {
        return $schema->string()->max(1000)->description($description.' A path of folder names separated by "/", e.g. "KT Plan/Lakeshore"; "" is the top level.');
    }

    /**
     * The user's folder at a path like "KT Plan/Lakeshore" ("" is the top
     * level, returned as null). `false` when no such folder exists.
     *
     * @return TFolder|false|null
     */
    protected function folderAt(User $user, string $path): Model|false|null
    {
        $folder = null;

        foreach ($this->pathNames($path) as $name) {
            $folder = $this->childFolder($user, $folder, $name);

            if (! $folder) {
                return false;
            }
        }

        return $folder;
    }

    /**
     * The same, making every missing folder along the way, like `mkdir -p`.
     *
     * @return TFolder|null
     */
    protected function ensureFolderAt(User $user, string $path): ?Model
    {
        $folder = null;

        foreach ($this->pathNames($path) as $name) {
            $folder = $this->childFolder($user, $folder, $name) ?? $this->folders($user)->create([
                'name' => mb_substr($name, 0, 255),
                'parent_id' => $folder?->getKey(),
            ]);
        }

        return $folder;
    }

    /**
     * @param  TFolder|null  $parent
     * @return TFolder|null
     */
    private function childFolder(User $user, ?Model $parent, string $name): ?Model
    {
        $siblings = fn () => $this->folders($user)->getQuery()->where('parent_id', $parent?->getKey());

        return $siblings()->where('name', $name)->first()
            // A wrong capital shouldn't create a near-duplicate folder
            ?? $siblings()->whereLike('name', $name)->first();
    }

    /**
     * The folder names in a path, ignoring blanks from "a//b" or a trailing "/".
     *
     * @return list<string>
     */
    private function pathNames(string $path): array
    {
        return array_values(array_filter(
            array_map('trim', explode('/', $path)),
            fn (string $name) => $name !== '',
        ));
    }
}
