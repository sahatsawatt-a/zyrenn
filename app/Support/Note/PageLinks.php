<?php

namespace App\Support\Note;

use App\Models\Board\Board;
use App\Models\Map\Trip;
use App\Models\Note\Note;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Links from a note to another page, by the page's name: [[Budget]] in
 * Markdown, a chip in the editor (features/notes/nodes/PageLinkNode.ts).
 *
 * A link is kept by the page's ref_id, so it goes on pointing at the page
 * when the page is renamed, and it is written by title, so nobody has to
 * look an address up: the title is found among the pages of whoever owns the
 * note. Every link a note holds -- these, and ordinary links to a page's
 * address -- is also kept in note_links, which is how a page knows what
 * links to it.
 */
class PageLinks
{
    /** The inline node a link is in a note's document. */
    public const NODE = 'pageLink';

    /**
     * What can be linked to, in the order a bare title is looked for: each
     * kind's model, the owner's relation that holds them, and its route.
     *
     * @var array<string, array{class-string<Model>, string, string}>
     */
    public const KINDS = [
        'note' => [Note::class, 'notes', 'notes.show'],
        'board' => [Board::class, 'boards', 'boards.show'],
        'table' => [Table::class, 'tables', 'tables.show'],
        'trip' => [Trip::class, 'trips', 'trips.show'],
    ];

    /** The first part of each kind's address: /notes/…, /boards/… */
    private const PATHS = ['notes' => 'note', 'boards' => 'board', 'tables' => 'table', 'trips' => 'trip'];

    /**
     * Every page a document links to, once each: by a page link, or by an
     * ordinary link to a page's address. A note is not said to link to itself.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<array{kind: string, ref: string}>
     */
    public static function found(?array $doc, ?string $self = null): array
    {
        $links = [];

        self::walk($doc ?? [], function (array $node) use (&$links, $self) {
            $found = null;

            if (($node['type'] ?? '') === self::NODE) {
                $found = [(string) ($node['attrs']['kind'] ?? 'note'), (string) ($node['attrs']['ref'] ?? '')];
            }

            foreach ($node['marks'] ?? [] as $mark) {
                if (($mark['type'] ?? '') === 'link' && preg_match('#(?:^|//[^/]+)/(notes|boards|tables|trips)/([A-Za-z0-9]+)(?:[/?\#]|$)#', (string) ($mark['attrs']['href'] ?? ''), $address)) {
                    $found = [self::PATHS[$address[1]], $address[2]];
                }
            }

            if ($found !== null && $found[1] !== '' && isset(self::KINDS[$found[0]]) && ! ($found[0] === 'note' && $found[1] === $self)) {
                $links[$found[0].':'.$found[1]] = ['kind' => $found[0], 'ref' => $found[1]];
            }

            return $node;
        });

        return array_values($links);
    }

    /**
     * Keeps note_links in step with what the note now links to.
     */
    public static function sync(Note $note): void
    {
        $links = self::found($note->content, $note->ref_id);

        DB::table('note_links')->where('note_id', $note->id)->delete();

        if ($links !== []) {
            DB::table('note_links')->insert(array_map(fn (array $link) => ['note_id' => $note->id, ...$link], $links));
        }
    }

    /**
     * A document -- or any part of one -- with each link that only names a
     * page pointed at that page, found among the owner's. Answers the names
     * nothing was found for.
     *
     * @template TTree of array<array-key, mixed>
     *
     * @param  TTree  $tree
     * @return array{0: TTree, 1: list<string>}
     */
    public static function resolve(array $tree, Owner $owner): array
    {
        $missing = [];

        /** @var TTree $tree */
        $tree = self::walk($tree, function (array $node) use ($owner, &$missing) {
            if (($node['type'] ?? '') !== self::NODE || ($node['attrs']['ref'] ?? '') !== '') {
                return $node;
            }

            $wanted = (string) ($node['attrs']['title'] ?? '');
            $page = self::find($owner, $wanted, $node['attrs']['kind'] ?? null);

            if ($page === null) {
                $missing[] = $wanted;

                return $node;
            }

            $node['attrs'] = [
                ...$node['attrs'],
                'kind' => self::kindOf($page),
                'ref' => $page->getAttribute('ref_id'),
                'title' => (string) $page->getAttribute('title'),
            ];

            // Shown as the page is called: no words of its own to keep
            if (mb_strtolower((string) ($node['attrs']['text'] ?? '')) === mb_strtolower((string) $page->getAttribute('title'))) {
                $node['attrs']['text'] = '';
            }

            return $node;
        });

        return [$tree, array_values(array_unique($missing))];
    }

    /**
     * The names in a document that no page was found for.
     *
     * @param  array<array-key, mixed>|null  $tree
     * @return list<string>
     */
    public static function missing(?array $tree): array
    {
        $names = [];

        self::walk($tree ?? [], function (array $node) use (&$names) {
            if (($node['type'] ?? '') === self::NODE && ($node['attrs']['ref'] ?? '') === '') {
                $names[] = (string) ($node['attrs']['title'] ?? '');
            }

            return $node;
        });

        return array_values(array_unique($names));
    }

    /**
     * What to tell a client that wrote links nothing was found for.
     *
     * @param  array<array-key, mixed>|null  $tree
     * @return array{unlinked?: list<string>, unlinked_note?: string}
     */
    public static function report(?array $tree): array
    {
        $names = self::missing($tree);

        return $names === [] ? [] : [
            'unlinked' => $names,
            'unlinked_note' => 'No page of the same owner has these titles, so each [[link]] is kept as written and points nowhere yet. Check the title (list-notes, list-boards, list-tables, list-trips), or make the page and save the note again.',
        ];
    }

    /**
     * A document with each link named as its page is called now, for writing
     * it out as Markdown. Where the owner has two pages of that name the link
     * is written by ref_id instead ("target"), so reading it back finds the
     * same page.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array<string, mixed>|null
     */
    public static function named(?array $doc, Owner $owner): ?array
    {
        if ($doc === null) {
            return null;
        }

        return self::walk($doc, function (array $node) use ($owner) {
            if (($node['type'] ?? '') !== self::NODE || ($node['attrs']['ref'] ?? '') === '') {
                return $node;
            }

            $kind = (string) ($node['attrs']['kind'] ?? 'note');
            $page = isset(self::KINDS[$kind]) ? self::among($owner, $kind)->where('ref_id', $node['attrs']['ref'])->first() : null;

            if ($page === null) {
                return $node;
            }

            $title = (string) $page->getAttribute('title');
            $node['attrs']['title'] = $title;

            if ($title === '' || self::titled($owner, $kind, $title)->count() > 1) {
                $node['attrs']['target'] = $page->getAttribute('ref_id');
            }

            return $node;
        });
    }

    /**
     * The owner's page of this name: by ref_id, or by its title whatever the
     * case -- of the kind asked for, or the first kind that has one. Of two
     * with the same title, the one changed last.
     */
    public static function find(Owner $owner, string $named, ?string $kind = null): ?Model
    {
        $named = trim($named);

        if ($named === '') {
            return null;
        }

        foreach (isset(self::KINDS[$kind ?? '']) ? [$kind] : array_keys(self::KINDS) as $each) {
            $page = self::among($owner, $each)->where('ref_id', $named)->first()
                ?? self::titled($owner, $each, $named)->latest('updated_at')->first();

            if ($page !== null) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The owner's pages a few letters could mean, for choosing one to link
     * to: those whose title starts with them first, then the ones changed
     * last.
     *
     * @return list<array{kind: string, ref: string, title: string, folder: string|null}>
     */
    public static function search(Owner $owner, string $letters, ?Note $except = null, int $limit = 12): array
    {
        $letters = trim($letters);
        $pages = [];

        foreach (array_keys(self::KINDS) as $kind) {
            $found = self::among($owner, $kind)
                ->with('folder')
                ->when($letters !== '', fn ($query) => $query->whereLike('title', '%'.$letters.'%'))
                ->when($kind === 'note' && $except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
                ->where('title', '!=', '')
                ->latest('updated_at')
                ->limit($limit)
                ->get();

            foreach ($found as $page) {
                $pages[] = [
                    'kind' => $kind,
                    'ref' => (string) $page->getAttribute('ref_id'),
                    'title' => (string) $page->getAttribute('title'),
                    'folder' => $page->getRelation('folder')?->getAttribute('name'),
                    'at' => (string) $page->getAttribute('updated_at'),
                ];
            }
        }

        $starts = fn (array $page) => $letters !== '' && str_starts_with(mb_strtolower($page['title']), mb_strtolower($letters)) ? 0 : 1;

        usort($pages, fn (array $a, array $b) => [$starts($a), $b['at']] <=> [$starts($b), $a['at']]);

        return array_map(
            fn (array $page) => ['kind' => $page['kind'], 'ref' => $page['ref'], 'title' => $page['title'], 'folder' => $page['folder']],
            array_slice($pages, 0, $limit),
        );
    }

    /**
     * What each of a note's links points at now, for showing them: the
     * page's title and address, when there is such a page and the reader may
     * see it. A link that only names a page is looked for among the pages of
     * the note's owner.
     *
     * @param  list<array{kind?: string|null, ref?: string|null, title?: string|null}>  $links
     * @return list<array{kind: string, ref: string, title: string, url: string|null, found: bool}>
     */
    public static function describe(array $links, Note $from, User $reader): array
    {
        return array_map(function (array $link) use ($from, $reader) {
            $kind = isset(self::KINDS[$link['kind'] ?? '']) ? (string) $link['kind'] : 'note';
            $ref = (string) ($link['ref'] ?? '');
            $wanted = (string) ($link['title'] ?? '');

            $page = $ref !== ''
                ? self::KINDS[$kind][0]::query()->where('ref_id', $ref)->first()
                : self::find($from->owner(), $wanted, $link['kind'] ?? null);

            if ($page === null || $reader->cannot('view', $page)) {
                return ['kind' => $kind, 'ref' => $ref, 'title' => $wanted, 'url' => null, 'found' => false];
            }

            return [
                'kind' => self::kindOf($page),
                'ref' => (string) $page->getAttribute('ref_id'),
                'title' => (string) $page->getAttribute('title'),
                'url' => self::url($page),
                'found' => true,
            ];
        }, $links);
    }

    /**
     * The notes that link to a page, among those the reader may see.
     *
     * @return list<array{ref_id: string, title: string, url: string, folder: string|null}>
     */
    public static function linkedFrom(Model $page, User $reader): array
    {
        $ids = DB::table('note_links')
            ->where('kind', self::kindOf($page))
            ->where('ref', $page->getAttribute('ref_id'))
            ->pluck('note_id');

        return array_values(Note::query()
            ->whereKey($ids)
            ->with('folder')
            ->orderBy('title')
            ->get()
            ->filter(fn (Note $note) => $reader->can('view', $note))
            ->map(fn (Note $note) => [
                'ref_id' => $note->ref_id,
                'title' => $note->title,
                'url' => self::url($note),
                'folder' => $note->folder?->name,
            ])
            ->all());
    }

    /** A page's address in the app, from its root. */
    public static function url(Model $page): string
    {
        return route(self::KINDS[self::kindOf($page)][2], $page, absolute: false);
    }

    public static function kindOf(Model $page): string
    {
        foreach (self::KINDS as $kind => [$class]) {
            if ($page instanceof $class) {
                return $kind;
            }
        }

        return 'note';
    }

    /**
     * The owner's pages of a kind.
     *
     * @return Builder<Model>
     */
    private static function among(Owner $owner, string $kind): Builder
    {
        /** @var Builder<Model> $pages */
        $pages = $owner->{self::KINDS[$kind][1]}()->getQuery();

        return $pages;
    }

    /**
     * Those of them with this title, whatever its case.
     *
     * @return Builder<Model>
     */
    private static function titled(Owner $owner, string $kind, string $title): Builder
    {
        return self::among($owner, $kind)->whereRaw('lower(title) = ?', [mb_strtolower($title)]);
    }

    /**
     * A tree of nodes with `change` made to each, children first.
     *
     * @param  array<array-key, mixed>  $tree
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<array-key, mixed>
     */
    private static function walk(array $tree, callable $change): array
    {
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = self::walk($value, $change);
            }
        }

        return isset($tree['type']) && is_string($tree['type']) ? $change($tree) : $tree;
    }
}
