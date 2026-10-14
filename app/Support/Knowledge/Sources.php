<?php

namespace App\Support\Knowledge;

use App\Models\Drive\DriveFile;
use App\Models\Folder;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Note\Note;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Table\TableStorage;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * What a knowledge base reads, as documents: each note of a folder (and the
 * folders in it) as Markdown, a table's rows as lines of "column: value",
 * and the text files of a Drive folder. Only ever its owner's.
 *
 * @phpstan-type Document array{kind: string, ref: string, title: string, url: string|null, text: string}
 */
final class Sources
{
    /** The most of one file read. */
    private const MAX_FILE_BYTES = 256 * 1024;

    private const TEXT_MIMES = ['application/json', 'application/xml', 'application/x-ndjson', 'application/yaml', 'application/x-yaml'];

    /**
     * @return Generator<int, Document>
     */
    public static function documents(KnowledgeBase $base): Generator
    {
        $owner = $base->owner();

        foreach ($base->sources ?? [] as $source) {
            $ref = $source['ref'];

            yield from match ($source['kind']) {
                'notes' => self::notes($owner, $ref),
                'table' => self::table($owner, $ref),
                'drive' => self::files($owner, $ref),
                default => [],
            };
        }
    }

    /**
     * The ids of a folder at $path and every folder in it; null for the whole of them.
     *
     * @param  HasMany<covariant Folder, covariant Model&Owner>  $folders
     * @return list<int>|null
     */
    private static function under(HasMany $folders, string $path): ?array
    {
        $path = trim($path, '/ ');

        if ($path === '') {
            return null;
        }

        $ids = [];

        foreach (Folder::pathsById($folders->get(['id', 'parent_id', 'name']), '/') as $id => $named) {
            if (strcasecmp($named, $path) === 0 || stripos($named, "{$path}/") === 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return Generator<int, Document>
     */
    private static function notes(Owner $owner, string $path): Generator
    {
        $folders = self::under($owner->noteFolders(), $path);

        $notes = $owner->notes()
            ->when($folders !== null, fn ($query) => $query->whereIn('folder_id', $folders))
            ->orderBy('id')
            ->lazy();

        foreach ($notes as $note) {
            /** @var Note $note */
            yield [
                'kind' => 'note',
                'ref' => $note->ref_id,
                'title' => $note->title ?: 'Untitled note',
                'url' => route('notes.show', $note),
                'text' => TiptapMarkdown::toMarkdown($note->content),
            ];
        }
    }

    /**
     * @return Generator<int, Document>
     */
    private static function table(Owner $owner, string $ref): Generator
    {
        $table = $owner->tables()->with('columns')->where('ref_id', $ref)->first();

        if (! $table instanceof Table) {
            return;
        }

        $labels = $table->columns->mapWithKeys(fn (TableColumn $column) => [$column->name => $column->label])->all();
        $lines = [];

        foreach (TableStorage::rows($table) as $row) {
            $parts = [];

            foreach ($labels as $name => $label) {
                $value = $row[$name] ?? null;
                $value = is_array($value) ? implode(', ', array_map(fn ($part) => is_scalar($part) ? (string) $part : json_encode($part), $value)) : (string) $value;

                if (trim($value) !== '') {
                    $parts[] = "{$label}: ".trim($value);
                }
            }

            if ($parts !== []) {
                $lines[] = implode('; ', $parts);
            }
        }

        yield [
            'kind' => 'table',
            'ref' => $table->ref_id,
            'title' => $table->title ?: 'Untitled table',
            'url' => route('tables.show', $table),
            // A row a line, so a passage never cuts one in half
            'text' => implode("\n\n", $lines),
        ];
    }

    /**
     * @return Generator<int, Document>
     */
    private static function files(Owner $owner, string $path): Generator
    {
        $folders = self::under($owner->driveFolders(), $path);

        $files = $owner->driveFiles()
            ->when($folders !== null, fn ($query) => $query->whereIn('folder_id', $folders))
            ->where(fn ($query) => $query->where('mime', 'like', 'text/%')->orWhereIn('mime', self::TEXT_MIMES))
            ->orderBy('id')
            ->lazy();

        foreach ($files as $file) {
            /** @var DriveFile $file */
            $read = Storage::disk(DriveFile::DISK)->exists($file->path)
                ? mb_strcut((string) Storage::disk(DriveFile::DISK)->get($file->path), 0, self::MAX_FILE_BYTES, 'UTF-8')
                : '';

            if ($read === '' || ! mb_check_encoding($read, 'UTF-8')) {
                continue;
            }

            yield [
                'kind' => 'file',
                'ref' => $file->ref_id,
                'title' => $file->name,
                'url' => $file->url(),
                'text' => $read,
            ];
        }
    }
}
