<?php

namespace App\Support;

use App\Models\Folder;
use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * What every folder view needs, whatever the folders hold: finding the one
 * being browsed, listing what is in it, and the way back up.
 */
final class Folders
{
    /**
     * A folder ref_id, of this kind, that belongs to the owner.
     *
     * @param  class-string<Folder>  $model
     */
    public static function rule(string $model, Owner $owner): Exists
    {
        return Rule::exists((new $model)->getTable(), 'ref_id')->where($owner->ownerColumn(), $owner->getKey());
    }

    /**
     * The id of a folder given by ref_id, already validated as the owner's.
     *
     * @param  class-string<Folder>  $model
     */
    public static function idOf(string $model, ?string $refId): ?int
    {
        return $refId === null ? null : $model::query()->where('ref_id', $refId)->value('id');
    }

    /**
     * The folder being browsed, or null for the top level. One the owner
     * doesn't have is not found.
     *
     * @template TFolder of Folder
     *
     * @param  class-string<TFolder>  $model
     * @return TFolder|null
     */
    public static function open(string $model, Owner $owner, ?string $refId): ?Folder
    {
        if (empty($refId)) {
            return null;
        }

        $folder = $model::query()->where($owner->ownerColumn(), $owner->getKey())->where('ref_id', $refId)->firstOrFail();
        Gate::authorize('view', $folder);

        return $folder;
    }

    /**
     * Every one of the owner's folders of this kind, with just enough to list them.
     *
     * @template TFolder of Folder
     *
     * @param  class-string<TFolder>  $model
     * @return Collection<int, TFolder>
     */
    public static function all(string $model, Owner $owner): Collection
    {
        return $model::query()->where($owner->ownerColumn(), $owner->getKey())->get(['id', 'ref_id', 'parent_id', 'name']);
    }

    /**
     * The folders to show: those in the open folder, or with a search, those
     * whose names match it anywhere. Always by name. A filter about the things
     * inside ($hide) hides folders rather than guessing at them.
     *
     * @template TFolder of Folder
     *
     * @param  Collection<int, TFolder>  $all
     * @param  array<int, string>  $paths  from Folder::pathsById($all)
     * @return Collection<int, array{ref_id: string, name: string, path?: string}>
     */
    public static function listed(Collection $all, ?Folder $open, string $query, array $paths, bool $hide = false): Collection
    {
        $searching = $query !== '';

        return $all
            ->when(! $searching, fn ($folders) => $folders->where('parent_id', $open?->id))
            ->when($searching, fn ($folders) => $folders->filter(fn (Folder $folder) => mb_stripos($folder->name, $query) !== false))
            ->when($hide, fn ($folders) => $folders->take(0))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Folder $folder) => [
                'ref_id' => $folder->ref_id,
                'name' => $folder->name,
                // Search results come from every folder, so say where each one lives
                ...($searching ? ['path' => $paths[$folder->id]] : []),
            ])
            ->values();
    }

    /**
     * The folder at a path like "KT Plan/Lakeshore" among an owner's ("" is
     * the top level, returned as null). `false` when no such folder exists.
     *
     * @template TFolder of Model
     *
     * @param  HasMany<TFolder, covariant Model&Owner>  $folders
     * @return TFolder|false|null
     */
    public static function at(HasMany $folders, string $path): Model|false|null
    {
        $folder = null;

        foreach (self::pathNames($path) as $name) {
            $folder = self::child($folders, $folder, $name);

            if (! $folder) {
                return false;
            }
        }

        return $folder;
    }

    /**
     * The same, making every missing folder along the way, like `mkdir -p`,
     * as made by $by.
     *
     * @template TFolder of Model
     *
     * @param  HasMany<TFolder, covariant Model&Owner>  $folders
     * @return TFolder|null
     */
    public static function ensure(HasMany $folders, string $path, ?User $by): ?Model
    {
        $folder = null;

        foreach (self::pathNames($path) as $name) {
            $parent = $folder;
            $folder = self::child($folders, $parent, $name);

            if ($folder === null) {
                $folder = $folders->make(['name' => mb_substr($name, 0, 255), 'parent_id' => $parent?->getKey()]);
                $folder->setAttribute('created_by', $by?->id);
                $folder->save();
            }
        }

        return $folder;
    }

    /**
     * @template TFolder of Model
     *
     * @param  HasMany<TFolder, covariant Model&Owner>  $folders
     * @param  TFolder|null  $parent
     * @return TFolder|null
     */
    private static function child(HasMany $folders, ?Model $parent, string $name): ?Model
    {
        $siblings = fn () => (clone $folders)->getQuery()->where('parent_id', $parent?->getKey());

        return $siblings()->where('name', $name)->first()
            // A wrong capital shouldn't create a near-duplicate folder
            ?? $siblings()->whereLike('name', $name)->first();
    }

    /**
     * The folder names in a path, ignoring blanks from "a//b" or a trailing "/".
     *
     * @return list<string>
     */
    private static function pathNames(string $path): array
    {
        return array_values(array_filter(
            array_map('trim', explode('/', $path)),
            fn (string $name) => $name !== '',
        ));
    }

    /**
     * The path from the top level down to the folder, for breadcrumbs.
     *
     * @return list<array{ref_id: string, name: string}>
     */
    public static function crumbs(?Folder $folder): array
    {
        return array_map(
            fn (Folder $crumb) => ['ref_id' => $crumb->ref_id, 'name' => $crumb->name],
            $folder?->ancestry() ?? [],
        );
    }

    /**
     * An export of one of the owner's folders -- everything in it, its
     * folders too -- or of all they keep (`$everything` names that): what the
     * zip is called, the folder at its top, and each folder's path inside it
     * from there, null for one outside what is exported. A project's name
     * comes first, so what is unpacked says whose it is: "Team/Trip/Day 1",
     * in "Team - Trip.zip".
     *
     * @param  class-string<Folder>  $model
     * @param  callable(string): string  $fileName  a name as a disk takes it
     * @return array{name: string, top: string, pathOf: Closure(?int): ?string}
     */
    public static function exported(string $model, Owner $owner, ?string $refId, string $everything, callable $fileName): array
    {
        $folders = self::all($model, $owner)->keyBy('id');
        $open = filled($refId) ? $folders->firstWhere('ref_id', $refId) : null;
        $own = $open instanceof Folder ? $fileName($open->name) : null;
        $project = $owner instanceof Project ? $fileName($owner->name) : null;
        $top = implode('/', array_filter([$project, $own ?? ($project === null ? $everything : null)]));

        $pathOf = function (?int $id) use ($folders, $open, $top, $fileName): ?string {
            $names = [];

            for ($folder = $folders->get($id); $folder instanceof Folder; $folder = $folders->get($folder->parent_id)) {
                if ($open instanceof Folder && $folder->id === $open->id) {
                    return implode('/', [$top, ...$names]);
                }

                array_unshift($names, $fileName($folder->name));
            }

            // Outside the folder exported, or at the top when all are
            return $open instanceof Folder ? null : implode('/', [$top, ...$names]);
        };

        return [
            'name' => $project !== null ? $project.' - '.($own ?? $everything) : ($own ?? $everything),
            'top' => $top,
            'pathOf' => $pathOf,
        ];
    }
}
