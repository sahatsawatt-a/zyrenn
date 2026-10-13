<?php

namespace App\Mcp\Tools;

use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use App\Support\Folders;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Base for every tool that acts on content: the user's own, or with a
 * `project` argument, a project's the user is in.
 *
 * Rows are always reached through the owner's own relations, so a tool can
 * never touch a note or file of anyone else's. In a project, a viewer can
 * only read; changing anything takes an owner or an editor.
 *
 * @template TFolder of Model  the folder model this tool's content lives in
 */
abstract class ScopedTool extends UserTool
{
    /**
     * The owner's folders of the kind this tool works with.
     *
     * @return HasMany<TFolder, covariant Model&Owner>
     */
    abstract protected function folders(Owner $owner): HasMany;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'project' => $schema->string()->max(255)->description('A project the user is in, by ref_id or name (see list-projects), to work on its shared content. Leave out for the user\'s own.'),
        ];
    }

    /**
     * Whose content this call is about: the project it names, or the user's
     * own. $changes when the call makes, changes or deletes something.
     */
    protected function targetOwner(Request $request, bool $changes = false): Owner
    {
        $user = $this->targetUser($request);
        $named = trim((string) $request->get('project', ''));

        if ($named === '') {
            return $user;
        }

        $project = $this->projectNamed($user, $named);
        $role = $project->getRelationValue('pivot')?->getAttribute('role');

        if ($changes && ! in_array($role, [Project::OWNER, Project::EDITOR], true)) {
            throw ValidationException::withMessages([
                'project' => "The user is a {$role} in \"{$project->name}\": only its owners and editors can change what is in it.",
            ]);
        }

        return $project;
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
    protected function folderAt(Owner $owner, string $path): Model|false|null
    {
        return Folders::at($this->folders($owner), $path);
    }

    /**
     * The same, making every missing folder along the way, like `mkdir -p`.
     *
     * @return TFolder|null
     */
    protected function ensureFolderAt(Owner $owner, string $path, User $by): ?Model
    {
        return Folders::ensure($this->folders($owner), $path, $by);
    }
}
