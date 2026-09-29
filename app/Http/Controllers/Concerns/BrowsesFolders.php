<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Folder;
use App\Models\Owner;
use App\Support\Folders;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A list of titled things kept in folders -- notes, boards, tables: browsing a
 * folder, searching all of them, sorting, and "edited lately". The controller
 * says which folders it uses; its index says what to show of each thing.
 */
trait BrowsesFolders
{
    use ActsForOwner;

    /**
     * How a list can be sorted: key => [column, direction].
     *
     * @var array<string, array{string, 'asc'|'desc'}>
     */
    private static array $sorts = [
        'edited' => ['updated_at', 'desc'],
        'created' => ['created_at', 'desc'],
        'title' => ['title', 'asc'],
    ];

    /**
     * How far back the "edited" filter reaches, in days (0 = since midnight).
     *
     * @var array<string, int>
     */
    private static array $edited = ['today' => 0, 'week' => 7, 'month' => 30];

    /**
     * The kind of folder these things are kept in.
     *
     * @return class-string<Folder>
     */
    abstract protected static function folderModel(): string;

    /**
     * Browse a folder of an owner's things (the top level when none is given),
     * or search every folder when there is a query.
     *
     * @template TItem of Model
     *
     * @param  HasMany<TItem, covariant Model&Owner>  $items  the owner's things, with whatever they need loaded
     * @param  list<string>  $searchIn  the columns a search looks in
     * @param  Closure(TItem, string): array<string, mixed>  $describe  what the list shows of a thing beyond its
     *                                                                  title and dates, given the search ('' when none)
     */
    protected function browse(Request $request, string $page, string $key, HasMany $items, array $searchIn, Closure $describe): Response
    {
        $owner = $items->getParent();

        $filters = $request->validate([
            'folder' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(array_keys(self::$sorts))],
            'edited' => ['nullable', Rule::in(array_keys(self::$edited))],
        ]);

        $folder = Folders::open(static::folderModel(), $owner, $filters['folder'] ?? null);
        $query = trim($filters['q'] ?? '');
        $searching = $query !== '';
        $sort = $filters['sort'] ?? 'edited';
        $edited = $filters['edited'] ?? null;
        [$column, $direction] = self::$sorts[$sort];

        $allFolders = Folders::all(static::folderModel(), $owner);
        $paths = static::folderModel()::pathsById($allFolders);

        if ($searching) {
            $items->where(function (Builder $match) use ($searchIn, $query) {
                foreach ($searchIn as $searchable) {
                    $match->orWhereLike($searchable, "%{$query}%");
                }
            });
        } else {
            $items->where('folder_id', $folder?->id);
        }

        if ($edited !== null) {
            $days = self::$edited[$edited];
            $items->where('updated_at', '>=', $days === 0 ? now()->startOfDay() : now()->subDays($days));
        }

        $found = $items->orderBy($column, $direction)->orderByDesc('id')->get()
            ->map(fn ($thing) => [
                'ref_id' => $thing->getAttribute('ref_id'),
                'title' => $thing->getAttribute('title'),
                ...$describe($thing, $query),
                'updated_at' => $thing->getAttribute('updated_at'),
                'created_at' => $thing->getAttribute('created_at'),
                // Search results come from every folder, so say where each one lives
                ...($searching ? ['path' => $paths[$thing->getAttribute('folder_id')] ?? null] : []),
            ]);

        return Inertia::render($page, [
            'folder' => $folder?->only(['ref_id', 'name']),
            'breadcrumbs' => Folders::crumbs($folder),
            'folders' => Folders::listed($allFolders, $folder, $query, $paths, hide: $edited !== null),
            $key => $found,
            'filters' => ['q' => $query, 'sort' => $sort, 'edited' => $edited],
            'allFolders' => fn () => static::folderModel()::paths($allFolders),
        ]);
    }

    /**
     * A folder ref_id that belongs to the owner.
     */
    protected static function ownFolder(Owner $owner): Exists
    {
        return Folders::rule(static::folderModel(), $owner);
    }

    /**
     * The id of a folder given by ref_id, already validated as the owner's.
     */
    protected static function folderId(?string $refId): ?int
    {
        return Folders::idOf(static::folderModel(), $refId);
    }

    /**
     * The path from the top level down to the folder, for breadcrumbs.
     *
     * @return list<array{ref_id: string, name: string}>
     */
    protected static function crumbs(?Folder $folder): array
    {
        return Folders::crumbs($folder);
    }
}
