<?php

namespace App\Mcp\Tools;

use App\Models\Folder;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

/**
 * Base for the tools about one kind of titled thing kept in folders -- notes,
 * boards, tables. A kind says what it is called, where the user keeps them,
 * and what it shows of one; finding one, naming its folder and summing it up
 * are the same for all of them. The list, list-folders, get and delete tools
 * are shared outright, in Concerns.
 *
 * @template TFolder of Folder
 * @template TThing of Model
 *
 * @extends ScopedTool<TFolder>
 */
abstract class FiledTool extends ScopedTool
{
    /**
     * What one of them is called, e.g. "board".
     */
    abstract protected function noun(): string;

    /**
     * The user's things of this kind.
     *
     * @return HasMany<TThing, User>
     */
    abstract protected function things(User $user): HasMany;

    /**
     * The columns a search looks in, with what each is called, e.g. ['title' => 'title'].
     *
     * @return array<string, string>
     */
    abstract protected function searchIn(): array;

    /**
     * Everything about one, as get returns it and create and update answer with.
     *
     * @param  TThing  $thing
     * @return array<string, mixed>
     */
    abstract protected function full(Model $thing): array;

    /**
     * What a list says of one beyond its title, folder and dates.
     *
     * @param  TThing  $thing
     * @return array<string, mixed>
     */
    protected function details(Model $thing): array
    {
        return [];
    }

    protected function plural(): string
    {
        return Str::plural($this->noun());
    }

    /**
     * The tool that lists this kind's folders, e.g. "list-board-folders".
     */
    protected function folderTool(): string
    {
        return "list-{$this->noun()}-folders";
    }

    /**
     * @return TThing|null
     */
    protected function find(User $user, string $refId): ?Model
    {
        return $this->things($user)->getQuery()->where('ref_id', $refId)->first();
    }

    protected function notFound(string $refId): Response
    {
        return Response::error(Str::ucfirst($this->noun())." {$refId} was not found.");
    }

    /**
     * Schema for the reference argument of a tool about one of them.
     */
    protected function refIdArgument(JsonSchema $schema): Type
    {
        return $schema->string()->description("The {$this->noun()}'s ref_id (shown on its page, e.g. \"k3x9m2p7qa\").")->required();
    }

    /**
     * Every folder's path, e.g. "KT Plan/Lakeshore", keyed by id.
     *
     * @return array<int, string>
     */
    protected function folderPaths(User $user): array
    {
        return $this->folders($user)->getRelated()::pathsById($this->folders($user)->get(['id', 'parent_id', 'name']), '/');
    }

    /**
     * The folder one is kept in, as a path, or null at the top level.
     *
     * @param  TThing  $thing
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many
     */
    protected function folderPath(Model $thing, ?array $paths = null): ?string
    {
        $folderId = $thing->getAttribute('folder_id');

        if ($folderId === null) {
            return null;
        }

        if (isset($paths[$folderId])) {
            return $paths[$folderId];
        }

        $folder = $thing->getRelationValue('folder');

        return $folder instanceof Folder
            ? implode('/', array_map(fn (Folder $step) => $step->name, $folder->ancestry()))
            : null;
    }

    /**
     * @param  TThing  $thing
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many
     * @return array<string, mixed>
     */
    protected function summary(Model $thing, ?array $paths = null): array
    {
        return [
            'ref_id' => $thing->getAttribute('ref_id'),
            'user_id' => $thing->getAttribute('user_id'),
            'title' => $thing->getAttribute('title'),
            'folder' => $this->folderPath($thing, $paths),
            ...$this->details($thing),
            'updated_at' => $thing->getAttribute('updated_at')?->toIso8601String(),
            'url' => route("{$this->plural()}.show", $thing),
        ];
    }
}
