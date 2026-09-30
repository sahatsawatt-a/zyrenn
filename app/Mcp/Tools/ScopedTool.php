<?php

namespace App\Mcp\Tools;

use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
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
     * One of the user's projects, by ref_id or, failing that, by name.
     */
    private function projectNamed(User $user, string $named): Project
    {
        $project = $user->projects()->where('projects.ref_id', $named)->first();

        if ($project) {
            return $project;
        }

        $matches = $user->projects()
            ->where(DB::raw('lower(projects.name)'), mb_strtolower($named))
            ->get();

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => throw ValidationException::withMessages([
                'project' => "The user is in no project \"{$named}\". See list-projects.",
            ]),
            default => throw ValidationException::withMessages([
                'project' => "The user is in several projects called \"{$named}\"; pass one's ref_id instead (see list-projects).",
            ]),
        };
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
        $folder = null;

        foreach ($this->pathNames($path) as $name) {
            $folder = $this->childFolder($owner, $folder, $name);

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
    protected function ensureFolderAt(Owner $owner, string $path, User $by): ?Model
    {
        $folder = null;

        foreach ($this->pathNames($path) as $name) {
            $folder = $this->childFolder($owner, $folder, $name) ?? $this->newFolder($owner, $folder, $name, $by);
        }

        return $folder;
    }

    /**
     * @param  TFolder|null  $parent
     * @return TFolder
     */
    private function newFolder(Owner $owner, ?Model $parent, string $name, User $by): Model
    {
        $folder = $this->folders($owner)->make([
            'name' => mb_substr($name, 0, 255),
            'parent_id' => $parent?->getKey(),
        ]);
        $folder->setAttribute('created_by', $by->id);
        $folder->save();

        return $folder;
    }

    /**
     * @param  TFolder|null  $parent
     * @return TFolder|null
     */
    private function childFolder(Owner $owner, ?Model $parent, string $name): ?Model
    {
        $siblings = fn () => $this->folders($owner)->getQuery()->where('parent_id', $parent?->getKey());

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
