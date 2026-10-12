<?php

namespace App\Support\Guide;

use App\Support\Markdown\TiptapMarkdown;
use Illuminate\Support\Facades\File;

/**
 * The guide to the app, read inside it: one Markdown file a topic in
 * resources/guide, written with the feature it explains and shipped with it.
 *
 * A file is named "<order>-<slug>.md" -- the order is only where it comes in
 * the list -- and opens with its title as a "# " line. The rest is drawn by
 * the note editor, so anything a note can hold over MCP a topic can too.
 *
 * @phpstan-type Topic array{slug: string, title: string}
 */
class Guide
{
    /**
     * Every topic, in the order of its file name.
     *
     * @return list<Topic>
     */
    public static function topics(): array
    {
        return array_map(
            fn (string $path) => ['slug' => self::slug($path), 'title' => self::read($path)[0]],
            self::files(),
        );
    }

    /**
     * One topic with its body as a note document, or null when there is none by that name.
     *
     * @return array{slug: string, title: string, doc: array<string, mixed>}|null
     */
    public static function topic(string $slug): ?array
    {
        foreach (self::files() as $path) {
            if (self::slug($path) === $slug) {
                [$title, $body] = self::read($path);

                return ['slug' => $slug, 'title' => $title, 'doc' => TiptapMarkdown::toDoc($body)];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $files = File::glob(resource_path('guide/*.md'));
        sort($files);

        return $files;
    }

    private static function slug(string $path): string
    {
        return (string) preg_replace('/^\d+-/', '', basename($path, '.md'));
    }

    /**
     * A file's title and what follows it.
     *
     * @return array{string, string}
     */
    private static function read(string $path): array
    {
        $text = ltrim(File::get($path));

        if (preg_match('/^# +(.+)\R?/', $text, $found)) {
            return [trim($found[1]), ltrim(substr($text, strlen($found[0])))];
        }

        return [self::slug($path), $text];
    }
}
