<?php

namespace App\Support\Live;

use App\Models\Board\Board;
use App\Models\Note\Note;
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
        if (! self::enabled() || $changed === []) {
            return;
        }

        try {
            Http::timeout(3)
                ->withHeaders(['X-Collab-Secret' => config('services.collab.secret')])
                ->post(rtrim(config('services.collab.url'), '/').'/replace', [
                    'document' => self::documentName($thing),
                    ...$changed,
                ])
                ->throw();
        } catch (Throwable $problem) {
            report($problem);
        }
    }
}
