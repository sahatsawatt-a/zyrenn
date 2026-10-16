<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\Template;
use Illuminate\Support\Str;

/**
 * Text changed one way: trimmed, put in upper or lower case or as a title,
 * made a slug, something in it replaced (words, or a pattern), split into a
 * list, a list joined, cut short, or its HTML taken out.
 */
final class TextStep implements Step
{
    public function run(StepContext $step): StepResult
    {
        $given = $step->template('value');
        $do = (string) ($step->setting('do') ?: 'trim');
        $by = (string) ($step->setting('by') ?? '');

        if ($do === 'join') {
            if (! is_array($given)) {
                throw new StepFailed('To join, "Text" should come to a list, e.g. {{ node("feed").items }}.');
            }

            $value = implode(self::unescape($by) ?: ', ', array_map(fn (mixed $one) => Template::text($one), $given));

            return new StepResult(['value' => $value]);
        }

        $text = Template::text($given);

        $value = match ($do) {
            'upper' => mb_strtoupper($text),
            'lower' => mb_strtolower($text),
            'title' => Str::title($text),
            'slug' => Str::slug($text, language: null) ?: Str::slug(Str::ascii($text)),
            'replace' => self::replace($step, $text),
            'split' => array_values(array_filter(array_map('trim', explode(self::unescape($by) ?: ',', $text)), fn (string $one) => $one !== '')),
            'cut' => self::cut($text, max(1, (int) ($step->setting('length') ?: 100))),
            'strip_html' => trim((string) preg_replace('/[ \t]+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(br|/p|/div|/li|/h\d)\b[^>]*>#i', "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            default => trim((string) preg_replace('/\s+/u', ' ', $text)),
        };

        return new StepResult(['value' => $value, ...is_array($value) ? ['count' => count($value)] : []]);
    }

    private static function replace(StepContext $step, string $text): string
    {
        $find = $step->text('find');
        $with = $step->text('with');

        if ($find === '') {
            throw new StepFailed('Say what to find.');
        }

        if ($step->setting('pattern') !== 'yes') {
            return str_replace($find, $with, $text);
        }

        $pattern = '/'.str_replace('/', '\/', $find).'/u';
        $replaced = @preg_replace($pattern, $with, $text);

        if ($replaced === null) {
            throw new StepFailed("\"{$find}\" is not a pattern that can be read.");
        }

        return $replaced;
    }

    /** At most `$length` letters, the "…" among them, with no space left before it. */
    private static function cut(string $text, int $length): string
    {
        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)).'…';
    }

    /** "\n" and "\t" as a new line and a tab: what a separator is written as. */
    private static function unescape(string $by): string
    {
        return str_replace(['\n', '\t'], ["\n", "\t"], $by);
    }
}
