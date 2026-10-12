<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\References;
use App\Support\Workflow\Template;

/**
 * What the steps that search the web share, whichever service they ask:
 * the key they ask with, what they are asked for, and what was found set
 * out for a model to read.
 */
final class SearchSteps
{
    /**
     * The most a step gives out of pages' whole text, as it is kept. A step
     * gives a page's text both on its own and in the words of them all, and
     * the two together have to stay under Engine::MAX_OUTPUT_BYTES (256 KB).
     */
    public const MAX_TEXT_BYTES = 100 * 1024;

    /**
     * The step's key: one of the owner's connections to the service.
     *
     * @throws StepFailed
     */
    public static function key(StepContext $step, string $service): string
    {
        $connection = References::connection($step->owner(), (string) $step->setting('connection'), [$service])
            ?? throw new StepFailed('Its connection is gone, or is not this workflow\'s.');
        $key = (string) $connection->secret('api_key');

        return $key !== '' ? $key : throw new StepFailed("\"{$connection->name}\" has no key: give it one on the connection.");
    }

    /**
     * A setting that has to come to something.
     *
     * @throws StepFailed
     */
    public static function needs(StepContext $step, string $setting, string $what): string
    {
        $value = trim($step->text($setting));

        return $value !== '' ? $value : throw new StepFailed("There is {$what}.");
    }

    /**
     * How many of something a step asks for, within what the service gives.
     */
    public static function count(StepContext $step, string $setting, int $usual, int $most): int
    {
        return max(1, min($most, (int) ($step->setting($setting) ?: $usual)));
    }

    /**
     * Several things a setting names: a list of them, or written out one
     * on a line -- or, where none of them can hold one, with commas between.
     *
     * @return list<string>
     */
    public static function listed(StepContext $step, string $setting, bool $commas = true): array
    {
        $named = $step->template($setting);
        $named = is_array($named) ? $named : (preg_split($commas ? '/[\r\n,]+/' : '/\R+/', Template::text($named)) ?: []);

        return array_values(array_filter(array_map(fn ($one) => trim(Template::text($one)), $named), fn (string $one) => $one !== ''));
    }

    /**
     * What was found, each as the parts of it a step gives out.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $as
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $found, callable $as, ?int $most = null): array
    {
        $rows = array_values(array_filter(is_array($found) ? $found : [], is_array(...)));

        return array_map($as, $most === null ? $rows : array_slice($rows, 0, $most));
    }

    /**
     * What was found as words for a model to read: each numbered, with its
     * title, where it is, and what it says.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function words(array $rows, string $where, string $says): string
    {
        $words = [];

        foreach ($rows as $at => $row) {
            $words[] = implode("\n", array_filter([
                ($at + 1).'. '.Template::text($row['title'] ?? ''),
                Template::text($row[$where] ?? ''),
                Template::text($row[$says] ?? ''),
            ], fn (string $line) => trim($line) !== ''));
        }

        return implode("\n\n", $words);
    }

    /**
     * Pages' whole text, each cut short where they are together more than
     * a step may give out; and whether any was.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array{list<array<string, mixed>>, bool}
     */
    public static function fit(array $pages): array
    {
        // As it is kept, where a letter outside ASCII takes more than itself
        $size = fn (mixed $text) => is_string($text) ? strlen((string) json_encode($text)) : 0;
        $sizes = array_map(fn (array $page) => $size($page['text'] ?? null), $pages);

        if (array_sum($sizes) <= self::MAX_TEXT_BYTES) {
            return [$pages, false];
        }

        $each = intdiv(self::MAX_TEXT_BYTES, count($pages));

        foreach ($pages as $at => $page) {
            if ($sizes[$at] > $each) {
                $pages[$at]['text'] = mb_substr($page['text'], 0, (int) floor(mb_strlen($page['text']) * $each / $sizes[$at] * 0.95)).'…';
            }
        }

        return [$pages, true];
    }

    /**
     * Every page's text as one: a single page's as it is, several each
     * under where it is.
     *
     * @param  list<array<string, mixed>>  $pages
     */
    public static function together(array $pages): string
    {
        return count($pages) === 1
            ? Template::text($pages[0]['text'] ?? '')
            : implode("\n\n---\n\n", array_map(fn (array $page) => Template::text($page['url'] ?? '')."\n\n".Template::text($page['text'] ?? ''), $pages));
    }
}
