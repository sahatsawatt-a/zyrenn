<?php

namespace App\Support\Board;

/**
 * A slide's animations: the list on a frame of what each item on it does as
 * the show goes on -- comes in, draws the eye, goes out -- and how the show
 * moves to the slide. The effects and limits are shared with the canvas,
 * in resources/js/features/boards/lib/animations.json, which plays them
 * (deck/build.ts).
 */
class Animations
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $shared = null;

    /**
     * @return array<string, mixed>
     */
    public static function shared(): array
    {
        return self::$shared ??= json_decode(
            (string) file_get_contents(resource_path('js/features/boards/lib/animations.json')),
            true,
        );
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_column(self::shared()['kinds'], 'id');
    }

    /** @return list<string> */
    public static function effects(string $kind): array
    {
        return array_column(self::shared()['effects'][$kind] ?? [], 'id');
    }

    /** @return list<string> every effect of any kind */
    public static function allEffects(): array
    {
        return array_values(array_unique(array_merge(...array_map(self::effects(...), self::kinds()))));
    }

    /** @return list<string> */
    public static function starts(): array
    {
        return array_column(self::shared()['starts'], 'id');
    }

    /** @return list<string> */
    public static function froms(): array
    {
        return self::shared()['froms'];
    }

    /** @return list<string> */
    public static function transitions(): array
    {
        return array_column(self::shared()['transitions'], 'id');
    }

    public static function most(): int
    {
        return (int) self::shared()['most'];
    }

    /** Whether an effect of a kind comes from (or goes to) a side. */
    public static function hasFrom(string $kind, string $effect): bool
    {
        foreach (self::shared()['effects'][$kind] ?? [] as $one) {
            if ($one['id'] === $effect) {
                return (bool) ($one['from'] ?? false);
            }
        }

        return false;
    }

    /**
     * A slide's steps as they are kept: each of an item there is, with an
     * effect its kind has, a start, a duration and delay within the limits,
     * and a side for an effect that has one. A step that is none of these is
     * left out rather than refused, as a canvas would never have made it.
     *
     * @param  array<mixed>  $steps
     * @param  array<string, true>|null  $ids  the items on the board, when known
     * @return list<array{item: string, kind: string, effect: string, start: string, duration: int, delay: int, from?: string}>
     */
    public static function clean(array $steps, ?array $ids = null): array
    {
        $shared = self::shared();
        $kept = [];

        foreach (array_slice(array_values(array_filter($steps, 'is_array')), 0, self::most()) as $step) {
            $item = is_string($step['item'] ?? null) ? $step['item'] : '';
            $kind = in_array($step['kind'] ?? null, self::kinds(), true) ? $step['kind'] : null;

            if ($item === '' || $kind === null || ($ids !== null && ! isset($ids[$item]))) {
                continue;
            }

            $effects = self::effects($kind);
            $effect = in_array($step['effect'] ?? null, $effects, true) ? $step['effect'] : $effects[0];
            $one = [
                'item' => $item,
                'kind' => $kind,
                'effect' => $effect,
                'start' => in_array($step['start'] ?? null, self::starts(), true) ? $step['start'] : 'click',
                'duration' => (int) min($shared['duration']['max'], max($shared['duration']['min'], is_numeric($step['duration'] ?? null) ? (int) $step['duration'] : $shared['duration']['default'])),
                'delay' => (int) min($shared['delay']['max'], max(0, is_numeric($step['delay'] ?? null) ? (int) $step['delay'] : 0)),
            ];

            if (self::hasFrom($kind, $effect)) {
                $one['from'] = in_array($step['from'] ?? null, self::froms(), true) ? $step['from'] : 'bottom';
            }

            $kept[] = $one;
        }

        return $kept;
    }
}
