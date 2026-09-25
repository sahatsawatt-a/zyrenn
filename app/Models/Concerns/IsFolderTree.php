<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder that nests under another folder of its own kind through
 * `parent_id`, like Drive folders and note folders.
 */
trait IsFolderTree
{
    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * This folder and every folder above it, from the root down.
     *
     * @return list<static>
     */
    public function ancestry(): array
    {
        $chain = [];

        for ($folder = $this; $folder; $folder = $folder->parent) {
            array_unshift($chain, $folder);
        }

        return $chain;
    }

    /**
     * Ids of this folder and all its descendants.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = static::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            array_push($ids, ...$frontier);
        }

        return $ids;
    }

    /**
     * Every folder's full path, e.g. "Work / Diagrams", keyed by folder id.
     *
     * @param  iterable<static>  $folders  all of one owner's folders
     * @return array<int, string>
     */
    public static function pathsById(iterable $folders, string $separator = ' / '): array
    {
        $byId = [];
        foreach ($folders as $folder) {
            $byId[$folder->id] = $folder;
        }

        $paths = [];
        foreach ($byId as $id => $folder) {
            $names = [];
            for ($node = $folder; $node; $node = $byId[$node->parent_id] ?? null) {
                array_unshift($names, $node->name);
            }

            $paths[$id] = implode($separator, $names);
        }

        return $paths;
    }

    /**
     * Every folder with its full path, sorted by path. For a move dialog.
     *
     * @param  iterable<static>  $folders  all of one owner's folders
     * @return list<array{ref_id: string, path: string}>
     */
    public static function paths(iterable $folders): array
    {
        $refs = [];
        foreach ($folders as $folder) {
            $refs[$folder->id] = $folder->ref_id;
        }

        $paths = [];
        foreach (static::pathsById($folders) as $id => $path) {
            $paths[] = ['ref_id' => $refs[$id], 'path' => $path];
        }

        usort($paths, fn (array $a, array $b) => strnatcasecmp($a['path'], $b['path']));

        return $paths;
    }
}
