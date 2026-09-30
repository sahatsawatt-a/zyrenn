<?php

namespace App\Support\Live;

use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The collaboration server (collab/server.mjs) from Laravel's side: which
 * note or board a shared document is, what it holds, and telling an open one
 * that it changed elsewhere.
 */
final class Collab
{
    /**
     * Whether notes and boards are edited live. Without the server, their
     * pages save as they always did.
     */
    public static function enabled(): bool
    {
        return filled(config('services.collab.url')) && filled(config('services.collab.secret'));
    }

    /**
     * The shared document's name, e.g. "notes.k3x9m2p7qa".
     */
    public static function documentName(Note|Board $thing): string
    {
        return ($thing instanceof Note ? 'notes' : 'boards').'.'.$thing->ref_id;
    }

    /**
     * The note or board a document name is for, if there is one.
     */
    public static function find(string $document): Note|Board|null
    {
        if (! preg_match('/^(notes|boards)\.([a-z0-9]{1,16})$/', $document, $name)) {
            return null;
        }

        $model = $name[1] === 'notes' ? Note::class : Board::class;

        return $model::query()->where('ref_id', $name[2])->first();
    }

    /**
     * What a document holds besides its shared state, as the server seeds it.
     *
     * @return array<string, mixed>
     */
    public static function values(Note|Board $thing): array
    {
        return $thing instanceof Note
            ? ['content' => $thing->content, 'title' => $thing->title, 'is_wide' => $thing->is_wide]
            : ['items' => $thing->content['items'] ?? [], 'title' => $thing->title];
    }

    /**
     * What a save just changed that the people editing it share, in the form
     * values() gives it -- for replace().
     *
     * @return array<string, mixed>
     */
    public static function changes(Note|Board $thing): array
    {
        $values = self::values($thing);
        $changed = [];

        foreach ($thing->getChanges() as $column => $value) {
            // A board's items live in its content
            $key = $thing instanceof Board && $column === 'content' ? 'items' : $column;

            if (array_key_exists($key, $values)) {
                $changed[$key] = $values[$key];
            }
        }

        return $changed;
    }

    /**
     * Tells the server a document changed elsewhere, so everyone who has it
     * open sees the change. Nothing is lost when this fails: the shared state
     * was dropped with the change, so the next open starts from what is saved.
     *
     * @param  array<string, mixed>  $changed  values as values() gives them
     */
    public static function replace(Note|Board $thing, array $changed): void
    {
        if ($changed !== []) {
            self::ask('replace', $thing, $changed);
        }
    }

    /**
     * Has the server hand an open document to the app now, so what is read
     * next is what everyone has typed, not what was kept a moment ago.
     */
    public static function flush(Note|Board $thing): void
    {
        self::ask('flush', $thing);
    }

    /**
     * Makes changes by block or item in the live copy, when there is one:
     * only what they name changes, so whoever is typing elsewhere in it carries
     * on. The server hands the result to the app before it answers.
     *
     * Null when nobody has it open (or the server can't be reached): the
     * changes are then the app's to save itself.
     *
     * @param  list<array<string, mixed>>  $edits
     * @param  User|null  $by  who made them, kept as its last editor
     * @return array{missing: list<string>}|null
     */
    public static function apply(Note|Board $thing, array $edits, ?User $by = null): ?array
    {
        $answer = self::ask('apply', $thing, ['edits' => $edits, 'by' => $by?->id]);

        return ($answer['live'] ?? false) ? ['missing' => array_values($answer['missing'] ?? [])] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function ask(string $path, Note|Board $thing, array $payload = []): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        try {
            return Http::timeout(5)
                ->withHeaders(['X-Collab-Secret' => config('services.collab.secret')])
                ->post(rtrim(config('services.collab.url'), '/').'/'.$path, [
                    'document' => self::documentName($thing),
                    ...$payload,
                ])
                ->throw()
                ->json();
        } catch (Throwable $problem) {
            report($problem);

            return null;
        }
    }
}
