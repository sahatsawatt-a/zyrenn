<?php

namespace App\Support\Formula;

use App\Events\TableChanged;
use App\Events\ValuesChanged;
use App\Models\Map\Trip;
use App\Models\Note\Note;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Live\Live;

/**
 * Tables whose formulas, and notes whose live values, read a trip or a table
 * -- trip("Shanghai"), table("Budget") -- are worked out again when what they
 * read changes: whoever has one open is told to load it again.
 *
 * Kept out of the Trip and Table models: a save that changes a trip's revision,
 * or a table's rows (each touches the table), is heard here.
 */
final class Dependents
{
    public static function listen(): void
    {
        Trip::saved(function (Trip $trip) {
            if ($trip->wasChanged('revision')) {
                self::changed($trip->owner(), 'trip', $trip->ref_id, $trip->title);
            }
        });
        Trip::deleted(fn (Trip $trip) => self::changed($trip->owner(), 'trip', $trip->ref_id, $trip->title));

        Table::saved(fn (Table $table) => self::changed($table->owner(), 'table', $table->ref_id, $table->title, $table->id));
        Table::deleted(fn (Table $table) => self::changed($table->owner(), 'table', $table->ref_id, $table->title, $table->id));
    }

    /**
     * Tells every table of the owner with a formula, and every note with a
     * live value, that names this thing by ref_id or title.
     */
    public static function changed(Owner $owner, string $kind, string $refId, string $title, ?int $except = null): void
    {
        $names = array_values(array_filter([mb_strtolower($refId), mb_strtolower(trim($title))], fn (string $name) => $name !== ''));
        $reads = fn (string $formula) => self::reads($formula, $kind, $names);

        $tables = $owner->tables()->getQuery()->when($except, fn ($query) => $query->whereKeyNot($except))->pluck('id');

        $reading = $tables->isEmpty() ? collect() : TableColumn::query()
            ->whereIn('table_id', $tables)
            ->where('type', 'formula')
            ->get()
            ->filter(fn (TableColumn $column) => $reads((string) ($column->options_meta['expression'] ?? '')))
            ->pluck('table_id')
            ->unique();

        foreach (Table::query()->whereKey($reading)->get() as $table) {
            Live::tell(new TableChanged($table, 'reload'));
        }

        // A note's values are in its Markdown copy as {{ … }}
        $notes = $owner->notes()->getQuery()
            ->where('plain_text', 'like', '%{{%')
            ->get(['id', 'ref_id', 'plain_text'])
            ->filter(fn (Note $note) => preg_match_all('/\{\{([^{}\n]*)\}\}/', (string) $note->plain_text, $values) > 0
                && $reads(implode("\n", $values[1])));

        foreach ($notes as $note) {
            Live::tell(new ValuesChanged($note));
        }
    }

    /**
     * Whether a formula, or text with formulas in it, names the thing.
     *
     * @param  list<string>  $names  lower-cased
     */
    private static function reads(string $formula, string $kind, array $names): bool
    {
        $formula = mb_strtolower($formula);

        if (! str_contains($formula, $kind.'(')) {
            return false;
        }

        foreach ($names as $name) {
            if (str_contains($formula, '"'.$name.'"') || str_contains($formula, "'".$name."'")) {
                return true;
            }
        }

        return false;
    }
}
