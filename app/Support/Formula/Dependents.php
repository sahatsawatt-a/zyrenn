<?php

namespace App\Support\Formula;

use App\Events\TableChanged;
use App\Models\Map\Trip;
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Live\Live;

/**
 * Tables whose formulas read a trip or another table -- trip("Shanghai"),
 * table("Budget") -- are worked out again when what they read changes: whoever
 * has one open is told to load it again, as when its own columns change.
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
     * Tells every table of the owner with a formula that names this thing,
     * by ref_id or title, to load again.
     */
    public static function changed(Owner $owner, string $kind, string $refId, string $title, ?int $except = null): void
    {
        $tables = $owner->tables()->getQuery()->when($except, fn ($query) => $query->whereKeyNot($except))->pluck('id');

        if ($tables->isEmpty()) {
            return;
        }

        $names = array_filter([mb_strtolower($refId), mb_strtolower(trim($title))]);

        $reading = TableColumn::query()
            ->whereIn('table_id', $tables)
            ->where('type', 'formula')
            ->get()
            ->filter(function (TableColumn $column) use ($kind, $names) {
                $expression = mb_strtolower((string) ($column->options_meta['expression'] ?? ''));

                if (! str_contains($expression, $kind.'(')) {
                    return false;
                }

                foreach ($names as $name) {
                    if (str_contains($expression, '"'.$name.'"') || str_contains($expression, "'".$name."'")) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('table_id')
            ->unique();

        foreach (Table::query()->whereKey($reading)->get() as $table) {
            Live::tell(new TableChanged($table, 'reload'));
        }
    }
}
