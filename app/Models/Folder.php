<?php

namespace App\Models;

use App\Models\Concerns\HasRefId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A folder of one kind of thing -- notes, boards, tables, Drive files -- owned
 * by one user and nested under another folder of its own kind through
 * `parent_id`. Each kind keeps its folders in a table of its own; everything
 * else about a folder is the same, and lives here.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
abstract class Folder extends Model
{
    use HasRefId;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'parent_id'];

    /**
     * What this kind of folder holds.
     *
     * @return class-string<Model>
     */
    abstract protected function itemModel(): string;

    /**
     * Get the user that owns the folder.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Delete the folder with everything inside it, at any depth.
     *
     * Things go one by one rather than in a single query, so each one's own
     * deleting hook runs -- a table takes its rows with it, a file its bytes.
     */
    public function deleteTree(): void
    {
        $this->itemModel()::query()
            ->whereIn('folder_id', $this->subtreeIds())
            ->each(fn (Model $item) => $item->delete());

        // Subfolders go with it through the parent_id cascade
        $this->delete();
    }

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
