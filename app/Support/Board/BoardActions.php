<?php

namespace App\Support\Board;

/**
 * What a prototype's items and screens do in Play, as kept on them: a tap's
 * action (`link`), other triggers' (`when`), and a screen's own after so many
 * seconds (`screen.after`). Each is an action that may name screens (frames)
 * and items; read here as the canvas reads them
 * (resources/js/features/boards/model/links.ts, the shapes in
 * features/prototypes/interactions.ts).
 */
final class BoardActions
{
    public const TRANSITIONS = ['slide', 'push', 'fade', 'up', 'none'];

    public const OVERLAY_PLACES = ['center', 'bottom', 'top', 'left', 'right'];

    public const TRIGGERS = ['long-press', 'hover', 'swipe-left', 'swipe-right', 'swipe-up', 'swipe-down'];

    /** The kinds of thing an action can do, as a client names them. */
    public const KINDS = ['to', 'back', 'close', 'toggle', 'show', 'hide', 'set', 'if', 'all', 'save', 'remove'];

    /** A repeat's rows at the least, the most, and when it does not say. */
    public const ROWS = [1, 200, 50];

    /** The widest gap between a repeat's rows, and the most filters. */
    private const MOST_GAP = 400.0;

    private const MOST_WHERE = 10;

    /** How deep an `if` or `all` may nest. */
    private const DEEPEST = 6;

    /** The most steps an `all` holds, and items a show, hide or toggle names. */
    private const MOST = 20;

    /** A screen's wait, in seconds, at the least and the most. */
    public const SECONDS = [0.5, 30.0];

    /**
     * An action as it should be, from whatever was sent or kept: anything
     * unsound in it left out, and null when nothing sound is left.
     *
     * @return array<string, mixed>|null
     */
    public static function clean(mixed $action, int $depth = 0): ?array
    {
        if (! is_array($action) || $depth > self::DEEPEST) {
            return null;
        }

        $transition = in_array($action['transition'] ?? null, self::TRANSITIONS, true) ? ['transition' => $action['transition']] : [];

        if (self::named($action['to'] ?? null) !== null) {
            return [
                'to' => self::named($action['to']),
                ...$transition,
                ...(in_array($action['overlay'] ?? null, self::OVERLAY_PLACES, true) ? ['overlay' => $action['overlay']] : []),
            ];
        }

        if (($action['back'] ?? null) === true) {
            return ['back' => true, ...$transition];
        }

        if (($action['close'] ?? null) === true) {
            return ['close' => true];
        }

        foreach (['toggle', 'show', 'hide'] as $kind) {
            $ids = self::ids($action[$kind] ?? null);

            if ($ids !== null) {
                return [$kind => $ids];
            }
        }

        $value = $action['value'] ?? null;

        if (self::named($action['set'] ?? null) !== null && (is_string($value) || is_bool($value))) {
            return ['set' => self::named($action['set']), 'value' => $value];
        }

        // A project table as the prototype's database: a row saved -- new,
        // or the current one changed -- or the current row deleted
        if (self::named($action['save'] ?? null) !== null) {
            $values = [];

            foreach (is_array($action['values'] ?? null) ? $action['values'] : [] as $column => $words) {
                if (self::named((string) $column) !== null && is_string($words) && count($values) < self::MOST) {
                    $values[self::named((string) $column)] = $words;
                }
            }

            return [
                'save' => self::named($action['save']),
                ...(($action['row'] ?? null) === 'current' ? ['row' => 'current'] : []),
                ...($values !== [] ? ['values' => $values] : []),
            ];
        }

        if (self::named($action['remove'] ?? null) !== null) {
            return ['remove' => self::named($action['remove'])];
        }

        if (self::named($action['if'] ?? null) !== null) {
            $then = self::clean($action['then'] ?? null, $depth + 1);
            $else = self::clean($action['else'] ?? null, $depth + 1);
            $equals = $action['equals'] ?? null;

            return $then === null ? null : [
                'if' => self::named($action['if']),
                ...(is_string($equals) || is_bool($equals) ? ['equals' => $equals] : []),
                'then' => $then,
                ...($else !== null ? ['else' => $else] : []),
            ];
        }

        if (is_array($action['all'] ?? null)) {
            $steps = array_values(array_filter(array_map(
                fn (mixed $step) => self::clean($step, $depth + 1),
                array_slice(array_values($action['all']), 0, self::MOST),
            )));

            return match (count($steps)) {
                0 => null,
                1 => $steps[0],
                default => ['all' => $steps],
            };
        }

        return null;
    }

    /**
     * The other triggers' actions, each sound, by trigger.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function when(mixed $when): array
    {
        $kept = [];

        foreach (self::TRIGGERS as $trigger) {
            $action = is_array($when) ? self::clean($when[$trigger] ?? null) : null;

            if ($action !== null) {
                $kept[$trigger] = $action;
            }
        }

        return $kept;
    }

    /**
     * A screen's own settings: what it does after so many seconds.
     *
     * @return array{after?: array{seconds: float, do: array<string, mixed>}}
     */
    public static function screen(mixed $screen): array
    {
        $after = is_array($screen) ? ($screen['after'] ?? null) : null;
        $action = is_array($after) ? self::clean($after['do'] ?? null) : null;

        if ($action === null || ! is_numeric($after['seconds'] ?? null)) {
            return [];
        }

        return ['after' => [
            'seconds' => round(min(self::SECONDS[1], max(self::SECONDS[0], (float) $after['seconds'])), 1),
            'do' => $action,
        ]];
    }

    /**
     * A row card's repeat as it should be: a table named, and of the rest
     * only what is sound. Null when no table is named.
     *
     * @return array{table: string, where?: list<array{column: string, is: string}>, sort?: array{column: string, desc?: true}, limit?: int, gap?: float, across?: true}|null
     */
    public static function repeat(mixed $repeat): ?array
    {
        if (! is_array($repeat) || self::named($repeat['table'] ?? null) === null) {
            return null;
        }

        $where = [];

        foreach (is_array($repeat['where'] ?? null) ? $repeat['where'] : [] as $each) {
            if (is_array($each) && self::named($each['column'] ?? null) !== null && is_string($each['is'] ?? null) && count($where) < self::MOST_WHERE) {
                $where[] = ['column' => self::named($each['column']), 'is' => $each['is']];
            }
        }

        $sort = is_array($repeat['sort'] ?? null) && self::named($repeat['sort']['column'] ?? null) !== null
            ? ['column' => self::named($repeat['sort']['column']), ...(($repeat['sort']['desc'] ?? null) === true ? ['desc' => true] : [])]
            : null;

        return [
            'table' => self::named($repeat['table']),
            ...($where !== [] ? ['where' => $where] : []),
            ...($sort !== null ? ['sort' => $sort] : []),
            ...(is_numeric($repeat['limit'] ?? null) ? ['limit' => (int) round(min(self::ROWS[1], max(self::ROWS[0], (float) $repeat['limit'])))] : []),
            ...(is_numeric($repeat['gap'] ?? null) ? ['gap' => min(self::MOST_GAP, max(0.0, (float) $repeat['gap']))] : []),
            ...(($repeat['across'] ?? null) === true ? ['across' => true] : []),
        ];
    }

    /**
     * Every table a prototype uses, by ref_id, each once: those its row
     * cards repeat and those its actions save to or delete from -- what Play
     * needs sent to it.
     *
     * @param  list<mixed>  $items
     * @return list<string>
     */
    public static function tables(array $items): array
    {
        $tables = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $repeat = self::repeat($item['repeat'] ?? null);

            if ($repeat !== null) {
                $tables[] = $repeat['table'];
            }

            $actions = [
                self::clean($item['link'] ?? null),
                ...array_values(self::when($item['when'] ?? null)),
                self::screen($item['screen'] ?? null)['after']['do'] ?? null,
            ];

            foreach (array_filter($actions) as $action) {
                array_push($tables, ...self::tablesActed($action));
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * The tables one action saves to or deletes from, through if and all.
     *
     * @param  array<string, mixed>  $action
     * @return list<string>
     */
    private static function tablesActed(array $action): array
    {
        return match (true) {
            isset($action['save']) => [(string) $action['save']],
            isset($action['remove']) => [(string) $action['remove']],
            isset($action['if']) => [...self::tablesActed($action['then']), ...(isset($action['else']) ? self::tablesActed($action['else']) : [])],
            isset($action['all']) => array_merge(...array_map(fn (array $step) => self::tablesActed($step), $action['all'])),
            default => [],
        };
    }

    /**
     * An action with each screen and item it names put through `$rename`
     * ($name, 'screen'|'item'): another id, or null to leave it out. A step
     * left with nothing to do goes, and an `if` whose `then` goes with it.
     *
     * @param  array<string, mixed>  $action
     * @param  callable(string, string): ?string  $rename
     * @return array<string, mixed>|null
     */
    public static function renamed(array $action, callable $rename): ?array
    {
        if (isset($action['to'])) {
            $to = $rename((string) $action['to'], 'screen');

            return $to === null ? null : [...$action, 'to' => $to];
        }

        foreach (['toggle', 'show', 'hide'] as $kind) {
            if (isset($action[$kind])) {
                $kept = array_values(array_filter(array_map(fn ($id) => $rename((string) $id, 'item'), $action[$kind])));

                return $kept === [] ? null : [$kind => $kept];
            }
        }

        if (isset($action['if'])) {
            $then = self::renamed($action['then'], $rename);
            $else = isset($action['else']) ? self::renamed($action['else'], $rename) : null;
            unset($action['else']);

            return $then === null ? null : [...$action, 'then' => $then, ...($else !== null ? ['else' => $else] : [])];
        }

        if (isset($action['all'])) {
            $steps = array_values(array_filter(array_map(fn (array $step) => self::renamed($step, $rename), $action['all'])));

            return $steps === [] ? null : ['all' => $steps];
        }

        return $action;
    }

    /** A name: words, trimmed; nothing when there are none. */
    private static function named(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Ids named once each, as many as a step may name; null for none.
     *
     * @return list<string>|null
     */
    private static function ids(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $kept = array_values(array_unique(array_filter(array_map(fn ($id) => self::named($id), $value))));

        return $kept === [] ? null : array_slice($kept, 0, self::MOST);
    }
}
