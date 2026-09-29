<?php

namespace App\Support;

use App\Models\Folder;
use App\Models\User;
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
     * A folder ref_id, of this kind, that belongs to the user.
     *
     * @param  class-string<Folder>  $model
     */
    public static function rule(string $model, User $user): Exists
    {
        return Rule::exists((new $model)->getTable(), 'ref_id')->where('user_id', $user->id);
    }

    /**
     * The id of a folder given by ref_id, already validated as the user's own.
     *
     * @param  class-string<Folder>  $model
     */
    public static function idOf(string $model, ?string $refId): ?int
    {
        return $refId === null ? null : $model::query()->where('ref_id', $refId)->value('id');
    }

    /**
     * The folder being browsed, or null for the top level. Someone else's is
     * refused; one that doesn't exist is not found.
     *
     * @template TFolder of Folder
     *
     * @param  class-string<TFolder>  $model
     * @return TFolder|null
     */
    public static function open(string $model, User $user, ?string $refId): ?Folder
    {
        if (empty($refId)) {
            return null;
        }

        $folder = $model::query()->where('user_id', $user->id)->where('ref_id', $refId)->firstOrFail();
        Gate::authorize('view', $folder);

        return $folder;
    }

    /**
     * Every one of the user's folders of this kind, with just enough to list them.
     *
     * @template TFolder of Folder
     *
     * @param  class-string<TFolder>  $model
     * @return Collection<int, TFolder>
     */
    public static function all(string $model, User $user): Collection
    {
        return $model::query()->where('user_id', $user->id)->get(['id', 'ref_id', 'parent_id', 'name']);
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
}
