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
 * A topic can go deeper in parts of its own: the files in a folder named as
 * the topic is ("02-notes/03-math.md" beside "02-notes.md"), read at
 * "notes/math".
 *
 * @phpstan-type Part array{slug: string, title: string}
 * @phpstan-type Topic array{slug: string, title: string, parts: list<Part>}
 */
class Guide
{
    /**
     * Every topic, in the order of its file name, with its parts.
     *
     * @return list<Topic>
     */
    public static function topics(): array
    {
        return array_map(
            fn (string $path) => [
                'slug' => self::slug($path),
                'title' => self::read($path)[0],
                'parts' => array_map(
                    fn (string $part) => ['slug' => self::slug($path).'/'.self::slug($part), 'title' => self::read($part)[0]],
                    self::files(self::folder($path)),
                ),
            ],
            self::files(resource_path('guide')),
        );
    }

    /**
     * One topic or part with its body as a note document, or null when there
     * is none by that name. A part says which topic it is in.
     *
     * @return array{slug: string, title: string, doc: array<string, mixed>, in: array{slug: string, title: string}|null}|null
     */
    public static function topic(string $slug): ?array
    {
        [$name, $part] = array_pad(explode('/', $slug, 2), 2, null);
        $topic = self::find(resource_path('guide'), $name);

        if ($topic === null) {
            return null;
        }

        $path = $part === null ? $topic : self::find(self::folder($topic), $part);

        if ($path === null) {
            return null;
        }

        [$title, $body] = self::read($path);

        return [
            'slug' => $slug,
            'title' => $title,
            'doc' => TiptapMarkdown::toDoc($body),
            'in' => $part === null ? null : ['slug' => $name, 'title' => self::read($topic)[0]],
        ];
    }

    private static function find(string $folder, string $slug): ?string
    {
        foreach (self::files($folder) as $path) {
            if (self::slug($path) === $slug) {
                return $path;
            }
        }

        return null;
    }

    /** Where a topic's parts are kept: the folder named as its file is. */
    private static function folder(string $path): string
    {
        return substr($path, 0, -strlen('.md'));
    }

    /**
     * @return list<string>
     */
    private static function files(string $folder): array
    {
        $files = File::glob($folder.'/*.md');
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
