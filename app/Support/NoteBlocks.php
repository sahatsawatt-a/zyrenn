<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A note as blocks: each paragraph, heading, list, list item, table and so on
 * carries an id (attrs.id), so one can be read or changed on its own -- over
 * MCP, say -- rather than sending the whole note both ways for one sentence.
 * Which kinds carry one is shared with the editor and the collaboration
 * server, in resources/js/lib/note-blocks.json.
 *
 * Ids, not positions: someone else writing above a block, live, doesn't
 * change which block an id names.
 */
final class NoteBlocks
{
    /** Kinds of list, and the kind of item each holds. */
    private const LISTS = ['bulletList' => 'listItem', 'orderedList' => 'listItem', 'taskList' => 'taskItem'];

    /**
     * @return array{attribute: string, idLength: int, types: list<string>}
     */
    private static function spec(): array
    {
        static $spec;

        return $spec ??= json_decode(
            (string) file_get_contents(resource_path('js/lib/note-blocks.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The document with an id on every block that should carry one. A block
     * without one, or with one used before it (a paragraph split in two), gets
     * a new one; the rest keep theirs.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array<string, mixed>|null
     */
    public static function withIds(?array $doc): ?array
    {
        if ($doc === null) {
            return null;
        }

        $seen = [];

        return self::giveIds($doc, $seen);
    }

    /**
     * The note's top-level blocks, each as its id, kind and first words.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<array<string, mixed>>
     */
    public static function outline(?array $doc): array
    {
        return array_map(
            fn (array $block) => array_filter([
                'id' => self::idOf($block),
                'type' => self::kindOf($block),
                'text' => Str::limit(self::text($block), 80),
                'items' => isset(self::LISTS[$block['type'] ?? '']) ? count($block['content'] ?? []) : null,
            ], fn ($value) => $value !== null && $value !== ''),
            self::blocks($doc),
        );
    }

    /**
     * Blocks as Markdown, each under its id. A list also gives each of its
     * items, so one bullet can be changed on its own.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function present(array $blocks): array
    {
        return array_map(function (array $block) {
            $shown = ['id' => self::idOf($block), 'type' => self::kindOf($block), 'markdown' => self::markdown($block)];

            if (isset(self::LISTS[$block['type'] ?? ''])) {
                $shown['items'] = array_map(
                    fn (array $item) => ['id' => self::idOf($item), 'markdown' => self::markdown($item)],
                    array_values(array_filter($block['content'] ?? [], 'is_array')),
                );
            }

            return $shown;
        }, $blocks);
    }

    /**
     * The blocks with these ids, at any depth, in the order asked for.
     *
     * @param  array<string, mixed>|null  $doc
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     *
     * @throws NoteBlockProblem when one isn't in the note
     */
    public static function find(?array $doc, array $ids): array
    {
        return array_map(function (string $id) use ($doc) {
            $path = self::pathTo($doc ?? [], $id) ?? throw NoteBlockProblem::missing($id);

            return self::nodeAt($doc ?? [], $path);
        }, $ids);
    }

    /**
     * A section: the heading with this text (any case) and every block after
     * it up to the next heading as big or bigger.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<array<string, mixed>>
     *
     * @throws NoteBlockProblem when there is no such heading
     */
    public static function section(?array $doc, string $heading): array
    {
        $blocks = self::blocks($doc);
        $wanted = mb_strtolower(trim($heading));

        foreach ($blocks as $at => $block) {
            if (($block['type'] ?? '') !== 'heading' || mb_strtolower(trim(self::text($block))) !== $wanted) {
                continue;
            }

            $level = (int) ($block['attrs']['level'] ?? 1);
            $section = [$block];

            foreach (array_slice($blocks, $at + 1) as $next) {
                if (($next['type'] ?? '') === 'heading' && (int) ($next['attrs']['level'] ?? 1) <= $level) {
                    break;
                }

                $section[] = $next;
            }

            return $section;
        }

        throw new NoteBlockProblem("There is no heading \"{$heading}\" in this note. get-note without a section lists its blocks.");
    }

    /**
     * Makes changes, in order, by block id: replace, insert_before,
     * insert_after, append, prepend, delete and move. Markdown given for a list
     * item becomes list items.
     *
     * Answers with the document as it now is, the same changes put for the
     * collaboration server to make in a live copy (each naming blocks by id,
     * with the finished blocks), and the ids of the blocks made or changed.
     *
     * @param  array<string, mixed>|null  $doc
     * @param  list<array<string, mixed>>  $operations
     * @return array{doc: array<string, mixed>, edits: list<array<string, mixed>>, changed: list<string>}
     *
     * @throws NoteBlockProblem when a change can't be made; then none are
     */
    public static function apply(?array $doc, array $operations): array
    {
        $doc = self::withIds($doc ?? ['type' => 'doc', 'content' => []]) ?? [];
        $doc['content'] ??= [];
        $edits = [];
        $changed = [];

        foreach ($operations as $number => $operation) {
            $op = (string) ($operation['op'] ?? '');
            $block = $operation['block'] ?? null;
            $problem = fn (string $why) => new NoteBlockProblem('Change '.($number + 1)." ({$op}): {$why}");

            switch ($op) {
                case 'replace':
                    $path = self::pathOf($doc, $block, $problem);
                    $old = self::nodeAt($doc, $path);
                    $nodes = self::newBlocks($doc, (string) ($operation['markdown'] ?? ''), $old['type'] ?? null, keep: self::idOf($old));
                    self::splice($doc, $path, 1, $nodes);
                    $edits[] = ['do' => 'replace', 'id' => $block, 'nodes' => $nodes];
                    array_push($changed, ...array_map(self::idOf(...), $nodes));
                    break;

                case 'insert_before':
                case 'insert_after':
                    $path = self::pathOf($doc, $block, $problem);
                    $nodes = self::newBlocks($doc, (string) ($operation['markdown'] ?? ''), self::nodeAt($doc, $path)['type'] ?? null);
                    $side = $op === 'insert_after' ? 'after' : 'before';
                    self::splice($doc, self::beside($path, $side), 0, $nodes);
                    $edits[] = ['do' => 'insert', $side => $block, 'nodes' => $nodes];
                    array_push($changed, ...array_map(self::idOf(...), $nodes));
                    break;

                case 'append':
                case 'prepend':
                    $nodes = self::newBlocks($doc, (string) ($operation['markdown'] ?? ''));
                    self::splice($doc, [$op === 'append' ? count($doc['content']) : 0], 0, $nodes);
                    $edits[] = ['do' => 'insert', 'at' => $op === 'append' ? 'end' : 'start', 'nodes' => $nodes];
                    array_push($changed, ...array_map(self::idOf(...), $nodes));
                    break;

                case 'delete':
                    $path = self::pathOf($doc, $block, $problem);
                    self::splice($doc, $path, 1, []);
                    $edits[] = ['do' => 'delete', 'id' => $block];
                    break;

                case 'move':
                    $path = self::pathOf($doc, $block, $problem);
                    $node = self::nodeAt($doc, $path);
                    $side = isset($operation['after']) ? 'after' : 'before';
                    $target = $operation[$side] ?? throw $problem('say where it goes: "after" or "before" another block.');

                    if ($target === $block) {
                        throw $problem('a block can\'t go beside itself.');
                    }

                    self::splice($doc, $path, 1, []);
                    $to = self::pathOf($doc, $target, $problem);

                    if (self::isItem($node) !== self::isItem(self::nodeAt($doc, $to))) {
                        throw $problem('a list item moves among list items, and a block among blocks.');
                    }

                    self::splice($doc, self::beside($to, $side), 0, [$node]);
                    $edits[] = ['do' => 'delete', 'id' => $block];
                    $edits[] = ['do' => 'insert', $side => $target, 'nodes' => [$node]];
                    $changed[] = $block;
                    break;

                default:
                    throw $problem('there is no such change. Use replace, insert_before, insert_after, append, prepend, delete or move.');
            }
        }

        return ['doc' => $doc, 'edits' => $edits, 'changed' => array_values(array_unique($changed))];
    }

    /**
     * A block as Markdown. A list item on its own is written as a one-item list.
     *
     * @param  array<string, mixed>  $block
     */
    public static function markdown(array $block): string
    {
        $alone = match ($block['type'] ?? '') {
            'listItem' => ['type' => 'bulletList', 'content' => [$block]],
            'taskItem' => ['type' => 'taskList', 'content' => [$block]],
            default => $block,
        };

        return rtrim(TiptapMarkdown::toMarkdown(['type' => 'doc', 'content' => [$alone]]), "\n");
    }

    /**
     * @param  array<string, mixed>|null  $doc
     * @return list<array<string, mixed>>
     */
    private static function blocks(?array $doc): array
    {
        return array_values(array_filter($doc['content'] ?? [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function idOf(array $node): ?string
    {
        $id = $node['attrs'][self::spec()['attribute']] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * What kind of block it is, as a reader would name it.
     *
     * @param  array<string, mixed>  $block
     */
    private static function kindOf(array $block): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');

        return match (true) {
            $type === 'heading' => 'heading '.($block['attrs']['level'] ?? 1),
            // A diagram or a board shown in the note is a code block underneath
            $type === 'codeBlock' && in_array($block['attrs']['language'] ?? '', ['mermaid', 'board'], true) => $block['attrs']['language'],
            default => $type,
        };
    }

    /**
     * What a block says, as plain words.
     *
     * @param  array<string, mixed>  $node
     */
    private static function text(array $node): string
    {
        $type = $node['type'] ?? '';

        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }

        if ($type === 'image') {
            return (string) ($node['attrs']['alt'] ?? '');
        }

        if (in_array($type, ['blockMath', 'inlineMath'], true)) {
            return (string) ($node['attrs']['latex'] ?? '');
        }

        $parts = array_map(fn ($child) => is_array($child) ? self::text($child) : '', $node['content'] ?? []);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private static function giveIds(array $node, array &$seen): array
    {
        $spec = self::spec();

        if (in_array($node['type'] ?? null, $spec['types'], true)) {
            $id = $node['attrs'][$spec['attribute']] ?? null;

            if (! is_string($id) || $id === '' || isset($seen[$id])) {
                do {
                    $id = Str::lower(Str::random($spec['idLength']));
                } while (isset($seen[$id]));

                $node['attrs'][$spec['attribute']] = $id;
            }

            $seen[$id] = true;
        }

        foreach ($node['content'] ?? [] as $at => $child) {
            if (is_array($child)) {
                $node['content'][$at] = self::giveIds($child, $seen);
            }
        }

        return $node;
    }

    /**
     * New blocks from Markdown, with ids none of the note's use. In place of a
     * list item they are list items. The first can keep an id -- a replaced
     * block's, so what named it still does.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function newBlocks(array $doc, string $markdown, ?string $like = null, ?string $keep = null): array
    {
        $blocks = self::blocks(TiptapMarkdown::toDoc($markdown));

        if (in_array($like, ['listItem', 'taskItem'], true)) {
            $blocks = self::asItems($blocks, $like);
        }

        if ($blocks === []) {
            return [];
        }

        $seen = [];
        self::giveIds($doc, $seen);

        if ($keep !== null) {
            unset($seen[$keep]);
            $blocks[0]['attrs'][self::spec()['attribute']] = $keep;
        }

        $made = self::giveIds(['type' => 'doc', 'content' => $blocks], $seen);

        return $made['content'];
    }

    /**
     * Blocks as items of a list: a list's own items, each of the kind asked
     * for; anything else becomes an item holding it.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function asItems(array $blocks, string $kind): array
    {
        $items = [];

        foreach ($blocks as $block) {
            $inside = isset(self::LISTS[$block['type'] ?? '']) ? ($block['content'] ?? []) : [['content' => [$block]]];

            foreach ($inside as $item) {
                $items[] = array_filter([
                    'type' => $kind,
                    'attrs' => $kind === 'taskItem' ? ['checked' => (bool) ($item['attrs']['checked'] ?? false)] : null,
                    'content' => $item['content'] ?? [],
                ], fn ($value) => $value !== null);
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function isItem(array $node): bool
    {
        return in_array($node['type'] ?? '', ['listItem', 'taskItem'], true);
    }

    /**
     * Where the block with this id is: its index at each depth.
     *
     * @param  array<string, mixed>  $node
     * @return list<int>|null
     */
    private static function pathTo(array $node, string $id): ?array
    {
        foreach ($node['content'] ?? [] as $at => $child) {
            if (! is_array($child)) {
                continue;
            }

            if (self::idOf($child) === $id) {
                return [$at];
            }

            $below = self::pathTo($child, $id);

            if ($below !== null) {
                return [$at, ...$below];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  callable(string): NoteBlockProblem  $problem
     * @return list<int>
     */
    private static function pathOf(array $doc, mixed $id, callable $problem): array
    {
        if (! is_string($id) || $id === '') {
            throw $problem('name the block by its id ("block").');
        }

        return self::pathTo($doc, $id) ?? throw $problem("there is no block \"{$id}\". get-note lists the note's blocks and their ids.");
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<int>  $path
     * @return array<string, mixed>
     */
    private static function nodeAt(array $doc, array $path): array
    {
        $node = $doc;

        foreach ($path as $at) {
            $node = $node['content'][$at];
        }

        return $node;
    }

    /**
     * The place just before or after the node at a path.
     *
     * @param  list<int>  $path
     * @return list<int>
     */
    private static function beside(array $path, string $side): array
    {
        $last = array_pop($path);

        return [...$path, $side === 'after' ? $last + 1 : $last];
    }

    /**
     * Takes out $remove nodes where the path points, and puts $nodes there.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<int>  $path
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function splice(array &$doc, array $path, int $remove, array $nodes): void
    {
        $at = array_pop($path);
        $parent = &$doc;

        foreach ($path as $step) {
            $parent = &$parent['content'][$step];
        }

        $content = array_values($parent['content'] ?? []);
        array_splice($content, $at, $remove, $nodes);
        $parent['content'] = $content;
    }
}
